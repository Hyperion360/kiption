<?php // app/Features/Feed/FeedController.php
namespace App\Features\Feed;
use Kip\{App, Database, Http\Response};
use App\Repositories\StoryRepository;
use App\Repositories\UserRepository;

final class FeedController
{
    public function __construct(private App $app, private Database $db) {}

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
            $this->fullText()), 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
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
