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
        // Only a request already carrying a cookie can belong to a logged-in
        // user, and App\Viewer::id re-validates the epoch: a session revoked
        // by a password change reads as a guest, never as the owner.
        $viewerId = \App\Viewer::id($this->request, $this->session, $this->db);
        $userId = $viewerId !== 0 ? $viewerId : null;
        $loggedIn = $userId !== null;
        // The home page's FIRST content query (finding 3): the featured five.
        // Guest-safe gates match browse (validated, not deleted, not
        // restricted) so the anonymous render and its static cache file never
        // leaks unreviewed, restricted or soft-deleted stories (adult BY
        // RATING is not the restricted gate; those stories age-gate on their
        // own pages, as in every listing); idx_stories_updated serves the
        // ORDER BY. One query, the / budget row's pinned 1.
        // The redesign adds the Kip-blog "Latest" list (S1) to the same
        // query: UNION ALL folds the featured five and the newest six into
        // one round trip, each arm its own idx_stories_updated walk with the
        // same guest gates, carrying the M6 card columns. The kind column
        // splits the rows back apart.
        $cardCols = "s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at,
                    u.penname, r.label AS rating_label,
                    (SELECT json_group_array(json_object('slug', c.slug, 'name', c.name)) FROM story_categories sc
                     JOIN categories c ON c.id = sc.category_id WHERE sc.story_id = s.id) AS cats_blob";
        $gates = 's.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0';
        // A member's mutes hide the author from both shelves, as on every
        // listing; the guest (cacheable) render binds nothing extra.
        $viewer = $loggedIn ? (int) $userId : 0;
        $muted = $viewer > 0 && \App\Features::on('mute');
        if ($muted) $gates .= \App\Repositories\MuteRepository::clause('s');
        $rows = $this->db->all(
            "SELECT * FROM (SELECT 'featured' AS kind, {$cardCols} FROM stories s
                 JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
                 WHERE s.featured = 1 AND {$gates} ORDER BY s.updated_at DESC, s.id DESC LIMIT 5)
             UNION ALL
             SELECT * FROM (SELECT 'latest' AS kind, {$cardCols} FROM stories s
                 JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
                 WHERE {$gates} ORDER BY s.updated_at DESC, s.id DESC LIMIT 6)",
            $muted ? [$viewer, $viewer] : []);
        $featured = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'featured'));
        $latest = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'latest'));
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
            'request' => $this->request,
            'navFile' => (string) $this->kip->config('nav_file', ''),
            'path' => $this->request->path,
            'loggedIn' => $loggedIn,
            'isAdmin' => $isAdmin,
            'featured' => $featured,
            'latest' => $latest,
            'csrf' => $loggedIn ? $this->session->csrfToken() : null,
        ]);
    }
}
