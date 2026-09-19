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
        [$perPage, $offset] = $this->paginate();
        $page = $this->page();
        $stories = $this->stories->recentStories($perPage, $offset);
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
        [$perPage, $offset] = $this->paginate();
        $page = $this->page();
        $stories = $this->stories->storiesInCategory($slug, $perPage, $offset);
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

    /** Coerced page param: junk, zero and negatives become page 1. */
    private function page(): int
    {
        return max(1, (int) ($this->request->get['page'] ?? 1));
    }

    /** Overflow guard: (page - 1) * perPage must stay an int, or a hostile
     *  page=99999999999999999999 makes the offset a float and the repository
     *  TypeError turns the listing into a 500. Cap page so it cannot. */
    private function paginate(): array
    {
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        $page = min($this->page(), intdiv(PHP_INT_MAX, $perPage));
        return [$perPage, ($page - 1) * $perPage];
    }
}
