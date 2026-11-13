<?php // app/Features/Features/FeaturesController.php
namespace App\Features\Features;
use App\Adminness;
use App\Features;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** The admin feature-flag board (/features): one row per inventory key, one
 *  POST to flip it. Flags are site-wide operator surface, so the gate is
 *  Adminness::requireAdmin: moderators keep the queues and get 403 here.
 *  The surface itself is never flaggable (the recorded
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
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
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
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        try {
            Features::toggle($key, !Features::on($key));
        } catch (\InvalidArgumentException) {
            return new Response(\App\Lang::t('admin.err_flag'), 422);
        }
        \App\ActivityLog::record($this->db, (int) $gate['id'], 'features.toggle', $key);
        \App\CachePurge::run($this->app, $this->db);
        return \App\Saved::withUndo('/features', Features::on($key) ? 'turned_on' : 'turned_off', '/features/toggle/' . $key, $this->session->csrfToken());
    }
}
