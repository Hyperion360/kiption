<?php // app/Features/Cloud/CloudController.php
namespace App\Features\Cloud;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

final class CloudController
{
    public function __construct(private View $view, private Session $session, private Request $request, private App $app, private Database $db) {}

    /** /cloud: the hosted-archive page (Page row 'cloud'), signup at
     *  cloud.kiption.com linked from the body. Renders the page/cloud
     *  template: hero, plan cards, body, and close band, all copy in the
     *  page row and the cloud_marketing config. */
    public function index(): Response|string
    {
        // A 'landing' array in cloud_marketing switches to the full landing
        // template; without it the page keeps the hero + plans + body shape.
        $m = $this->app->config('cloud_marketing', []);
        $template = is_array($m) && !empty($m['landing']) ? 'page/cloud_landing' : 'page/cloud';
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'cloud', $template);
    }
}
