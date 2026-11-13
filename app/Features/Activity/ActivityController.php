<?php // app/Features/Activity/ActivityController.php
namespace App\Features\Activity;
use App\AdminScreen;
use Kip\Http\Response;

/** Read-only activity log for the archive owner (admins only, 403 otherwise). */
final class ActivityController extends AdminScreen
{
    public function index(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        $page = min(max(1, (int) ($this->request->get['page'] ?? 1)), intdiv(PHP_INT_MAX, $perPage));
        $rows = \App\ActivityLog::recent($this->db, $perPage, ($page - 1) * $perPage);
        $hasMore = \count($rows) > $perPage;
        return $this->page('activity/index', 'activity.heading', '/activity', [
            'rows' => \array_slice($rows, 0, $perPage), 'page' => $page, 'hasMore' => $hasMore,
        ]);
    }
}
