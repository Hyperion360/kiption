<?php // app/src/Controllers/BrowseController.php
namespace App\Controllers;
use Kip\{App, Http\Request, Http\Response, View};
use App\Repositories\StoryRepository;

final class BrowseController
{
    public function __construct(
        private View $view,
        private Request $request,
        private App $app,
        private StoryRepository $stories,
    ) {}

    public function index(): string
    {
        return $this->view->render('browse/index', [
            'title' => 'Browse',
            'theme' => \App\Theme::current($this->request),
            'categories' => $this->stories->categoriesWithCounts(),
        ]);
    }

    public function recent(): string
    {
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        $page = max(1, (int) ($this->request->get['page'] ?? 1));
        $stories = $this->stories->recentStories($perPage, ($page - 1) * $perPage);
        return $this->view->render('browse/recent', [
            'title' => 'Recently updated',
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'stories' => $stories,
            'page' => $page,
            'baseUrl' => '/browse/recent',
        ]);
    }

    public function category(string $slug): Response|string
    {
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        $page = max(1, (int) ($this->request->get['page'] ?? 1));
        $stories = $this->stories->storiesInCategory($slug, $perPage, ($page - 1) * $perPage);
        if ($stories === null) return new Response('Page not found', 404);
        return $this->view->render('browse/recent', [
            'title' => 'Category: ' . $slug,
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'stories' => $stories,
            'page' => $page,
            'baseUrl' => '/browse/category/' . $slug,
        ]);
    }
}
