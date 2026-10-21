<?php // app/Features/Dmca/DmcaController.php
namespace App\Features\Dmca;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

final class DmcaController
{
    public function __construct(private View $view, private Session $session, private Request $request, private App $app, private Database $db) {}

    /** /dmca: the takedown page (Page row 'dmca', seeded by migration 037).
     *  One canonical URL: /page/view/dmca 301s here. */
    public function index(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'dmca');
    }
}
