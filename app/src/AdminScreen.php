<?php // app/src/AdminScreen.php
namespace App;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

/** The shared shell of the owner-only taxonomy managers (categories, ratings,
 *  tags, characters): the admin gate, the page render with the layout's
 *  standard data, and the one coarse cache purge every write ends with.
 *  Helpers are protected, so the router sees only each manager's actions. */
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
        return $this->view->render($view, $data + [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical($canonical)->withNoindex(),
            'theme' => Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'csrf' => $this->session->csrfToken(),
            'error' => null,
            'loggedIn' => true,
        ]);
    }

    /** Category, rating and tag names print on story pages, browse lists and
     *  search, so any write refreshes the whole static page cache. These are
     *  rare owner actions; lazy re-caching absorbs the cost. */
    protected function purge(): void
    {
        StaticCache\Cache::configured($this->app)->purgeAll();
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
