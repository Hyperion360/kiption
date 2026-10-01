<?php // app/Features/Analytics/AnalyticsController.php
namespace App\Features\Analytics;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\Auth as AuthAttr;

/** The site-wide admin analytics dashboard (/analytics): reads ONLY
 *  already-collected aggregates (page_stats rollups, story_kudos, favorites,
 *  users) - no new collection, nothing member-identifying, the recorded
 *  privacy stance. The one-query law carries a documented admin-surface
 *  exception here (the browse-at-2 precedent): FIVE content queries, the
 *  four 30-day day-series plus the top-10 list. The seven totals AND the
 *  admin gate ride ONE one-row driver that runs FIRST (findings 4 and 5,
 *  the queue '0gate' precedent): FROM (SELECT 1) x guarantees the row a
 *  list-shaped fold would drop on an empty window, and the folded is_admin
 *  scalar draws the 403 before any series or list statement executes.
 *  Zero JS (the stats-page precedent), noindex meta plus the X-Robots-Tag
 *  belt, so the dashboard never fills the static layer. */
final class AnalyticsController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        if (($r = \App\Features::guard('analytics')) !== null) return $r;
        $driver = $this->db->one(
            "SELECT (SELECT COUNT(*) FROM users WHERE id = ? AND role = 'admin') is_admin,
                    (SELECT COUNT(*) FROM stories) stories,
                    (SELECT COUNT(*) FROM chapters WHERE validated = 1) chapters,
                    (SELECT COUNT(*) FROM users) members,
                    (SELECT COUNT(*) FROM reviews) reviews,
                    (SELECT COUNT(*) FROM story_kudos) kudos,
                    (SELECT COUNT(*) FROM favorites) favorites,
                    (SELECT COALESCE(SUM(reads), 0) FROM page_stats WHERE chapter_id = 0) reads
             FROM (SELECT 1) x",
            [(int) ($this->session->get('user_id') ?? 0)]);
        if ((int) ($driver['is_admin'] ?? 0) === 0) return new Response('Forbidden', 403);
        $totals = [
            'stories' => (int) $driver['stories'], 'chapters' => (int) $driver['chapters'],
            'members' => (int) $driver['members'], 'reviews' => (int) $driver['reviews'],
            'kudos' => (int) $driver['kudos'], 'favorites' => (int) $driver['favorites'],
            'reads' => (int) $driver['reads'],
        ];
        // Reads by day from the chapter_id = 0 ROLLUP rows only (finding 2's
        // double-count rule: the beacon writes a chapter row AND a rollup row
        // per hit, so summing chapter rows too would count every read twice).
        $readsByDay = [];
        foreach ($this->db->all(
            "SELECT day, SUM(reads) reads FROM page_stats
              WHERE chapter_id = 0 AND day >= date('now', '-30 days')
              GROUP BY day ORDER BY day") as $r) {
            $readsByDay[] = ['day' => (string) $r['day'], 'count' => (int) $r['reads']];
        }
        $kudosByDay = $this->byDay('story_kudos');
        $favoritesByDay = $this->byDay('favorites');
        $membersByDay = $this->byDay('users');
        // Top stories: the trending branch's 30-day velocity (rollup reads
        // plus in-window kudos, anchored on the UNION of read-stories and
        // kudos-stories so a kudos-only story surfaces) behind the guest
        // gates, top 10.
        $top = [];
        foreach ($this->db->all(
            "SELECT s.slug, s.title, x.c
             FROM (SELECT u.story_id,
                          COALESCE((SELECT SUM(p.reads) FROM page_stats p
                                    WHERE p.story_id = u.story_id AND p.chapter_id = 0
                                      AND p.day >= date('now', '-30 days')), 0)
                        + COALESCE((SELECT COUNT(*) FROM story_kudos wk
                                    WHERE wk.story_id = u.story_id
                                      AND wk.created_at >= date('now', '-30 days')), 0) c
                   FROM (SELECT story_id FROM page_stats
                         WHERE day >= date('now', '-30 days') AND chapter_id = 0
                         UNION
                         SELECT story_id FROM story_kudos
                         WHERE created_at >= date('now', '-30 days')) u
                   ORDER BY c DESC, u.story_id LIMIT 10) x
             JOIN stories s ON s.id = x.story_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0
             ORDER BY x.c DESC, x.story_id") as $r) {
            $top[] = ['slug' => (string) $r['slug'], 'title' => (string) $r['title'], 'count' => (int) $r['c']];
        }
        $title = \App\Lang::t('analytics.heading');
        $rendered = $this->view->render('analytics/index', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/analytics')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'totals' => $totals,
            'readsByDay' => $readsByDay, 'kudosByDay' => $kudosByDay,
            'favoritesByDay' => $favoritesByDay, 'membersByDay' => $membersByDay,
            'top' => $top,
            'loggedIn' => true,
            'isAdmin' => true, // the driver's is_admin already proved it
        ]);
        return (new Response($rendered, 200))->withHeader('X-Robots-Tag', 'noindex');
    }

    /** The three created_at-keyed day-series (kudos, favorites, new members),
     *  one shared shape (finding 14's probe): day-granular counts over the
     *  30-day window. */
    private function byDay(string $table): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT strftime('%Y-%m-%d', created_at) d, COUNT(*) c FROM {$table}
              WHERE created_at >= date('now', '-30 days') GROUP BY d ORDER BY d") as $r) {
            $out[] = ['day' => (string) $r['d'], 'count' => (int) $r['c']];
        }
        return $out;
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }
}
