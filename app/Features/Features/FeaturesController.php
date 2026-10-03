<?php // app/Features/Features/FeaturesController.php
namespace App\Features\Features;
use App\Features;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** The admin feature-flag board (/features): one row per inventory key, one
 *  POST to flip it. Flags are site-wide operator surface, so the gate is the
 *  SQL admin check (the Adminstories idiom): moderators keep the queues and
 *  get 403 here. The surface itself is never flaggable (the recorded
 *  never-list). Every successful toggle purges the whole static layer, clears
 *  the framework page cache and refreshes the sitemap: a hidden link must not
 *  outlive its flag in any cache or segment. */
final class FeaturesController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $title = \App\Lang::t('features.heading');
        return $this->view->render('features/index', [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical('/features')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'features' => Features::all(),
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true, // the gate above already proved it
        ]);
    }

    /** Flip one flag to the other state (the featured 1-featured precedent:
     *  the board renders the state, so the POST carries no target value).
     *  Unknown keys answer 422, never an insert (toggle() throws first). */
    #[AuthAttr] #[Post]
    public function toggle(string $key): Response
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        try {
            Features::toggle($key, !Features::on($key));
        } catch (\InvalidArgumentException) {
            return new Response('Unknown feature flag', 422);
        }
        $this->purge();
        return Response::redirect('/features');
    }

    private function admin(): bool
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$me])['c'] === 1;
    }

    /** The full purge set (findings 3c + 12): Builder::prune VERBATIM (the
     *  NavController::update precedent: the whole static layer, maintenance
     *  marker preserved, plus the framework page cache file), then the sitemap
     *  refreshed under the same public_dir + base_url guard as Builder's own
     *  rebuild pass. toggle() dropped the memo, so writeAll resolves fresh
     *  state: it unlinks every sitemap*.xml and skips gated-off segments, so
     *  a freshly hidden surface drops out of the index on the same request. */
    private function purge(): void
    {
        $cacheDir = \App\StaticCache\Cache::configuredDir($this->app);
        \App\StaticCache\Builder::prune($cacheDir, (string) $this->app->config('app_dir', dirname(__DIR__, 2)));
        $publicDir = $this->app->config('public_dir');
        $baseUrl = rtrim((string) $this->app->config('base_url', ''), '/');
        if ($publicDir !== null && $baseUrl !== '') {
            \App\Seo\Sitemap::writeAll($this->db, (string) $publicDir, $baseUrl, []);
        }
    }
}
