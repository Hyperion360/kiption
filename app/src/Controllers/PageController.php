<?php // app/src/Controllers/PageController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** Public custom pages (markdown at rest) plus the admin forms that write
 *  them. The reader surface is a budget-1, anonymous-cacheable page at
 *  /page/view/{slug}: one SELECT, 404 on miss or junk slug. The slug is the
 *  primary key and is immutable after create, so a page's URL (and its cache
 *  file) can never move out from under a link. Admin-only by the SQL role
 *  check (the NavController idiom): pages are site chrome, not moderator
 *  territory. */
final class PageController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    public function view(string $slug): Response|string
    {
        $row = $this->find($slug);
        if ($row === null) return new Response('Page not found', 404);
        $body = $row['body'];
        $head = $this->head()->withTitle($row['title'])
            ->withDescription($this->description($body))
            ->withCanonical('/page/view/' . $slug);
        $data = [
            'title' => $row['title'],
            'head' => $body === '' ? $head->withNoindex() : $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'page' => $row,
            'loggedIn' => $this->request->cookies !== [] && (int) ($this->session->get('user_id') ?? 0) !== 0,
        ];
        // An empty body is the empty-category precedent: meta noindex plus the
        // X-Robots-Tag header, which also keeps it out of the static layer.
        return $body === ''
            ? (new Response($this->view->render('page/view', $data), 200))->withHeader('X-Robots-Tag', 'noindex')
            : $this->view->render('page/view', $data);
    }

    #[AuthAttr]
    public function new(): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        return $this->form(null);
    }

    #[AuthAttr] #[Post]
    public function create(): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        [$slug, $title, $body, $error] = $this->createInput();
        if ($error !== null) return new Response($this->form(null, $error), 422);
        $this->db->query('INSERT INTO pages (slug, title, body) VALUES (?, ?, ?)', [$slug, $title, $body]);
        return Response::redirect('/page/view/' . $slug);
    }

    #[AuthAttr]
    public function edit(string $slug): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $row = $this->find($slug);
        if ($row === null) return new Response('Page not found', 404);
        return $this->form($row);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $row = $this->find($slug);
        if ($row === null) return new Response('Page not found', 404);
        [$title, $body, $error] = $this->titleBody(); // the posted slug is ignored: immutable after create
        if ($error !== null) return new Response($this->form($row, $error), 422);
        $this->db->query("UPDATE pages SET title = ?, body = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE slug = ?",
            [$title, $body, $slug]);
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgePage($slug);
        return Response::redirect('/page/view/' . $slug);
    }

    /** slug ^[a-z0-9-]+$ and unique, title 1-255; the body is free-form
     *  markdown and may be empty (the noindex shape). */
    private function createInput(): array
    {
        $slug = trim($this->request->postStr('slug'));
        [$title, $body, $error] = $this->titleBody();
        if ($error !== null) return [$slug, $title, $body, $error];
        if (!preg_match('#^[a-z0-9-]+$#', $slug)) {
            return [$slug, $title, $body, 'Slug may only contain lowercase letters, digits, and hyphens.'];
        }
        if ((int) $this->db->one('SELECT COUNT(*) c FROM pages WHERE slug = ?', [$slug])['c'] > 0) {
            return [$slug, $title, $body, 'That slug is already in use.'];
        }
        return [$slug, $title, $body, null];
    }

    private function titleBody(): array
    {
        $title = trim($this->request->postStr('title'));
        $body = trim($this->request->postStr('body'));
        if ($title === '' || mb_strlen($title) > 255) {
            return [$title, $body, 'Title must be 1 to 255 characters.'];
        }
        return [$title, $body, null];
    }

    private function find(string $slug): ?array
    {
        if (!preg_match('#^[a-z0-9-]+$#', $slug)) return null;
        $row = $this->db->one('SELECT slug, title, body, updated_at FROM pages WHERE slug = ?', [$slug]);
        return $row === null ? null : [
            'slug' => (string) $row['slug'], 'title' => (string) $row['title'],
            'body' => (string) $row['body'], 'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** Meta description source: the rendered body with tags stripped and
     *  entities restored (the HtmlToMarkdown idiom); Head truncates to 160
     *  characters and falls back to the site default when this is empty. */
    private function description(string $body): string
    {
        $text = trim(html_entity_decode(strip_tags(\App\Markdown::render($body)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private function admin(): bool
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$me])['c'] === 1;
    }

    /** $row null renders the create form; the edit row prefills, targets
     *  update, and renders the slug read-only (immutable after create). */
    private function form(?array $row, ?string $error = null): string
    {
        $title = $row === null ? 'New page' : 'Edit page';
        return $this->view->render('page/form', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
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
