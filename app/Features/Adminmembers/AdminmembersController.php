<?php // app/Features/Adminmembers/AdminmembersController.php
namespace App\Features\Adminmembers;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Adminness;

/** Admin-only member roster with role-select forms. Roles are admin-only by
 *  design (moderators must not mint admins, finding 11), which is exactly why
 *  this surface is gated by Adminness::requireAdmin instead of
 *  Adminness::requireModerator: the moderator tier keeps the queues, never the
 *  role enum. Every write goes
 *  through Adminness::setRole exclusively (the iff invariant); the framework's
 *  generic /admin panel edits the users table raw, which is why operators are
 *  pointed here for roles.
 *
 *  The class name is deliberately Adminmembers (no inner capital): the router
 *  resolves /adminmembers to the studly name AdminmembersController, and the
 *  PSR-4 autoloader maps that LITERALLY to a file name. A camelCase class for
 *  a one-word URL segment only resolves through a case-INSENSITIVE filesystem
 *  (the QA 10a finding: it 404s whole on Linux and pcov cannot attribute its
 *  coverage). AdminToolsTest pins the contract case-exactly for every
 *  controller. */
final class AdminmembersController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        [$perPage, $offset] = $this->paginate();
        $q = trim((string) ($this->request->get['q'] ?? ''));
        // COALESCE keeps penname-less accounts on the unfiltered roster (an
        // operator must see every account); a prefix then excludes them.
        // Wildcards in the prefix are escaped (the SearchRepository idiom).
        $pattern = ($q === '' ? '' : str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q)) . '%';
        $rows = $this->db->all(
            "SELECT id, penname, email, role, is_locked, approved_at, email_verified_at, profile_slug
             FROM users WHERE COALESCE(penname, '') LIKE ? ESCAPE '\\' ORDER BY penname COLLATE NOCASE, id LIMIT ? OFFSET ?",
            [$pattern, $perPage, $offset]);
        return $this->view->render('adminmembers/index', [
            'title' => \App\Lang::t('adminmembers.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('adminmembers.heading'))->withCanonical('/adminmembers')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $rows,
            'hasMore' => count($rows) === $perPage,
            'roles' => Adminness::ROLES,
            'q' => $q,
            'page' => $this->page(),
            'me' => (int) ($this->session->get('user_id') ?? 0),
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true, // the gate above already proved it
        ]);
    }

    /** The ONLY role write path: validate against the enum BEFORE dispatch so
     *  a junk role answers 422, never setRole's InvalidArgumentException 500. */
    #[AuthAttr] #[Post]
    public function role(string $id, string $role): Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        if (!in_array($role, Adminness::ROLES, true)) return new Response('Unknown role.', 422);
        if (preg_match('#^[1-9][0-9]{0,9}$#', $id) !== 1) return new Response('Page not found', 404);
        if ($this->db->one('SELECT id FROM users WHERE id = ?', [(int) $id]) === null) {
            return new Response('Page not found', 404);
        }
        Adminness::setRole($this->db, (int) $id, $role); // the iff invariant lives there
        return Response::redirect('/adminmembers');
    }

    /** Lock a member out: sign-in answers with a plain "locked" message and
     *  the member leaves the public author directory. Never yourself, never
     *  the last admin who can still sign in. */
    #[AuthAttr] #[Post]
    public function lock(string $id): Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $target = $this->target($id);
        if ($target === null) return new Response('Page not found', 404);
        if ((int) $target['id'] === (int) $gate['id']) return new Response(\App\Lang::t('adminmembers.err_self_lock'), 422);
        if ($this->isLastAdmin($target)) return new Response(\App\Lang::t('adminmembers.err_last_admin'), 422);
        // A new random password hash changes the password epoch Kip's session
        // check reads, so every session the member holds ends on its next
        // request. They get back in after an unlock through a password reset
        // or a sign-in link.
        $this->db->query('UPDATE users SET is_locked = 1, password_hash = ? WHERE id = ?',
            [password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), (int) $target['id']]);
        $this->purgeMember($target);
        return Response::redirect('/adminmembers');
    }

    #[AuthAttr] #[Post]
    public function unlock(string $id): Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $target = $this->target($id);
        if ($target === null) return new Response('Page not found', 404);
        if ($this->isDeleted($target)) return new Response(\App\Lang::t('adminmembers.err_deleted'), 422);
        $this->db->query('UPDATE users SET is_locked = 0 WHERE id = ?', [(int) $target['id']]);
        $this->purgeMember($target);
        return Response::redirect('/adminmembers');
    }

    /** The confirm step before a delete: asks what happens to their stories. */
    #[AuthAttr]
    public function remove(string $id): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $target = $this->target($id);
        if ($target === null) return new Response('Page not found', 404);
        return $this->removeForm($target, null);
    }

    /** Delete a member. The account row is scrubbed and locked rather than
     *  dropped: the database would cascade-delete their stories, series and
     *  lists with the row, and a soft-deleted story should stay recoverable.
     *  Email, penname, bio and password are wiped, so the address is free to
     *  register again. Stories either move to another member or are
     *  soft-deleted. */
    #[AuthAttr] #[Post]
    public function destroy(string $id): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $target = $this->target($id);
        if ($target === null) return new Response('Page not found', 404);
        $refuse = fn (string $key): Response => new Response($this->removeForm($target, \App\Lang::t($key)), 422);
        if ((int) $target['id'] === (int) $gate['id']) return $refuse('adminmembers.err_self_delete');
        if ($this->isDeleted($target)) return $refuse('adminmembers.err_deleted');
        if ($this->isLastAdmin($target)) return $refuse('adminmembers.err_last_admin');
        $choice = $this->request->postStr('stories');
        if (!in_array($choice, ['move', 'remove'], true)) return $refuse('adminmembers.err_choose');
        $heir = null;
        if ($choice === 'move') {
            $heir = $this->db->one(
                'SELECT id FROM users WHERE penname = ? COLLATE NOCASE AND approved_at IS NOT NULL AND email_verified_at IS NOT NULL AND is_locked = 0 AND id != ?',
                [trim($this->request->postStr('penname')), (int) $target['id']]);
            if ($heir === null) return $refuse('adminmembers.err_heir');
        }
        $uid = (int) $target['id'];
        $this->db->begin();
        try {
            if ($heir !== null) {
                $heirId = (int) $heir['id'];
                $this->db->query('DELETE FROM coauthors WHERE user_id = ? AND story_id IN (SELECT id FROM stories WHERE author_id = ?)', [$heirId, $uid]);
                $this->db->query('UPDATE stories SET author_id = ? WHERE author_id = ?', [$heirId, $uid]);
                $this->db->query('UPDATE series SET owner_id = ? WHERE owner_id = ?', [$heirId, $uid]);
            } else {
                $this->db->query('UPDATE stories SET deleted_at = ? WHERE author_id = ? AND deleted_at IS NULL', [date('c'), $uid]);
            }
            $this->db->query('DELETE FROM coauthors WHERE user_id = ?', [$uid]);
            $this->db->query(
                "UPDATE users SET email = ?, password_hash = ?, penname = NULL, profile_slug = NULL, bio = '', avatar_path = NULL,
                        support_url = NULL, legacy_md5 = NULL, legacy_bcrypt = NULL, role = 'member', is_admin = 0, is_locked = 1
                 WHERE id = ?",
                ['deleted-' . $uid . '@deleted.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $uid]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        // Stories, series and author pages all changed hands: clear the lot.
        \App\StaticCache\Cache::configured($this->app)->purgeAll();
        return Response::redirect('/adminmembers');
    }

    private function target(string $id): ?array
    {
        if (preg_match('#^[1-9][0-9]{0,9}$#', $id) !== 1) return null;
        return $this->db->one('SELECT id, penname, email, role, is_locked, profile_slug FROM users WHERE id = ?', [(int) $id]);
    }

    private function isDeleted(array $target): bool
    {
        return str_ends_with((string) $target['email'], '@deleted.invalid');
    }

    /** True when the target is an admin who can sign in and no other
     *  sign-in-capable admin exists. */
    private function isLastAdmin(array $target): bool
    {
        if ($target['role'] !== 'admin' || (int) $target['is_locked'] === 1) return false;
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE role = 'admin' AND is_locked = 0 AND id != ?", [(int) $target['id']])['c'] === 0;
    }

    private function purgeMember(array $target): void
    {
        $cache = \App\StaticCache\Cache::configured($this->app);
        if ((string) $target['profile_slug'] !== '') $cache->purgeUser((string) $target['profile_slug']);
        $cache->purgeAuthors();
    }

    private function removeForm(array $target, ?string $error): string
    {
        $title = \App\Lang::t('adminmembers.remove_heading');
        return $this->view->render('adminmembers/remove', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'member' => $target,
            'storyCount' => (int) $this->db->one('SELECT COUNT(*) c FROM stories WHERE author_id = ? AND deleted_at IS NULL', [(int) $target['id']])['c'],
            'error' => $error,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true,
        ]);
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }

    /** Coerced page param: junk, zero and negatives become page 1. */
    private function page(): int
    {
        return max(1, (int) ($this->request->get['page'] ?? 1));
    }

    /** Overflow guard (the BrowseController idiom): a hostile page value must
     *  not push the offset past int range into a repository TypeError. */
    private function paginate(): array
    {
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        $page = min($this->page(), intdiv(PHP_INT_MAX, $perPage));
        return [$perPage, ($page - 1) * $perPage];
    }
}
