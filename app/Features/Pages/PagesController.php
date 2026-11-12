<?php // app/Features/Pages/PagesController.php
namespace App\Features\Pages;
use App\Adminness;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\Auth as AuthAttr;

/** Admin list of every page, with links to edit, delete and add. */
final class PagesController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function manage(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $title = \App\Lang::t('pages.heading');
        return $this->view->render('pages/manage', [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $this->db->all('SELECT slug, title, updated_at FROM pages ORDER BY slug'),
            'protected' => \App\Features\Page\PageController::PROTECTED,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true,
        ]);
    }
}
