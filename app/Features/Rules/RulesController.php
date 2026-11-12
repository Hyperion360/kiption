<?php // app/Features/Rules/RulesController.php
namespace App\Features\Rules;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

/** /rules: the owner's Markdown message of the same name, or a 404 while it is empty. */
final class RulesController
{
    public function __construct(private View $view, private Request $request, private App $app, private Session $session, private Database $db) {}

    public function index(): Response|string
    {
        return \App\NoticePage::render($this->view, $this->request, $this->app, \App\Viewer::id($this->request, $this->session, $this->db) !== 0, 'msg.rules', 'foot.rules', '/rules');
    }
}
