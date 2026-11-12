<?php // app/Features/Help/HelpController.php
namespace App\Features\Help;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

/** /help: the owner's Markdown message of the same name, or a 404 while it is empty. */
final class HelpController
{
    public function __construct(private View $view, private Request $request, private App $app, private Session $session, private Database $db) {}

    public function index(): Response|string
    {
        return \App\NoticePage::render($this->view, $this->request, $this->app, \App\Viewer::id($this->request, $this->session, $this->db) !== 0, 'msg.help', 'foot.help', '/help');
    }
}
