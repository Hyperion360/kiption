<?php // app/src/Controllers/HomeController.php
namespace App\Controllers;
use Kip\{Database, Http\Request, Session, View};

final class HomeController
{
    public function __construct(private View $view, private Session $session, private Request $request, private \Kip\App $kip, private Database $db) {}

    public function index(): string
    {
        // Cookieless guests must never touch the session. A get() would start
        // one and set a cookie, making this page uncacheable for everyone.
        // Only a request already carrying a cookie can belong to a logged-in user.
        $userId = $this->request->cookies !== [] ? $this->session->get('user_id') : null;
        $loggedIn = $userId !== null;
        // The layout's operator block (Task 4's recorded scope): home passes
        // isAdmin where it is cheap, meaning only for a cookie-carrying viewer;
        // the anonymous render stays query-free and cacheable.
        $isAdmin = $loggedIn
            && (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [(int) $userId])['c'] === 1;
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
            'isAdmin' => $isAdmin,
            'csrf' => $loggedIn ? $this->session->csrfToken() : null,
        ]);
    }
}
