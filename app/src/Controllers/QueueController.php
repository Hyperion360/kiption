<?php // app/src/Controllers/QueueController.php
namespace App\Controllers;
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
            'title' => 'Validation queue',
            'head' => $this->head()->withTitle('Validation queue')->withCanonical('/queue')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'rows' => $rows,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
        ]);
    }

    #[AuthAttr] #[Post]
    public function story(string $id, string $op): Response
    {
        if (!$this->moderator()) return new Response('Forbidden', 403);
        $repo = new AuthoringRepository($this->db);
        $coords = $op === 'approve' ? $repo->approveStory((int) $id) : $repo->removeStory((int) $id);
        if ($coords !== null) {
            $this->purge($coords[0], $coords[1]);
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
            $this->purge($coords[0], $coords[1]);
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
        return Response::redirect('/queue');
    }

    private function moderator(): bool
    {
        return !(Adminness::requireModerator($this->db, $this->session) instanceof Response);
    }

    private function purge(string $slug, array $cats): void
    {
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeStory($slug, $cats);
    }

    private function notifyPublish(string $slug): void
    {
        $repo = new AuthoringRepository($this->db);
        $story = $repo->storyForNotify($slug);
        if ($story === null || (int) $story['live_chapters'] === 0) return;
        [$ids, $emails] = (new \App\Repositories\EngagementRepository($this->db))->followersToNotify((int) $story['author_id']);
        $notifications = new \App\Notifications($this->db);
        foreach ($ids as $followerId) {
            $notifications->create($followerId, 'update', (int) $story['story_id'], (int) $story['author_id'], (string) $story['title']);
        }
        $base = rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/');
        foreach ($emails as $email) {
            try {
                $this->mailer->send($email, 'Story update: ' . $story['title'],
                    "A story you follow has a new chapter:\n\n" . $story['title'] . "\n{$base}/story/read/{$story['slug']}/{$story['latest_position']}");
            } catch (\Throwable $e) {
                error_log("follower mail failed: {$e->getMessage()}");
            }
        }
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
