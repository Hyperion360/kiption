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
        // Same regex the story form stores by: junk reads as unfiltered, like
        // the junk page param. The query string makes these pages cache-ineligible.
        $language = trim((string) ($this->request->get['language'] ?? ''));
        if ($language !== '' && !preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language)) {
            $language = '';
        }
        return $this->view->render('browse/index', [
            'title' => 'Browse',
            'head' => $this->head()->withTitle('Browse')->withCanonical('/browse'),
            'theme' => \App\Theme::current($this->request),
            'categories' => $this->stories->categoriesWithCounts(),
            'language' => $language,
            'langStories' => $language === '' ? [] : $this->stories->storiesInLanguage($language),
        ]);
    }

    public function recent(): string
    {
        [$perPage, $offset] = $this->paginate();
        $page = $this->page();
        $stories = $this->stories->recentStories($perPage, $offset);
        $items = [];
        foreach ($stories as $i => $s) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => '/story/view/' . $s['slug'], 'name' => $s['title']];
        }
        return $this->view->render('browse/recent', [
            'title' => 'Recently updated',
            'head' => $this->head()->withTitle('Recently updated')
                ->withCanonical($this->request->path)
                ->withJsonLd(['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items]),
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
        $head = $this->head()->withTitle('Category: ' . $slug)
            ->withCanonical($this->request->path)
            ->withJsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Browse', 'item' => '/browse'],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => 'Category: ' . $slug, 'item' => '/browse/category/' . $slug],
                ],
            ]);
        $data = [
            'title' => 'Category: ' . $slug,
            'head' => $stories === [] ? $head->withNoindex() : $head,
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'stories' => $stories,
            'page' => $page,
            'baseUrl' => '/browse/category/' . $slug,
        ];
        // Empty category pages have no unique content to rank; belt (meta) and
        // suspenders (header) so no cache or crawler ever indexes them.
        return $stories === []
            ? (new Response($this->view->render('browse/recent', $data), 200))->withHeader('X-Robots-Tag', 'noindex')
            : $this->view->render('browse/recent', $data);
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
