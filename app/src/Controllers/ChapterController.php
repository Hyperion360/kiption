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
        [$title, $content, $before, $after, $rawPublishAt] = $this->chapterInput();
        if (trim($content) === '') {
            return new Response($this->form($slug, null, 'Chapter text is required.'), 422);
        }
        try {
            $publishAt = $this->canonicalPublishAt($rawPublishAt);
        } catch (\InvalidArgumentException) {
            return new Response($this->form($slug, null, \App\Lang::t('chapter.publish_invalid')), 422);
        }
        $auto = $this->autoValidates();
        // The round-robin expansion reaches chapter CREATION only (finding 7's
        // scope ruling): a full member may open the gate on rr stories, while
        // update/delete and every story form stay story-side.
        $rr = \App\Features::on('roundrobin');
        try {
            [$cats, $seriesSlugs, $authorSlug, $challengeSlugs] = $this->repo()->createChapter($slug, $this->uid(), $title, $content, $before, $after, $auto, $publishAt, $rr);
        } catch (\RuntimeException) {
            return new Response('Page not found', 404); // non-owned or unknown story, same contract as story writes
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs);
        // Finding 5: a scheduled create never fans out. storyForNotify only
        // checks live_chapters >= 1, so scheduling onto a live story would
        // notify followers about a chapter they cannot read yet.
        if ($auto && $publishAt === null) $this->notifyPublish($slug);
        // A pure rr contributor cannot see /story/edit (owner-gated by
        // construction); ownStory WITHOUT the flag is exactly the edit page's
        // gate, so the probe cannot drift from the surface it routes to.
        try {
            $this->repo()->ownStory($slug, $this->uid());
            $land = '/story/edit/';
        } catch (\RuntimeException) {
            $land = '/story/view/';
        }
        return Response::redirect($land . $slug);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug, int $position): Response|string
    {
        [$title, $content, $before, $after, $rawPublishAt] = $this->chapterInput();
        try {
            $publishAt = $this->canonicalPublishAt($rawPublishAt);
        } catch (\InvalidArgumentException) {
            return new Response($this->form($slug, $position, \App\Lang::t('chapter.publish_invalid')), 422);
        }
        try {
            [$cats, $seriesSlugs, $authorSlug, $challengeSlugs] = $this->repo()->updateChapter($slug, $position, $this->uid(), $title, $content, $before, $after, $publishAt);
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs);
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
            [$cats, $seriesSlugs, $authorSlug, $challengeSlugs] = $this->repo()->deleteChapter($slug, $position, $this->uid());
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs);
        return Response::redirect('/story/edit/' . $slug);
    }

    private function form(string $slug, ?int $position, ?string $error): Response|string
    {
        // The flag rides every chapterFormData call; the repository scopes the
        // rr clause to the NEW-chapter branch alone (the edit form stays
        // story-side, matching the update/delete writes it feeds).
        $row = $this->repo()->chapterFormData($slug, $position, $this->uid(), \App\Features::on('roundrobin'));
        if ($row === null) return new Response('Page not found', 404);
        return $this->view->render('chapter/form', [
            'title' => $position === null ? \App\Lang::t('chapter.new') : \App\Lang::t('chapter.edit_title'),
            'head' => $this->head()->withTitle($position === null ? \App\Lang::t('chapter.new') : \App\Lang::t('chapter.edit_title'))
                ->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
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

    private function purge(string $slug, array $cats, array $seriesSlugs = [], string $authorSlug = '', array $challengeSlugs = []): void
    {
        // Config-injected dir when present (the StoryController pattern), the
        // tree's public/cache otherwise: tests pin through-controller purges
        // without ever writing into the real dir.
        (new \App\StaticCache\Cache((string) (($this->app->config('static_cache', []) ?? [])['dir'] ?? dirname(__DIR__, 3) . '/public/cache')))
            ->purgeStory($slug, $cats, $seriesSlugs, $authorSlug, [], $challengeSlugs);
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

    /** @return array{string,string,string,string,string} */
    private function chapterInput(): array
    {
        $p = $this->request->post;
        return [trim((string) ($p['title'] ?? '')), (string) ($p['content'] ?? ''),
            trim((string) ($p['notes_before'] ?? '')), trim((string) ($p['notes_after'] ?? '')),
            trim((string) ($p['publish_at'] ?? ''))];
    }

    /** The schedule input's normalizer (finding 1). The zone is OPTIONAL in
     *  the accepted shape because the form's datetime-local input submits
     *  without one; a zone-less value reads as UTC (the third createFromFormat
     *  argument pins that, whatever the host default timezone is). Every
     *  accepted value is canonicalized to Y-m-d\TH:i:s\Z UTC so releaseDue's
     *  lexicographic publish_at <= now compares exact instants: raw offsets
     *  compare wrong in both directions. Empty returns null (the immediate
     *  path unchanged); junk throws for the 422. */
    private function canonicalPublishAt(string $raw): ?string
    {
        if ($raw === '') return null;
        if (!preg_match('#^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})?$#', $raw)) {
            throw new \InvalidArgumentException('publish_at');
        }
        $utc = new \DateTimeZone('UTC');
        foreach (['Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i\Z', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:iP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw, $utc);
            $errs = \DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errs === false || ($errs['warning_count'] === 0 && $errs['error_count'] === 0))) {
                return $dt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
            }
        }
        throw new \InvalidArgumentException('publish_at'); // shape matched, instant did not (2099-13-45T99:99)
    }
}
