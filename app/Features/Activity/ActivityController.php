<?php // app/Features/Activity/ActivityController.php
namespace App\Features\Activity;
use App\Adminness;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

/** Read-only activity log for the archive owner (admins only, 403 otherwise). */
final class ActivityController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    public function index(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        $page = min(max(1, (int) ($this->request->get['page'] ?? 1)), intdiv(PHP_INT_MAX, $perPage));
        $rows = \App\ActivityLog::recent($this->db, $perPage, ($page - 1) * $perPage);
        $hasMore = \count($rows) > $perPage;
        $title = \App\Lang::t('activity.heading');
        $head = \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        )->withTitle($title)->withCanonical('/activity')->withNoindex();
        return $this->view->render('activity/index', [
            'title' => $title, 'head' => $head, 'rows' => \array_slice($rows, 0, $perPage),
            'page' => $page, 'hasMore' => $hasMore, 'loggedIn' => true, 'isAdmin' => true,
        ]);
    }
}
