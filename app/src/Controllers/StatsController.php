<?php // app/src/Controllers/StatsController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Session, View};
use Kip\Routing\Auth as AuthAttr;

/** The author stats dashboard (/stats): ONE compound query listing the
 *  member's own works with four scalar subqueries riding along - total
 *  reads and 30-day reads from the chapter_id = 0 ROLLUP rows ONLY
 *  (finding 2: the beacon writes a chapter row AND a rollup row per hit,
 *  so summing chapter rows too would double-count every read), plus
 *  kudos and favorites counts. The story gate is the OWN-SURFACE gate
 *  (finding 8): author-or-coauthor + not-deleted and NOTHING else, since
 *  the storiesTab guest gates (validated, restricted) would strip the
 *  author's own restricted and pending works from their dashboard.
 *  Aggregate counts only, no reader identities: page_stats stores none
 *  (the recorded privacy stance). Tables, zero charts. */
final class StatsController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Database $db,
        private Session $session,
        private App $app,
    ) {}

    #[AuthAttr]
    public function index(): string
    {
        $me = (int) $this->session->get('user_id');
        $rows = $this->db->all(
            "SELECT s.slug, s.title, s.validated,
                    (SELECT COALESCE(SUM(ps.reads), 0) FROM page_stats ps
                      WHERE ps.story_id = s.id AND ps.chapter_id = 0) AS total_reads,
                    (SELECT COALESCE(SUM(ps2.reads), 0) FROM page_stats ps2
                      WHERE ps2.story_id = s.id AND ps2.chapter_id = 0
                        AND ps2.day >= date('now', '-30 days')) AS month_reads,
                    (SELECT COUNT(*) FROM story_kudos k WHERE k.story_id = s.id) AS kudos,
                    (SELECT COUNT(*) FROM favorites f WHERE f.story_id = s.id) AS favorites
             FROM stories s
             WHERE s.deleted_at IS NULL
               AND (s.author_id = ?
                    OR EXISTS (SELECT 1 FROM coauthors ca WHERE ca.story_id = s.id AND ca.user_id = ?))
             ORDER BY s.updated_at DESC, s.id DESC LIMIT 250",
            // TWO binds, one per gate arm (author match, coauthor EXISTS); the
            // LIMIT 250 cap mirrors the account fold's own-works bound.
            [$me, $me]);
        foreach ($rows as &$r) {
            $r['validated'] = (int) $r['validated'];
            $r['total_reads'] = (int) $r['total_reads'];
            $r['month_reads'] = (int) $r['month_reads'];
            $r['kudos'] = (int) $r['kudos'];
            $r['favorites'] = (int) $r['favorites'];
        }
        unset($r);
        $title = \App\Lang::t('stats.heading');
        return $this->view->render('stats/index', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/stats')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $rows,
            'loggedIn' => true,
        ]);
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
