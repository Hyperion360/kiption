<?php // app/src/Controllers/CoauthorController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Mailer, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Notifications;
use App\Repositories\AuthoringRepository;

final class CoauthorController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app, private Mailer $mailer,
    ) {}

    #[AuthAttr] #[Post]
    public function add(string $slug): Response|string
    {
        $me = (int) $this->session->get('user_id');
        try {
            $newUserId = $this->authoring()->addCoauthor($slug, trim($this->request->postStr('penname')), $me);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'not found') return new Response('Page not found', 404);
            $form = $this->storyForm($slug, $me, $e->getMessage()); // 422 renders inline in the form's error slot
            return new Response($form ?? 'Page not found', $form === null ? 404 : 422);
        }
        $story = $this->db->one('SELECT id, title, author_id FROM stories WHERE slug = ?', [$slug]);
        $coauthor = $this->db->one('SELECT email, penname, profile_slug FROM users WHERE id = ?', [$newUserId]);
        (new Notifications($this->db))->create($newUserId, 'coauthor', (int) $story['id'], $me, (string) $story['title']);
        $base = rtrim((string) $this->app->config('base_url', ''), '/');
        try {
            $this->mailer->send((string) $coauthor['email'], 'You were added as a coauthor on ' . $story['title'],
                "You were added as a coauthor on \"{$story['title']}\".\n\nView and manage the story:\n{$base}/story/view/{$slug}");
        } catch (\Throwable $e) {
            // the add already happened: mail is best-effort, never blocks the redirect
            error_log("Coauthor mail failed: {$e->getMessage()}");
        }
        $this->purges($slug, (int) $story['id'], (int) $story['author_id'], (string) $coauthor['profile_slug']);
        return Response::redirect('/story/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function remove(string $slug, int $id): Response
    {
        $me = (int) $this->session->get('user_id');
        if (!$this->authoring()->removeCoauthor($slug, $id, $me)) return new Response('Page not found', 404);
        $story = $this->db->one('SELECT id, author_id FROM stories WHERE slug = ?', [$slug]);
        $affected = $this->db->one('SELECT profile_slug FROM users WHERE id = ?', [$id]);
        $this->purges($slug, (int) $story['id'], (int) $story['author_id'], (string) ($affected['profile_slug'] ?? ''));
        return Response::redirect('/story/view/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function leave(string $slug): Response
    {
        $me = (int) $this->session->get('user_id');
        if (!$this->authoring()->removeCoauthor($slug, $me, $me)) return new Response('Page not found', 404);
        $story = $this->db->one('SELECT id, author_id FROM stories WHERE slug = ?', [$slug]);
        $leaver = $this->db->one('SELECT profile_slug FROM users WHERE id = ?', [$me]);
        $this->purges($slug, (int) $story['id'], (int) $story['author_id'], (string) ($leaver['profile_slug'] ?? ''));
        return Response::redirect('/story/view/' . $slug);
    }

    /** Every op touches the byline and both profile story lists: purge the
     *  story (with its series + author coordinates) and the affected
     *  coauthor's profile pages. */
    private function purges(string $slug, int $storyId, int $authorId, string $coauthorSlug): void
    {
        [$seriesSlugs, $authorSlug] = $this->authoring()->purgeData($storyId, $authorId);
        $cache = new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache');
        $cache->purgeStory($slug, [], $seriesSlugs, $authorSlug);
        if ($coauthorSlug !== '') $cache->purgeUser($coauthorSlug);
    }

    /** 422 re-render of the story edit form with the coauthor error in the
     *  form's own error slot. Mirrors StoryController::renderForm (the
     *  per-controller form idiom); null when the gate rejects the viewer. */
    private function storyForm(string $slug, int $me, string $error): ?string
    {
        $rows = $this->authoring()->formData($slug, $me);
        $story = null;
        $categories = [];
        $ratings = [];
        $coauthors = [];
        foreach ($rows as $r) {
            if ($r['k'] === 's') $story = $r;
            elseif ($r['k'] === 'cat') $categories[] = $r;
            elseif ($r['k'] === 'r') $ratings[] = $r;
            elseif ($r['k'] === 'co') $coauthors[] = ['id' => (int) $r['a'], 'penname' => (string) $r['b']];
        }
        if ($story === null) return null;
        $chapters = ($story['h'] ?? null) !== null && $story['h'] !== '[]' ? json_decode((string) $story['h'], true) ?: [] : [];
        usort($chapters, static fn(array $x, array $y): int => (int) $x['position'] <=> (int) $y['position']);
        return $this->view->render('story/form', [
            'title' => 'Edit story',
            'head' => $this->head()->withTitle('Edit story')->withCanonical('/story/edit/' . $slug)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => '/story/edit/' . $slug,
            'story' => [
                'slug' => $slug, 'title' => $story['b'], 'summary' => $story['c'],
                'notes' => $story['d'], 'rating_id' => $story['f'], 'completed' => $story['g'],
                'restricted' => (int) $story['i'],
                'language' => (string) $story['j'],
            ],
            'selectedCategories' => array_filter(explode(',', (string) ($story['e'] ?? '')), 'strlen'),
            'categories' => $categories,
            'ratings' => $ratings,
            'chapters' => $chapters,
            'coauthors' => $coauthors,
            'canManageCoauthors' => $me === (int) $story['l'] || (int) $story['m'] === 1,
            'csrf' => $this->session->csrfToken(),
            'error' => $error,
            'loggedIn' => true,
        ]);
    }

    private function authoring(): AuthoringRepository
    {
        return new AuthoringRepository($this->db);
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
