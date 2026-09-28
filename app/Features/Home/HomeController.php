<?php // app/Features/Home/HomeController.php
namespace App\Features\Home;
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
        // The home page's FIRST content query (finding 3): the featured five.
        // Guest-safe gates match browse (validated, not deleted, not
        // restricted) so the anonymous render and its static cache file never
        // leaks unreviewed, restricted or soft-deleted stories (adult BY
        // RATING is not the restricted gate; those stories age-gate on their
        // own pages, as in every listing); idx_stories_updated serves the
        // ORDER BY. One query, the / budget row's pinned 1.
        $featured = $this->db->all(
            'SELECT slug, title, summary FROM stories WHERE featured = 1 AND validated = 1 AND deleted_at IS NULL AND is_restricted = 0 ORDER BY updated_at DESC, id DESC LIMIT 5');
        // The layout's operator block (Task 4's recorded scope): home passes
        // isAdmin where it is cheap, meaning only for a cookie-carrying viewer;
        // the anonymous render stays at its single content query.
        $isAdmin = $loggedIn
            && (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [(int) $userId])['c'] === 1;
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => (string) $this->kip->config('site_name', 'Kiption'),
        ];
        // Finding 9a: the SearchAction entry joins the WebSite node only when
        // the search flag is on, so a searchless archive never advertises a
        // dead target (the node itself stays either way).
        if (\App\Features::on('search')) {
            $jsonLd['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => '/search?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ];
        }
        $head = \App\Seo\Head::make(
            siteName: (string) $this->kip->config('site_name', 'Kiption'),
            ogImage: (string) $this->kip->config('og_image', ''),
            baseUrl: rtrim((string) $this->kip->config('base_url', ''), '/'),
        )->withTitle(null)
            ->withCanonical($this->request->path)
            ->withJsonLd($jsonLd);
        return $this->view->render('home/index', [
            'title' => \App\Lang::t('nav.brand'),
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->kip->config('nav_file', ''),
            'path' => $this->request->path,
            'loggedIn' => $loggedIn,
            'isAdmin' => $isAdmin,
            'featured' => $featured,
            'csrf' => $loggedIn ? $this->session->csrfToken() : null,
        ]);
    }
}
