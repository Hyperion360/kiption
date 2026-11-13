<?php // app/src/AdminScreen.php
namespace App;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

/** The shared shell of the owner-only admin screens (the taxonomy managers,
 *  word filter, signup question, profile fields, pages list, activity log,
 *  mass mail and the manage menu): the admin gate, the page render with the
 *  layout's standard data, and the one coarse cache purge taxonomy writes
 *  end with. Helpers are protected, so the router sees only each screen's
 *  actions. */
abstract class AdminScreen
{
    public function __construct(
        protected View $view, protected Request $request, protected Database $db,
        protected Session $session, protected App $app,
    ) {}

    /** A 403 Response for anyone but a live-session admin, else null. */
    protected function deny(): ?Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        return $gate instanceof Response ? $gate : null;
    }

    /** @param array<string,mixed> $data */
    protected function page(string $view, string $titleKey, string $canonical, array $data = []): string
    {
        $title = Lang::t($titleKey);
        $head = $this->head()->withTitle($title)->withNoindex();
        return $this->view->render($view, $data + [
            'title' => $title,
            'head' => $canonical === '' ? $head : $head->withCanonical($canonical),
            'theme' => Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'csrf' => $this->session->csrfToken(),
            'error' => null,
            'loggedIn' => true,
            'isAdmin' => true, // deny() already proved it
        ]);
    }

    /** Category, rating and tag names print on story pages, browse lists and
     *  search, so any write refreshes the whole static page cache. These are
     *  rare owner actions; lazy re-caching absorbs the cost. */
    protected function purge(): void
    {
        StaticCache\Cache::configured($this->app)->purgeAll();
    }

    /** Run $work in one transaction: commit when it returns, roll back and
     *  rethrow when it throws. */
    protected function atomically(callable $work): void
    {
        $this->db->begin();
        try {
            $work();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Swap row $id with its neighbour in $ids (display order) toward $dir
     *  ('up' or 'down'), then renumber the whole list 1..n, because freshly
     *  seeded rows can all share one position. $table is a literal from the
     *  caller, never input. */
    protected function swapPosition(string $table, array $ids, int $id, string $dir): void
    {
        $at = array_search($id, $ids, true);
        $to = $dir === 'up' ? $at - 1 : $at + 1;
        if (isset($ids[$to])) [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->atomically(function () use ($table, $ids): void {
            foreach ($ids as $i => $rowId) $this->db->query("UPDATE {$table} SET position = ? WHERE id = ?", [$i + 1, $rowId]);
        });
    }

    protected function post(string $key): string
    {
        return trim((string) ($this->request->post[$key] ?? ''));
    }

    private function head(): Seo\Head
    {
        return Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }
}
