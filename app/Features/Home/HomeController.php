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
        // The home page's ONE content query (finding 3, and the / budget
        // row's pinned 1 for guests and members alike): UNION ALL folds four
        // arms into a single round trip, each arm its own index walk, the
        // kind column splitting the rows back apart.
        //   featured/latest - the shelves. Guest-safe gates match browse
        //     (validated, not deleted, not restricted) so the anonymous
        //     render and its static cache file never leak unreviewed,
        //     restricted or soft-deleted stories (adult BY RATING is not the
        //     restricted gate; those stories age-gate on their own pages, as
        //     in every listing); idx_stories_updated serves the ORDER BY.
        //     The redesign's Kip-blog "Latest" list (S1) is the second arm.
        //   continue (member only) - the Magic Ink context-from-history
        //     card. Member-only arms keep the guest SQL the pinned
        //     cacheable shape. The read-side shape applies (restricted
        //     stories are readable once logged in, the same rule read()
        //     enforces); marked rows are the save-for-later shelf (the
        //     Account split rides the same column), not in-progress reads;
        //     mutes hold as on the shelves. The usable-row filter (a
        //     validated chapter at or below the saved position) sits in the
        //     WHERE, so a story whose every chapter above the mark went
        //     unpublished falls through to the next-best history row
        //     instead of blanking the card, and idx_reading_user_updated
        //     still serves the newest-first walk with no sort.
        //   admin (member only) - the layout's operator block (Task 4's
        //     recorded scope), one row when the viewer is an admin, no rows
        //     otherwise; the anonymous render binds no member arms.
        $cardCols = "s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at,
                    u.penname, r.label AS rating_label,
                    (SELECT json_group_array(json_object('slug', c.slug, 'name', c.name)) FROM story_categories sc
                     JOIN categories c ON c.id = sc.category_id WHERE sc.story_id = s.id) AS cats_blob";
        $shelveCols = 'NULL AS resume, NULL AS words_before, NULL AS words_total, 0 AS is_admin';
        $gates = 's.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0';
        // A member's mutes hide the author from both shelves and the card,
        // as on every listing; the guest (cacheable) render binds nothing extra.
        $viewer = $loggedIn ? (int) $userId : 0;
        $muted = $viewer > 0 && \App\Features::on('mute');
        if ($muted) $gates .= \App\Repositories\MuteRepository::clause('s');
        $memberArms = '';
        $memberBinds = [];
        if ($loggedIn) {
            $continueGates = 's.validated = 1 AND s.deleted_at IS NULL';
            if ($muted) { $continueGates .= \App\Repositories\MuteRepository::clause('s'); }
            $memberArms = "
             UNION ALL
             SELECT * FROM (SELECT 'continue' AS kind, s.slug, s.title,
                        NULL AS summary, 0 AS completed, 0 AS word_count, NULL AS updated_at,
                        NULL AS penname, NULL AS rating_label, NULL AS cats_blob,
                        (SELECT MAX(c.position) FROM chapters c WHERE c.story_id = s.id AND c.validated = 1 AND c.position <= rh.last_position) AS resume,
                        (SELECT COALESCE(SUM(c2.word_count), 0) FROM chapters c2 WHERE c2.story_id = s.id AND c2.validated = 1 AND c2.position <= rh.last_position) AS words_before,
                        (SELECT COALESCE(SUM(c3.word_count), 0) FROM chapters c3 WHERE c3.story_id = s.id AND c3.validated = 1) AS words_total,
                        0 AS is_admin
                 FROM reading_history rh JOIN stories s ON s.id = rh.story_id
                 WHERE rh.user_id = ? AND rh.marked_at IS NULL AND {$continueGates}
                   AND EXISTS (SELECT 1 FROM chapters c4 WHERE c4.story_id = s.id AND c4.validated = 1 AND c4.position <= rh.last_position)
                 ORDER BY rh.updated_at DESC, rh.rowid DESC LIMIT 1)
             UNION ALL
             SELECT 'admin' AS kind, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1
             FROM users u9 WHERE u9.id = ? AND u9.role = 'admin'";
            $memberBinds = $muted
                ? [(int) $userId, $viewer, $viewer]
                : [(int) $userId, (int) $userId];
        }
        $rows = $this->db->all(
            "SELECT * FROM (SELECT 'featured' AS kind, {$cardCols}, {$shelveCols} FROM stories s
                 JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
                 WHERE s.featured = 1 AND {$gates} ORDER BY s.updated_at DESC, s.id DESC LIMIT 5)
             UNION ALL
             SELECT * FROM (SELECT 'latest' AS kind, {$cardCols}, {$shelveCols} FROM stories s
                 JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
                 WHERE {$gates} ORDER BY s.updated_at DESC, s.id DESC LIMIT 6){$memberArms}",
            $muted ? [$viewer, $viewer, ...$memberBinds] : $memberBinds);
        $featured = [];
        $latest = [];
        $continue = null;
        $isAdmin = false;
        foreach ($rows as $r) {
            if ($r['kind'] === 'featured') { $featured[] = $r; }
            elseif ($r['kind'] === 'latest') { $latest[] = $r; }
            elseif ($r['kind'] === 'continue') { $continue = $r; }
            elseif ($r['kind'] === 'admin') { $isAdmin = true; }
        }
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
            'continue' => $continue,
            'csrf' => $loggedIn ? $this->session->csrfToken() : null,
        ]);
    }
}
