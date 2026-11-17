<?php // app/Features/Cloud/CloudController.php
namespace App\Features\Cloud;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

final class CloudController
{
    public function __construct(private View $view, private Session $session, private Request $request, private App $app, private Database $db) {}

    /** /cloud: the hosted-archive page (Page row 'cloud', seeded once by
     *  migration 072, editable and deletable like any page). */
    public function index(): Response|string
    {
        // Two tiers. A 'landing' array in cloud_marketing (the gitignored
        // flagship config) renders the full designed landing; every other
        // install gets the simplified page: the row's text plus one link.
        $m = $this->app->config('cloud_marketing', []);
        $template = is_array($m) && !empty($m['landing']) ? 'page/cloud_landing' : 'page/cloud';
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'cloud', $template);
    }
}
