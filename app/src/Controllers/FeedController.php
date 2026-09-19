<?php // app/src/Controllers/FeedController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Response};
use App\Repositories\StoryRepository;

final class FeedController
{
    public function __construct(private App $app, private Database $db) {}

    public function index(): Response
    {
        return $this->serve('application/atom+xml; charset=utf-8', \App\Seo\Feed::atom(...));
    }

    private function serve(string $contentType, \Closure $build): Response
    {
        $stories = (new StoryRepository($this->db))->recentStories(20, 0);
        return new Response($build(
            (string) $this->app->config('site_name', 'Kiption'),
            rtrim((string) $this->app->config('base_url', 'http://localhost'), '/'),
            $stories), 200, ['Content-Type' => $contentType]);
    }
}
