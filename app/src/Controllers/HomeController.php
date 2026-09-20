<?php // app/src/Controllers/HomeController.php
namespace App\Controllers;
use Kip\{Http\Request, Session, View};

final class HomeController
{
    public function __construct(private View $view, private Session $session, private Request $request, private \Kip\App $kip) {}

    public function index(): string
    {
        // Cookieless guests must never touch the session. A get() would start
        // one and set a cookie, making this page uncacheable for everyone.
        // Only a request already carrying a cookie can belong to a logged-in user.
        $loggedIn = $this->request->cookies !== [] && $this->session->get('user_id') !== null;
        $head = \App\Seo\Head::make(
            siteName: (string) $this->kip->config('site_name', 'Kiption'),
            ogImage: (string) $this->kip->config('og_image', ''),
            baseUrl: rtrim((string) $this->kip->config('base_url', ''), '/'),
        )->withTitle(null)
            ->withCanonical($this->request->path)
            ->withJsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => (string) $this->kip->config('site_name', 'Kiption'),
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => '/search?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ]);
        return $this->view->render('home/index', [
            'title' => 'Kiption',
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->kip->config('nav_file', ''),
            'path' => $this->request->path,
            'loggedIn' => $loggedIn,
            'csrf' => $loggedIn ? $this->session->csrfToken() : null,
        ]);
    }
}
