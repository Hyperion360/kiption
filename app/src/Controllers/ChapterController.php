<?php // app/src/Controllers/ChapterController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\AuthoringRepository;

final class ChapterController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app, private \Kip\Mailer $mailer,
    ) {}

    #[AuthAttr]
    public function new(string $slug): Response|string
    {
        return $this->form($slug, null, null);
    }

    #[AuthAttr]
    public function edit(string $slug, int $position): Response|string
    {
        return $this->form($slug, $position, null);
    }

    #[AuthAttr] #[Post]
    public function create(string $slug): Response|string
    {
        [$title, $content, $before, $after] = $this->chapterInput();
        if (trim($content) === '') {
            return new Response($this->form($slug, null, 'Chapter text is required.'), 422);
        }
        $auto = $this->autoValidates();
        try {
            [$cats, $seriesSlugs, $authorSlug] = $this->repo()->createChapter($slug, $this->uid(), $title, $content, $before, $after, $auto);
        } catch (\RuntimeException) {
            return new Response('Page not found', 404); // non-owned or unknown story, same contract as story writes
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug);
        if ($auto) $this->notifyPublish($slug);
        return Response::redirect('/story/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug, int $position): Response|string
    {
        [$title, $content, $before, $after] = $this->chapterInput();
        try {
            [$cats, $seriesSlugs, $authorSlug] = $this->repo()->updateChapter($slug, $position, $this->uid(), $title, $content, $before, $after);
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug);
        $wasLive = (int) ($this->db->one(
            'SELECT validated FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = ?) AND position = ?',
            [$slug, $position])['validated'] ?? 0);
        if ($wasLive === 1) $this->notifyPublish($slug); // only an already-live chapter's edit is "news"
        return Response::redirect('/story/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function delete(string $slug, int $position): Response
    {
        try {
            [$cats, $seriesSlugs, $authorSlug] = $this->repo()->deleteChapter($slug, $position, $this->uid());
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug);
        return Response::redirect('/story/edit/' . $slug);
    }

    private function form(string $slug, ?int $position, ?string $error): Response|string
    {
        $row = $this->repo()->chapterFormData($slug, $position, $this->uid());
        if ($row === null) return new Response('Page not found', 404);
        return $this->view->render('chapter/form', [
            'title' => $position === null ? 'New chapter' : 'Edit chapter',
            'head' => $this->head()->withTitle($position === null ? 'New chapter' : 'Edit chapter')
                ->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'story' => $row,
            'position' => $position,
            'csrf' => $this->session->csrfToken(),
            'error' => $error,
            'loggedIn' => true,
        ]);
    }

    private function repo(): AuthoringRepository { return new AuthoringRepository($this->db); }
    private function uid(): int { return (int) $this->session->get('user_id'); }

    private function autoValidates(): bool
    {
        $role = (string) ($this->db->one('SELECT role FROM users WHERE id = ?', [$this->uid()])['role'] ?? 'member');
        return !((bool) $this->app->config('validation_required', true))
            || in_array($role, ['validated_author', 'moderator', 'admin'], true);
    }

    private function purge(string $slug, array $cats, array $seriesSlugs = [], string $authorSlug = ''): void
    {
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeStory($slug, $cats, $seriesSlugs, $authorSlug);
    }

    private function notifyPublish(string $slug): void
    {
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        $story = $repo->storyForNotify($slug);
        if ($story === null || (int) $story['live_chapters'] === 0) return;
        $engagement = new \App\Repositories\EngagementRepository($this->db);
        [$followerIds, $followerEmails] = $engagement->followersToNotify((int) $story['author_id']);
        [$favoriterIds, $favoriterEmails] = $engagement->favoritersToNotify((int) $story['story_id']);
        // Union with dedupe by id: a member who both follows the author and favorited
        // the story gets ONE notification row, never two.
        $uniqueIds = [];
        foreach ($followerIds as $id) { $uniqueIds[$id] = true; }
        foreach ($favoriterIds as $id) { $uniqueIds[$id] = true; }
        // One immediate email per member across both channels: merging the two
        // user_id-keyed email maps dedupes by construction (entries are the same
        // users.email either way).
        $emails = $favoriterEmails;
        foreach ($followerEmails as $id => $email) { $emails[$id] = $email; }
        $notifications = new \App\Notifications($this->db);
        foreach (array_keys($uniqueIds) as $memberId) {
            $notifications->create((int) $memberId, 'update', (int) $story['story_id'], (int) $story['author_id'], (string) $story['title']);
        }
        $base = rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/');
        foreach ($emails as $memberId => $email) {
            try {
                $this->mailer->send($email, 'Story update: ' . $story['title'],
                    "A story you follow has a new chapter:\n\n" . $story['title'] . "\n{$base}/story/read/{$story['slug']}/{$story['latest_position']}");
            } catch (\Throwable $e) {
                error_log("follower mail failed for member {$memberId}: {$e->getMessage()}");
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

    /** @return array{string,string,string,string} */
    private function chapterInput(): array
    {
        $p = $this->request->post;
        return [trim((string) ($p['title'] ?? '')), (string) ($p['content'] ?? ''),
            trim((string) ($p['notes_before'] ?? '')), trim((string) ($p['notes_after'] ?? ''))];
    }
}
