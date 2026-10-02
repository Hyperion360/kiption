<?php // app/Features/Series/SeriesController.php
namespace App\Features\Series;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\SeriesRepository;

final class SeriesController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Session $session,
        private App $app,
        private Database $db,
        private SeriesRepository $series,
    ) {}

    /** The /series index (comp nav surface). ALWAYS-ON by design: series has
     *  NO feature flag (no 'series' name exists in the flags ledger), so this
     *  action deliberately carries no Features::guard, and the page renders
     *  200 for every viewer on every archive. Guest-rendered and paged like
     *  /browse/recent: the ?page param coerces by the max(1,(int)) rule and a
     *  page past the end is an empty list, still 200. */
    public function index(): string
    {
        $page = max(1, (int) ($this->request->get['page'] ?? 1)); // the junk page-param coercion
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        // Overflow guard: a hostile page=99999999999999999999 must not make
        // the offset a float (the BrowseController::paginate rule).
        $page = min($page, intdiv(PHP_INT_MAX, $perPage));
        // The cookie-gated $me idiom (plan review finding 13): never a bare
        // session read, or the cookieless cacheable path starts a session.
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $seriesRows = $this->series->indexPage($perPage, ($page - 1) * $perPage);
        return $this->view->render('series/index', [
            'title' => \App\Lang::t('series.index_heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('series.index_heading'))->withCanonical($this->request->path),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'loggedIn' => $me !== 0,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'baseUrl' => '/series',
            'series' => $seriesRows,
            'hasOlder' => count($seriesRows) === $perPage,
            'page' => $page,
        ]);
    }

    public function view(string $slug): Response|string
    {
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        // The mute gate (finding 9): the item branch filters only while the
        // flag is on; $me itself keeps driving the visibility gates.
        $page = $this->series->seriesPage($slug, $me, \App\Features::on('mute') ? $me : 0);
        if ($page === null) return new Response('Page not found', 404);
        $s = $page['series'];
        $isOwner = $me !== 0 && $me === $s['owner_id'];   // folded into the one query
        $isAdmin = $s['is_admin'] === 1;                  // (plan review findings 9+12: no
        $items = $page['items'];                          // Adminness call, no second query)
        $head = $this->head()->withTitle($s['title'])
            ->withDescription($s['summary'] !== '' ? $s['summary'] : \App\Lang::t('series.meta_by', ['name' => $s['owner_penname']]))
            ->withCanonical('/series/view/' . $slug);
        $jsonLd = [];
        foreach ($items as $i => $it) {
            // absolute urls: Head.php's own rule for JSON-LD, the bookJsonLd precedent
            if ($it['confirmed'] === 1) $jsonLd[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => $head->url('/story/view/' . $it['slug']), 'name' => $it['title']];
        }
        $head = $head->withJsonLd(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $s['title'],
            'itemListElement' => $jsonLd]);
        return $this->view->render('series/view', [
            'title' => $s['title'],
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'series' => $s, 'items' => $items,
            'isOwner' => $isOwner, 'isAdmin' => $isAdmin, 'me' => $me,
            'csrf' => $me !== 0 ? $this->session->csrfToken() : '',
            'loggedIn' => $me !== 0,
        ]);
    }

    #[AuthAttr]
    public function new(): string
    {
        return $this->form(null);
    }

    #[AuthAttr] #[Post]
    public function create(): Response|string
    {
        [$title, $summary, $membership, $error] = $this->seriesInput();
        if ($error !== null) return new Response($this->form(null, $error), 422);
        $me = (int) $this->session->get('user_id');
        $slug = $this->series->create($me, $title, $summary, $membership);
        return Response::redirect('/series/view/' . $slug);
    }

    #[AuthAttr]
    public function edit(string $slug): Response|string
    {
        try { $row = $this->series->forEdit($slug, (int) $this->session->get('user_id')); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        return $this->form($row);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug): Response|string
    {
        $me = (int) $this->session->get('user_id');
        [$title, $summary, $membership, $error] = $this->seriesInput();
        if ($error !== null) return new Response($this->form(null, $error), 422);
        try { $this->series->update($slug, $title, $summary, $membership, $me, $this->isAdmin($me)); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        $this->staticCache()->purgeSeries($slug);
        return Response::redirect('/series/view/' . $slug);
    }

    /** title 1-120, summary <= 2000, membership in the enum; junk membership 422s
     *  like the story form's enum fields. */
    private function seriesInput(): array
    {
        $title = trim($this->request->postStr('title'));
        $summary = substr(trim($this->request->postStr('summary')), 0, 2000);
        $membership = $this->request->postStr('membership');
        if ($title === '' || mb_strlen($title) > 120) {
            return [$title, $summary, $membership, 'Title must be 1 to 120 characters.'];
        }
        if (!in_array($membership, ['open', 'moderated', 'closed'], true)) {
            return [$title, $summary, $membership, 'Membership must be open, moderated, or closed.'];
        }
        return [$title, $summary, $membership, null];
    }

    /** $row null renders the create form; the edit row prefills and targets update. */
    private function form(?array $row, ?string $error = null): string
    {
        $title = $row === null ? \App\Lang::t('series.new') : \App\Lang::t('series.edit');
        return $this->view->render('series/form', [
            'title' => $title, 'head' => $this->head()->withTitle($title)->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'row' => $row, 'error' => $error, 'csrf' => $this->session->csrfToken(), 'loggedIn' => true,
        ]);
    }

    #[AuthAttr] #[Post]
    public function add(string $slug): Response
    {
        $me = (int) $this->session->get('user_id');
        $storySlug = trim($this->request->postStr('story_slug'));
        try {
            $result = $this->series->addItem($slug, $storySlug, $me, $this->isAdmin($me));
        } catch (\RuntimeException $e) {
            return new Response($e->getMessage() === 'not found' ? 'Page not found' : $e->getMessage(), $e->getMessage() === 'not found' ? 404 : 422);
        }
        if ($result === 'pending') $this->notifyOwner($slug, $me, $storySlug);
        if ($result === 'confirmed') {
            // same series_confirm notification to the story author as the confirm
            // action (coordinator ruling: the owner/admin re-add upgrade IS one)
            $story = $this->storyForNotify($storySlug);
            if ($story !== null && (int) $story['author_id'] !== $me) {
                (new \App\Notifications($this->db))->create((int) $story['author_id'], 'series_confirm', (int) $story['id'], $me, (string) $story['title']);
            }
        }
        $cache = $this->staticCache();
        $cache->purgeSeries($slug);
        $cache->purgeStory($storySlug, [], $this->series->seriesSlugsForStory($this->storyId($storySlug)), '');
        return Response::redirect('/series/view/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function remove(string $slug, string $storySlug): Response
    {
        $me = (int) $this->session->get('user_id');
        if (!$this->series->removeItem($slug, $storySlug, $me, $this->isAdmin($me))) {
            return new Response('Page not found', 404);
        }
        $cache = $this->staticCache();
        $cache->purgeSeries($slug);
        $cache->purgeStory($storySlug, [], $this->series->seriesSlugsForStory($this->storyId($storySlug)), '');
        return Response::redirect('/series/view/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function move(string $slug, string $itemId, string $dir): Response
    {
        $me = (int) $this->session->get('user_id');
        $dir = $dir === 'down' ? 'down' : 'up'; // junk coerces, the browse page-param philosophy
        try {
            $this->series->move($slug, (int) $itemId, $dir, $me, $this->isAdmin($me));
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $this->staticCache()->purgeSeries($slug); // positions live on the series page only
        return Response::redirect('/series/view/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function confirm(string $slug, string $itemId): Response
    {
        $me = (int) $this->session->get('user_id');
        try {
            $info = $this->series->confirm($slug, (int) $itemId, $me, $this->isAdmin($me));
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        if ($info !== null) {
            [$authorId, $title, $storySlug] = $info;
            if ($authorId !== $me) { // no self-congratulation (the KudosController idiom)
                (new \App\Notifications($this->db))->create($authorId, 'series_confirm', $this->storyId($storySlug), $me, $title);
            }
            $cache = $this->staticCache();
            $cache->purgeSeries($slug);
            $cache->purgeStory($storySlug, [], $this->series->seriesSlugsForStory($this->storyId($storySlug)), '');
        }
        return Response::redirect('/series/view/' . $slug);
    }

    /** The series owner gets one series_submit per pending submission; a pending
     *  result implies a non-owner actor, so no self-guard is needed here. */
    private function notifyOwner(string $slug, int $me, string $storySlug): void
    {
        $owner = $this->db->one('SELECT owner_id FROM series WHERE slug = ?', [$slug]);
        $story = $this->storyForNotify($storySlug);
        if ($owner === null || $story === null) return;
        (new \App\Notifications($this->db))->create((int) $owner['owner_id'], 'series_submit', (int) $story['id'], $me, (string) $story['title']);
    }

    private function storyForNotify(string $storySlug): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $storySlug)) return null;
        return $this->db->one('SELECT id, author_id, title FROM stories WHERE slug = ? AND deleted_at IS NULL', [$storySlug]);
    }

    private function storyId(string $storySlug): int
    {
        return (int) ($this->db->one('SELECT id FROM stories WHERE slug = ?', [$storySlug])['id'] ?? 0);
    }

    private function isAdmin(int $me): bool
    {
        return $this->series->viewerIsAdmin($me);
    }

    private function staticCache(): \App\StaticCache\Cache
    {
        return new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache');
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
