<?php // app/src/Controllers/SeriesController.php
namespace App\Controllers;
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

    public function view(string $slug): Response|string
    {
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $page = $this->series->seriesPage($slug, $me);
        if ($page === null) return new Response('Page not found', 404);
        $s = $page['series'];
        $isOwner = $me !== 0 && $me === $s['owner_id'];   // folded into the one query
        $isAdmin = $s['is_admin'] === 1;                  // (plan review findings 9+12: no
        $items = $page['items'];                          // Adminness call, no second query)
        $head = $this->head()->withTitle($s['title'])
            ->withDescription($s['summary'] !== '' ? $s['summary'] : 'A series by ' . $s['owner_penname'] . '.')
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
            'series' => $s, 'items' => $items,
            'isOwner' => $isOwner, 'isAdmin' => $isAdmin, 'me' => $me,
            'csrf' => $me !== 0 ? $this->session->csrfToken() : '',
            'loggedIn' => $me !== 0,
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
