<?php // app/Features/Queue/QueueController.php
namespace App\Features\Queue;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Adminness;
use App\Repositories\AuthoringRepository;

final class QueueController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app, private \App\MailGuard $mailer,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        $rows = (new AuthoringRepository($this->db))->queueRows((int) $this->session->get('user_id'));
        $moderator = null;
        foreach ($rows as $r) {
            if ($r['k'] === '0gate') { $moderator = $r; break; }
        }
        if ($moderator === null) return new Response(\App\Lang::t('error.forbidden'), 403);
        return $this->view->render('queue/index', [
            'title' => \App\Lang::t('queue.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('queue.heading'))->withCanonical('/queue')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $rows,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            // the gate row already carries the viewer's role (c): the layout's
            // operator block costs no extra query here (the Task 4 ruling)
            'isAdmin' => ($moderator['c'] ?? '') === 'admin',
        ]);
    }

    #[AuthAttr] #[Post]
    public function story(string $id, string $op): Response
    {
        if (!$this->moderator()) return new Response(\App\Lang::t('error.forbidden'), 403);
        if ($op !== 'approve' && $op !== 'remove') return new Response(\App\Lang::t('error.not_found'), 404);
        $repo = new AuthoringRepository($this->db);
        $info = $this->db->one(
            'SELECT s.title, u.email, u.penname FROM stories s JOIN users u ON u.id = s.author_id
             WHERE s.id = ? AND s.validated = 0 AND s.deleted_at IS NULL', [(int) $id]);
        $coords = $op === 'approve' ? $repo->approveStory((int) $id) : $repo->removeStory((int) $id);
        if ($coords !== null) {
            $this->purge($coords[0], $coords[1], $coords[2], $coords[3], $coords[4]);
            (\App\StaticCache\Cache::configured($this->app))->purgeAuthors(); // story counts changed
            if ($op === 'approve') $this->notifyPublish($coords[0]);
            \App\ActivityLog::by($this->db, $this->session, 'queue.story_' . $op, (string) ($info['title'] ?? $coords[0]));
            if ($info !== null) {
                $base = rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/');
                $this->mailApplicant($op === 'approve' ? 'story_accepted' : 'story_rejected', (string) $info['email'], [
                    '{title}' => (string) $info['title'],
                    '{url}' => $base . '/story/view/' . $coords[0],
                    '{reason}' => $this->reason('queue.default_story_reason'),
                ]);
            }
        }
        return Response::redirect('/queue');
    }

    #[AuthAttr] #[Post]
    public function chapter(string $id, string $op): Response
    {
        if (!$this->moderator()) return new Response(\App\Lang::t('error.forbidden'), 403);
        if ($op !== 'approve' && $op !== 'remove') return new Response(\App\Lang::t('error.not_found'), 404);
        $repo = new AuthoringRepository($this->db);
        $coords = $op === 'approve' ? $repo->approveChapter((int) $id) : $repo->removeChapter((int) $id);
        if ($coords !== null) {
            $this->purge($coords[0], $coords[1], $coords[2], $coords[3], $coords[4]);
            if ($op === 'approve') $this->notifyPublish($coords[0]);
            \App\ActivityLog::by($this->db, $this->session, 'queue.chapter_' . $op, 'chapter ' . (int) $id . ' of ' . $coords[0]);
        }
        return Response::redirect('/queue');
    }

    #[AuthAttr] #[Post]
    public function member(string $id, string $op): Response
    {
        if (!$this->moderator()) return new Response(\App\Lang::t('error.forbidden'), 403);
        if ($op === 'approve') {
            $done = $this->db->query('UPDATE users SET approved_at = ? WHERE id = ? AND approved_at IS NULL', [date('c'), (int) $id])->rowCount();
            if ($done === 1) \App\ActivityLog::by($this->db, $this->session, 'queue.member_approve', 'member ' . (int) $id);
        } elseif ($op === 'reject') {
            $who = $this->db->one('SELECT email, penname FROM users WHERE id = ? AND approved_at IS NULL AND is_locked = 0 AND role = \'member\'', [(int) $id]);
            $locked = $this->db->query("UPDATE users SET is_locked = 1 WHERE id = ? AND approved_at IS NULL AND role = 'member'", [(int) $id])->rowCount();
            if ($locked === 1) \App\ActivityLog::by($this->db, $this->session, 'queue.member_reject', 'member ' . (int) $id);
            if ($who !== null && $locked === 1) {
                $this->mailApplicant('member_rejected', (string) $who['email'], [
                    '{penname}' => (string) ($who['penname'] ?? ''),
                    '{reason}' => $this->reason('queue.default_member_reason'),
                ]);
            }
        }
        // Both ops change directory membership: approve completes an
        // approval-mode member, reject locks them out of the listing.
        (\App\StaticCache\Cache::configured($this->app))->purgeAuthors();
        return Response::redirect('/queue');
    }

    /** The moderator's typed reason, or a polite default when left empty. */
    private function reason(string $defaultKey): string
    {
        $reason = trim($this->request->postStr('reason'));
        return $reason !== '' ? $reason : \App\Lang::t($defaultKey);
    }

    /** Send a decision mail through the editable template. A failed send
     *  never undoes the decision. */
    private function mailApplicant(string $template, string $to, array $pairs): void
    {
        if ($to === '' || \App\AccountSecurity::isReservedEmail($to)) return;
        [$subject, $body] = \App\Templates::get($this->db, $template, ...\App\Templates::pair($template));
        try {
            $this->mailer->send($to, strtr($subject, $pairs), strtr($body, $pairs));
        } catch (\Throwable $e) {
            error_log("{$template} mail failed: " . $e->getMessage());
        }
    }

    private function moderator(): bool
    {
        return !(Adminness::requireModerator($this->db, $this->session) instanceof Response);
    }

    private function purge(string $slug, array $cats, array $seriesSlugs = [], string $authorSlug = '', array $challengeSlugs = []): void
    {
        // The list rider (finding 4): approve flips validated (a story joins
        // every public list's guest render), remove soft-deletes (it leaves).
        // The challenge rider (F1) rides the same flips: item chapter counts
        // and story visibility shift. Caller-side lookup: Cache stays DB-free.
        $storyId = (int) ($this->db->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'] ?? 0);
        $listSlugs = (new \App\Repositories\ListsRepository($this->db))->publicListSlugsForStory($storyId);
        (\App\StaticCache\Cache::configured($this->app))
            ->purgeStory($slug, $cats, $seriesSlugs, $authorSlug, $listSlugs, $challengeSlugs);
    }

    private function notifyPublish(string $slug): void
    {
        (new \App\PublishFanout($this->db, $this->mailer,
            rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/')))->publish($slug);
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }
}
