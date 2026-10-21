<?php // app/Features/Cloud/CloudController.php
namespace App\Features\Cloud;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

final class CloudController
{
    public function __construct(private View $view, private Session $session, private Request $request, private App $app, private Database $db) {}

    /** /cloud: the hosted-archive page (Page row 'cloud'), signup at
     *  cloud.kiption.com linked from the body. */
    public function index(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'cloud');
    }
}
