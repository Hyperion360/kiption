<?php // app/src/Controllers/NewsController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\NewsRepository;

/** Archive news: an anonymous-cacheable index and item view (budget-1 each,
 *  markdown bodies at rest) plus the member comment POST and the admin-only
 *  forms. URLs are action-shaped per the standing convention: /news,
 *  /news/view/{id}, /news/comment/{id}; a two-segment /news/{id} would
 *  dispatch a nonexistent action and 404 (the Task 3 /page ruling). */
final class NewsController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    public function index(): string
    {
        [$perPage, $offset] = $this->paginate();
        [$items, $page] = [(new NewsRepository($this->db))->listing($perPage, $offset), $this->page()];
        return $this->view->render('news/index', [
            'title' => 'News',
            'head' => $this->head()->withTitle('News')->withCanonical('/news')
                ->withDescription('Archive announcements and site news.'),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'items' => $items,
            'page' => $page,
            'baseUrl' => '/news',
        ]);
    }

    public function view(string $id): Response|string
    {
        if (!preg_match('#^[1-9][0-9]{0,8}$#', $id)) return new Response('Page not found', 404); // mirrors the cache whitelist
        $repo = new NewsRepository($this->db);
        [$perPage, $offset] = $repo->commentWindow();
        $fold = $repo->withComments((int) $id, $perPage, $offset);
        if ($fold === null) return new Response('Page not found', 404);
        $loggedIn = $this->request->cookies !== [] && (int) ($this->session->get('user_id') ?? 0) !== 0;
        $head = $this->head()->withTitle($fold['news']['title'])
            ->withDescription($this->description($fold['news']['body']))
            ->withCanonical('/news/view/' . (int) $fold['news']['id'])
            ->withArticle($fold['news']['published_at'], $fold['news']['published_at']);
        return $this->view->render('news/view', [
            'title' => $fold['news']['title'],
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'news' => $fold['news'],
            'comments' => $fold['comments'],
            'comment_count' => $fold['comment_count'],
            'loggedIn' => $loggedIn,
            'csrf' => $loggedIn ? $this->session->csrfToken() : null, // never start a session for a cookieless guest
        ]);
    }

    /** Member-only comment (no guest news comments; the reviews' guest surface
     *  stays story-scoped). Kernel ordering: a tokenless POST draws the CSRF
     *  403 before the auth redirect. */
    #[AuthAttr] #[Post]
    public function comment(string $id): Response
    {
        if (!preg_match('#^[1-9][0-9]{0,8}$#', $id)) return new Response('Page not found', 404);
        $repo = new NewsRepository($this->db);
        if ($repo->find((int) $id) === null) return new Response('Page not found', 404); // existence, not the throttle: no check-then-act on the guard
        $body = trim($this->request->postStr('body'));
        if ($body === '' || strlen($body) > 5000) {
            return new Response('Comment text is required (max 5000 characters).', 422);
        }
        if (!$repo->addComment((int) $id, (int) ($this->session->get('user_id') ?? 0), $body)) {
            return new Response('You already commented on this post within the last hour.', 429);
        }
        // The comment moves both cached surfaces: the item's list and the
        // index's comment-count scalar.
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeNews((int) $id);
        return Response::redirect('/news/view/' . (int) $id . '#comments');
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
        [$title, $body, $error] = $this->titleBody();
        if ($error !== null) return new Response($this->form(null, $error), 422);
        $this->db->query('INSERT INTO news (author_id, title, body) VALUES (?, ?, ?)',
            [(int) ($this->session->get('user_id') ?? 0), $title, $body]);
        $id = (int) $this->db->lastInsertId();
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeNews($id);
        return Response::redirect('/news/view/' . $id);
    }

    #[AuthAttr]
    public function edit(string $id): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        if (!preg_match('#^[1-9][0-9]{0,8}$#', $id)) return new Response('Page not found', 404);
        $row = (new NewsRepository($this->db))->find((int) $id);
        if ($row === null) return new Response('Page not found', 404);
        return $this->form($row);
    }

    #[AuthAttr] #[Post]
    public function update(string $id): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        if (!preg_match('#^[1-9][0-9]{0,8}$#', $id)) return new Response('Page not found', 404);
        $row = (new NewsRepository($this->db))->find((int) $id);
        if ($row === null) return new Response('Page not found', 404);
        [$title, $body, $error] = $this->titleBody();
        if ($error !== null) return new Response($this->form($row, $error), 422);
        // published_at stays put: no re-dating on edit (no scheduled publishing either)
        $this->db->query('UPDATE news SET title = ?, body = ? WHERE id = ?', [$title, $body, (int) $id]);
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeNews((int) $id);
        return Response::redirect('/news/view/' . (int) $id);
    }

    /** Title 1-255; the body is markdown at rest and must say something (an
     *  empty post has no reader shape, unlike a stub page). */
    private function titleBody(): array
    {
        $title = trim($this->request->postStr('title'));
        $body = trim($this->request->postStr('body'));
        if ($title === '' || mb_strlen($title) > 255) return [$title, $body, 'Title must be 1 to 255 characters.'];
        if ($body === '') return [$title, $body, 'Body is required.'];
        return [$title, $body, null];
    }

    private function admin(): bool
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$me])['c'] === 1;
    }

    /** $row null renders the create form; the edit row prefills and targets
     *  update (the PageController form idiom). */
    private function form(?array $row, ?string $error = null): string
    {
        $title = $row === null ? 'New post' : 'Edit post';
        return $this->view->render('news/form', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'row' => $row, 'error' => $error,
            'csrf' => $this->session->csrfToken(), 'loggedIn' => true,
        ]);
    }

    /** Meta description source: the rendered body with tags stripped (the
     *  PageController idiom); Head truncates and falls back to the site
     *  default when empty. */
    private function description(string $body): string
    {
        $text = trim(html_entity_decode(strip_tags(\App\Markdown::render($body)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }

    /** Coerced page param: junk, zero and negatives become page 1. */
    private function page(): int
    {
        return max(1, (int) ($this->request->get['page'] ?? 1));
    }

    /** Overflow guard (the BrowseController idiom): a hostile page value must
     *  not push the offset past int range into a repository TypeError. */
    private function paginate(): array
    {
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        $page = min($this->page(), intdiv(PHP_INT_MAX, $perPage));
        return [$perPage, ($page - 1) * $perPage];
    }
}
