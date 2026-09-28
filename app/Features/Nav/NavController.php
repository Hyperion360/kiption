<?php // app/Features/Nav/NavController.php
namespace App\Features\Nav;
use App\NavLinks;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** Admin management of the dynamic nav. Every successful write rebuilds the
 *  JSON artifact the layout reads, so a link goes live (or drops out) on the
 *  next request; the artifact, never the table, is what pages render. Admin-
 *  only by the SQL role check (the SeriesRepository viewerIsAdmin idiom): the
 *  nav is site-wide chrome, not moderator territory. */
final class NavController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    /** All links incl. hidden ones: hiding removes a link from the artifact,
     *  never from the operator's own list. */
    #[AuthAttr]
    public function index(): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        return $this->form(null);
    }

    #[AuthAttr]
    public function new(): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        return $this->form(null);
    }

    #[AuthAttr]
    public function edit(string $id): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $row = $this->find($id);
        if ($row === null) return new Response('Page not found', 404);
        return $this->form($row);
    }

    #[AuthAttr] #[Post]
    public function create(): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        [$label, $url, $error] = $this->input();
        if ($error !== null) return new Response($this->form(null, $error), 422);
        $this->db->query('INSERT INTO nav_links (label, url, position, is_hidden) VALUES (?,?,?,?)',
            [$label, $url, $this->position(), $this->hidden()]);
        $this->rebuild();
        return Response::redirect('/nav');
    }

    #[AuthAttr] #[Post]
    public function update(string $id): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $row = $this->find($id);
        if ($row === null) return new Response('Page not found', 404);
        [$label, $url, $error] = $this->input();
        if ($error !== null) return new Response($this->form($row, $error), 422);
        $this->db->query('UPDATE nav_links SET label = ?, url = ?, position = ?, is_hidden = ? WHERE id = ?',
            [$label, $url, $this->position(), $this->hidden(), (int) $id]);
        $this->rebuild();
        return Response::redirect('/nav');
    }

    #[AuthAttr] #[Post]
    public function delete(string $id): Response
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        if ($this->db->query('DELETE FROM nav_links WHERE id = ?', [(int) $id])->rowCount() === 0) {
            return new Response('Page not found', 404);
        }
        $this->rebuild();
        return Response::redirect('/nav');
    }

    /** label 1-40, url internal-only (the router's own path shape: segments of
     *  [a-z0-9_-], single slashes): no javascript:, no external hosts, and no
     *  scheme-relative //host either (the QA 10a probe: //evil passed the old
     *  one-slash-free class and rendered as an external href). */
    private function input(): array
    {
        $label = trim($this->request->postStr('label'));
        $url = trim($this->request->postStr('url'));
        if ($label === '' || mb_strlen($label) > 40) {
            return [$label, $url, 'Label must be 1 to 40 characters.'];
        }
        if (!preg_match('#^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$#', $url)) {
            return [$label, $url, 'URL must be an internal path like /page/about.'];
        }
        return [$label, $url, null];
    }

    private function position(): int
    {
        return (int) $this->request->postStr('position'); // junk coerces to 0, the page-param philosophy
    }

    private function hidden(): int
    {
        return $this->request->postStr('is_hidden') === '1' ? 1 : 0;
    }

    private function find(string $id): ?array
    {
        if (!ctype_digit($id)) return null;
        $row = $this->db->one('SELECT id, label, url, position, is_hidden FROM nav_links WHERE id = ?', [(int) $id]);
        return $row === null ? null : [
            'id' => (int) $row['id'], 'label' => (string) $row['label'], 'url' => (string) $row['url'],
            'position' => (int) $row['position'], 'is_hidden' => (int) $row['is_hidden'],
        ];
    }

    private function admin(): bool
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$me])['c'] === 1;
    }

    private function rebuild(): void
    {
        NavLinks::rebuild($this->db, (string) $this->app->config('nav_file', ''));
        // Chrome rides EVERY page, so a nav write invalidates the whole static
        // layer and the framework page cache (the Builder::prune idiom), not
        // just the artifact: without this, pages cached before the write serve
        // the old menu indefinitely (the QA 10a live-smoke finding). The dirs
        // come from config so the KIP_STATIC_CACHE_DIR override (tests,
        // imports, alternate deploys) purges the layer actually in front.
        $cacheDir = (string) (($this->app->config('static_cache', []) ?? [])['dir'] ?? dirname(__DIR__, 3) . '/public/cache');
        \App\StaticCache\Builder::prune($cacheDir, (string) $this->app->config('app_dir', dirname(__DIR__, 2)));
    }

    /** $row null renders the create form; the edit row prefills and targets
     *  update. The plan's nav surface is one management page: the listing
     *  rides the same view. */
    private function form(?array $row, ?string $error = null): string
    {
        $title = $row === null ? \App\Lang::t('navlinks.heading') : \App\Lang::t('navlinks.edit_title');
        return $this->view->render('nav/form', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'rows' => $this->db->all('SELECT id, label, url, position, is_hidden FROM nav_links ORDER BY position, id'),
            'row' => $row, 'error' => $error,
            'csrf' => $this->session->csrfToken(), 'loggedIn' => true,
        ]);
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
