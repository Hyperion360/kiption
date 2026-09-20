<?php // app/src/Controllers/UserController.php
namespace App\Controllers;
use Kip\{App, Http\Request, Http\Response, Session, View};
use App\Repositories\UserRepository;

final class UserController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Session $session,
        private App $app,
        private UserRepository $users,
    ) {}

    public function view(string $slug): Response|string
    {
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $profile = $this->users->findByProfileSlug($slug);
        if ($profile === null) return new Response('Page not found', 404);
        $site = (string) $this->app->config('site_name', 'Kiption');
        $head = $this->head()->withTitle($profile['penname'])
            ->withDescription(($profile['bio'] ?? '') !== '' ? (string) $profile['bio'] : 'Stories by ' . $profile['penname'] . ' on ' . $site . '.')
            ->withCanonical('/user/view/' . $slug);
        // Person JSON-LD url is absolute: Head.php's own rule, the series ruling
        $head = $head->withJsonLd(['@context' => 'https://schema.org', '@type' => 'Person',
            'name' => $profile['penname'], 'url' => $head->url('/user/view/' . $slug)]);
        if ($profile['avatar_path'] !== null && (string) $profile['avatar_path'] !== '') {
            // og:image needs an absolute URL; url() is Head's own absolutizer
            $head = $head->withOgImage($head->url((string) $profile['avatar_path']));
        }
        return $this->view->render('user/view', [
            'title' => $profile['penname'],
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'slug' => $slug,
            'profile' => $profile,
            'loggedIn' => $me !== 0,
        ]);
    }

    public function stories(string $slug): Response|string
    {
        [$perPage, $offset] = $this->paginate();
        // ?sort= is whitelisted in PHP before the repository interpolates it;
        // junk coerces to 'recent' (the BrowseController page-param philosophy)
        $sort = ($this->request->get['sort'] ?? '') === 'alpha' ? 'alpha' : 'recent';
        $tab = $this->users->storiesTab($slug, $sort, $perPage, $offset);
        if ($tab === null) return new Response('Page not found', 404);
        return $this->renderTab($slug, $tab, 'Stories by ', '/user/stories/' . $slug);
    }

    public function favorites(string $slug): Response|string
    {
        [$perPage, $offset] = $this->paginate();
        $tab = $this->users->favoritesTab($slug, $perPage, $offset);
        if ($tab === null) return new Response('Page not found', 404);
        return $this->renderTab($slug, $tab, 'Favorites of ', '/user/favorites/' . $slug);
    }

    /** Both tabs reuse browse/recent.php verbatim; an empty tab is the
     *  empty-category precedent: meta noindex plus the X-Robots-Tag header. */
    private function renderTab(string $slug, array $tab, string $prefix, string $baseUrl): Response|string
    {
        $profile = $tab['profile'];
        $title = $prefix . $profile['penname'];
        $head = $this->head()->withTitle($title)
            ->withDescription($title . ' on ' . (string) $this->app->config('site_name', 'Kiption') . '.')
            ->withCanonical($baseUrl);
        $data = [
            'title' => $title,
            'head' => $tab['stories'] === [] ? $head->withNoindex() : $head,
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'stories' => $tab['stories'],
            'page' => $this->page(),
            'baseUrl' => $baseUrl,
        ];
        return $tab['stories'] === []
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
