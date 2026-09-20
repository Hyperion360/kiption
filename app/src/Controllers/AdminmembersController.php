<?php // app/src/Controllers/AdminmembersController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Adminness;

/** Admin-only member roster with role-select forms. Roles are admin-only by
 *  design (moderators must not mint admins, finding 11), which is exactly why
 *  this surface uses the SQL admin gate instead of Adminness::requireModerator:
 *  the moderator tier keeps the queues, never the role enum. Every write goes
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
        if (!$this->admin()) return new Response('Forbidden', 403);
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
            'title' => 'Members',
            'head' => $this->head()->withTitle('Members')->withCanonical('/adminmembers')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $rows,
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
        if (!$this->admin()) return new Response('Forbidden', 403);
        if (!in_array($role, Adminness::ROLES, true)) return new Response('Unknown role.', 422);
        if (preg_match('#^[1-9][0-9]{0,9}$#', $id) !== 1) return new Response('Page not found', 404);
        if ($this->db->one('SELECT id FROM users WHERE id = ?', [(int) $id]) === null) {
            return new Response('Page not found', 404);
        }
        Adminness::setRole($this->db, (int) $id, $role); // the iff invariant lives there
        return Response::redirect('/adminmembers');
    }

    /** The SQL admin gate (role = 'admin' iff is_admin = 1 makes one check enough). */
    private function admin(): bool
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$me])['c'] === 1;
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
