<?php // app/Features/Notifications/NotificationsController.php
namespace App\Features\Notifications;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Notifications;

final class NotificationsController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): string
    {
        $rows = (new Notifications($this->db))->inboxRows((int) $this->session->get('user_id'));
        return $this->view->render('notifications/index', [
            'title' => \App\Lang::t('notifications.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('notifications.heading'))->withCanonical('/notifications')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $rows,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
        ]);
    }

    #[AuthAttr] #[Post]
    public function read(): Response
    {
        (new Notifications($this->db))->markAllRead((int) $this->session->get('user_id'));
        return Response::redirect('/notifications');
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
