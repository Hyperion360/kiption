<?php // app/src/Controllers/SearchController.php
namespace App\Controllers;
use Kip\{App, Http\Request, View};
use App\Repositories\SearchRepository;

final class SearchController
{
    public function __construct(
        private View $view,
        private Request $request,
        private \Kip\Session $session, // finding 9: the StoryController::view idiom,
        private App $app,              // NOT a Request->session property (none exists)
        private SearchRepository $search,
    ) {}

    /** GET /search: one budget-1 statement returns the filter taxonomies AND
     *  the paged, gated results (searchWithTaxonomies). The query string
     *  makes the surface cache-ineligible by the queryless rule; it also
     *  renders noindex (meta + X-Robots-Tag) because query surfaces are not
     *  canonical content. Junk filters coerce, never 500. */
    public function index(): \Kip\Http\Response|string
    {
        $q = trim((string) ($this->request->get['q'] ?? ''));
        $filters = [
            'category' => preg_match('#^[a-z0-9-]+$#', (string) ($this->request->get['category'] ?? '')) ? (string) $this->request->get['category'] : null,
            'rating_id' => ctype_digit((string) ($this->request->get['rating'] ?? '')) ? (int) $this->request->get['rating'] : null,
            'completed' => ($this->request->get['completed'] ?? '') === '1' ? true : null,
            'language' => preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', (string) ($this->request->get['language'] ?? '')) ? (string) $this->request->get['language'] : null,
        ];
        [$perPage, $offset] = $this->paginate();
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $result = $this->search->searchWithTaxonomies($q, $filters, $perPage, $offset, $me);
        $head = $this->head()->withTitle('Search')->withCanonical('/search')->withNoindex();
        $out = $this->view->render('search/index', [
            'title' => 'Search', 'head' => $head, 'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'q' => $q, 'filters' => $filters, 'result' => $result,
            'ratings' => $result['ratings'], 'categories' => $result['categories'],
            'page' => $this->page(), 'perPage' => $perPage, 'loggedIn' => $me !== 0,
        ]);
        return (new \Kip\Http\Response($out, 200))->withHeader('X-Robots-Tag', 'noindex');
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
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
