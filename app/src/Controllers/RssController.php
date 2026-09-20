<?php // app/src/Controllers/RssController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Response};
use App\Repositories\StoryRepository;

final class RssController
{
    public function __construct(private App $app, private Database $db) {}

    public function index(): Response
    {
        // The shared feed base (finding 14): /rss carries the same gates as
        // /feed, syndication exclusion included; RSS stays summary-mode (its
        // description element is plain text, no content counterpart specced).
        $stories = (new StoryRepository($this->db))->feedStories(20, 0);
        return new Response(\App\Seo\Feed::rss(
            (string) $this->app->config('site_name', 'Kiption'),
            rtrim((string) $this->app->config('base_url', 'http://localhost'), '/'),
            $stories), 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }
}
