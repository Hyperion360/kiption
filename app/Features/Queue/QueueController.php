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
        private Session $session, private App $app, private \Kip\Mailer $mailer,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        $rows = (new AuthoringRepository($this->db))->queueRows((int) $this->session->get('user_id'));
        $moderator = null;
        foreach ($rows as $r) {
            if ($r['k'] === '0gate') { $moderator = $r; break; }
        }
        if ($moderator === null) return new Response('Forbidden', 403);
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
        if (!$this->moderator()) return new Response('Forbidden', 403);
        $repo = new AuthoringRepository($this->db);
        $coords = $op === 'approve' ? $repo->approveStory((int) $id) : $repo->removeStory((int) $id);
        if ($coords !== null) {
            $this->purge($coords[0], $coords[1], $coords[2], $coords[3], $coords[4]);
            (\App\StaticCache\Cache::configured($this->app))->purgeAuthors(); // story counts changed
            if ($op === 'approve') $this->notifyPublish($coords[0]);
        }
        return Response::redirect('/queue');
    }

    #[AuthAttr] #[Post]
    public function chapter(string $id, string $op): Response
    {
        if (!$this->moderator()) return new Response('Forbidden', 403);
        $repo = new AuthoringRepository($this->db);
        $coords = $op === 'approve' ? $repo->approveChapter((int) $id) : $repo->removeChapter((int) $id);
        if ($coords !== null) {
            $this->purge($coords[0], $coords[1], $coords[2], $coords[3], $coords[4]);
            if ($op === 'approve') $this->notifyPublish($coords[0]);
        }
        return Response::redirect('/queue');
    }

    #[AuthAttr] #[Post]
    public function member(string $id, string $op): Response
    {
        if (!$this->moderator()) return new Response('Forbidden', 403);
        if ($op === 'approve') {
            $this->db->query('UPDATE users SET approved_at = COALESCE(approved_at, ?) WHERE id = ?', [date('c'), (int) $id]);
        } elseif ($op === 'reject') {
            $this->db->query('UPDATE users SET is_locked = 1 WHERE id = ?', [(int) $id]);
        }
        // Both ops change directory membership: approve completes an
        // approval-mode member, reject locks them out of the listing.
        (\App\StaticCache\Cache::configured($this->app))->purgeAuthors();
        return Response::redirect('/queue');
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
