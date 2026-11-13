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
        $who = mb_substr(trim((string) ($this->request->get['who'] ?? '')), 0, 60);
        $kind = (string) ($this->request->get['kind'] ?? '');
        if (!isset(\App\ActivityLog::KINDS[$kind])) $kind = '';
        $rows = \App\ActivityLog::recent($this->db, $perPage, ($page - 1) * $perPage, $who, $kind);
        $hasMore = \count($rows) > $perPage;
        $rows = \array_slice($rows, 0, $perPage);
        return $this->page('activity/index', 'activity.heading', '/activity', [
            'days' => \App\ActivityLog::byDay($rows), 'page' => $page, 'hasMore' => $hasMore,
            'who' => $who, 'kind' => $kind, 'kinds' => array_keys(\App\ActivityLog::KINDS),
            'filter' => http_build_query(array_filter(['who' => $who, 'kind' => $kind], static fn (string $v): bool => $v !== '')),
        ]);
    }
}
