<?php // app/Features/Feed/FeedController.php
namespace App\Features\Feed;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use App\Repositories\StoryRepository;
use App\Repositories\UserRepository;

final class FeedController
{
    public function __construct(
        private App $app,
        private Database $db,
        private View $view,
        private Request $request,
        private Session $session,
    ) {}

    /** /feed/subscribe: the human page behind the footer's Feed link. A raw
     *  feed is for feed readers; a browser that opens one either shows XML or
     *  downloads it, so people land here instead: what a feed is, the
     *  addresses to paste into a reader, and the per-category feeds. One
     *  query (the category list). */
    public function subscribe(): Response
    {
        if (($r = \App\Features::guard('feeds')) !== null) return $r;
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $base = rtrim((string) $this->app->config('base_url', 'http://localhost'), '/');
        $categories = $this->db->all('SELECT slug, name FROM categories ORDER BY position, name');
        $title = \App\Lang::t('feed.subscribe_heading');
        return new Response($this->view->render('feed/subscribe', [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: $base,
            )->withTitle($title)->withCanonical('/feed/subscribe'),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'loggedIn' => $me !== 0,
            'base' => $base,
            'categories' => $categories,
        ]));
    }

    public function index(): Response
    {
        if (($r = \App\Features::guard('feeds')) !== null) return $r;
        $stories = (new StoryRepository($this->db))->feedStories(20, 0, $this->fullText());
        return $this->serveAtom((string) $this->app->config('site_name', 'Kiption'), $stories);
    }

    /** /feed/author/{slug}: the anchor-row feed. Zero rows means the slug is
     *  unknown (or the member locked/penname-less): the profile page's 404.
     *  NULL-story rows are the anchor itself: a valid EMPTY feed titled with
     *  the penname, never a 404. */
    public function author(string $slug): Response
    {
        if (($r = \App\Features::guard('feeds')) !== null) return $r;
        $rows = (new UserRepository($this->db))->authorFeed($slug, 20, 0, $this->fullText());
        if ($rows === null || $rows === []) return new Response('Page not found', 404);
        return $this->serveAtom((string) $rows[0]['feed_title'], $this->entryRows($rows));
    }

    /** /feed/category/{slug}: the same anchor-vs-unknown semantics, with the
     *  category name titling the feed and an empty category rendering empty. */
    public function category(string $slug): Response
    {
        if (($r = \App\Features::guard('feeds')) !== null) return $r;
        $rows = (new StoryRepository($this->db))->categoryFeed($slug, 20, 0, $this->fullText());
        if ($rows === []) return new Response('Page not found', 404);
        return $this->serveAtom((string) $rows[0]['feed_title'], $this->entryRows($rows));
    }

    private function serveAtom(string $title, array $stories): Response
    {
        return new Response(\App\Seo\Feed::atom(
            $title,
            rtrim((string) $this->app->config('base_url', 'http://localhost'), '/'),
            $stories,
            $this->fullText()), 200, [
                'Content-Type' => 'application/atom+xml; charset=utf-8',
                // a browser that downloads instead of rendering saves a named,
                // extension-bearing file (it used to save "feed", no extension)
                'Content-Disposition' => 'inline; filename="kiption.atom.xml"',
            ]);
    }

    private function fullText(): bool
    {
        return (bool) $this->app->config('feeds_full_text', false);
    }

    /** @return list<array<string,mixed>> the rows that carry a story */
    private function entryRows(array $rows): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => $r['slug'] !== null));
    }
}
