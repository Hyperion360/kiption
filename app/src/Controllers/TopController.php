<?php // app/src/Controllers/TopController.php
namespace App\Controllers;
use Kip\{App, Http\Request, View};
use App\Repositories\ToplistRepository;

final class TopController
{
    public function __construct(
        private View $view,
        private Request $request,
        private App $app,
        private ToplistRepository $toplists,
    ) {}

    /** GET /top: the toplists hub, all four sections riding the repository's
     *  ONE statement (budget 1). Anonymous by construction: no session touch,
     *  guest gates only, restricted works excluded outright - so the page
     *  fills the static cache behind the whitelist, and every engagement or
     *  visibility write purges it through purgeStory's unconditional /top
     *  unlink. No JSON-LD ItemList per section (YAGNI, ruled in the plan):
     *  four lists on one page dilute structured-data value. */
    public function index(): \Kip\Http\Response|string
    {
        if (($r = \App\Features::guard('toplists')) !== null) return $r;
        return $this->view->render('top/index', [
            'title' => \App\Lang::t('top.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('top.heading'))->withCanonical('/top'),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'sections' => $this->toplists->hub(),
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
