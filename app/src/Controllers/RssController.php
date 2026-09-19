<?php // app/src/Controllers/RssController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Response};
use App\Repositories\StoryRepository;

final class RssController
{
    public function __construct(private App $app, private Database $db) {}

    public function index(): Response
    {
        $stories = (new StoryRepository($this->db))->recentStories(20, 0);
        return new Response(\App\Seo\Feed::rss(
            (string) $this->app->config('site_name', 'Kiption'),
            rtrim((string) $this->app->config('base_url', 'http://localhost'), '/'),
            $stories), 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }
}
