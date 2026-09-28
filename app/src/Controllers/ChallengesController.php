<?php // app/src/Controllers/ChallengesController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\ChallengesRepository;

/** The challenges module: public pages (index + the one-query view fold),
 *  member CRUD with prompt management, and story membership (join/confirm/
 *  remove), the SeriesController + ListsController mirrors (guard-first,
 *  #[AuthAttr] writes, ownership via the repository so a stranger's slug is
 *  a 404). */
final class ChallengesController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Session $session,
        private App $app,
        private Database $db,
        private ChallengesRepository $challenges,
    ) {}

    /** The public index: every challenge newest-first with visible item
     *  counts. An empty index is the noindex shape (belt and suspenders, the
     *  empty-category precedent: nothing worth caching or indexing). */
    public function index(): Response|string
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $rows = $this->challenges->indexRows($me);
        $title = \App\Lang::t('challenges.index');
        $data = [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/challenges'),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'rows' => $rows, 'loggedIn' => $me !== 0,
        ];
        return $rows === []
            ? (new Response($this->view->render('challenges/index', $data), 200))->withHeader('X-Robots-Tag', 'noindex')
            : $this->view->render('challenges/index', $data);
    }

    /** The public challenge page: the one-query fold for every viewer, the
     *  join form for members on open/moderated challenges, and the owner's
     *  confirm/remove surfaces on pending items. */
    public function view(string $slug): Response|string
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        // The mute gate (finding 9): the item branch filters only while the
        // flag is on; $me itself keeps driving the visibility gates.
        $page = $this->challenges->challengePage($slug, $me, \App\Features::on('mute') ? $me : 0);
        if ($page === null) return new Response('Page not found', 404);
        $c = $page['challenge'];
        $items = $page['items'];
        $isOwner = $me !== 0 && $me === $c['owner_id']; // folded into the one query
        $isAdmin = $c['is_admin'] === 1;
        $head = $this->head()->withTitle($c['title'])
            ->withDescription($c['summary'] !== '' ? $c['summary'] : \App\Lang::t('challenges.meta_by', ['name' => $c['owner_penname']]))
            ->withCanonical('/challenges/view/' . $slug);
        $data = [
            'title' => $c['title'],
            'head' => $items === [] ? $head->withNoindex() : $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'challenge' => $c, 'items' => $items,
            'isOwner' => $isOwner, 'isAdmin' => $isAdmin, 'me' => $me,
            'csrf' => $me !== 0 ? $this->session->csrfToken() : '',
            'loggedIn' => $me !== 0,
        ];
        // An itemless challenge has nothing to rank (the empty-category
        // precedent): meta tag plus header, so no cache or crawler touches it.
        return $items === []
            ? (new Response($this->view->render('challenges/view', $data), 200))->withHeader('X-Robots-Tag', 'noindex')
            : $this->view->render('challenges/view', $data);
    }

    #[AuthAttr]
    public function new(): Response|string
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        return $this->form(null);
    }

    #[AuthAttr] #[Post]
    public function create(): Response|string
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        [$title, $summary, $membership, $error] = $this->challengeInput();
        if ($error !== null) return new Response($this->form(null, $error), 422);
        $me = (int) $this->session->get('user_id');
        $slug = $this->challenges->create($me, $title, $summary, $membership);
        $this->staticCache()->purgeChallenge($slug); // the index gains a row (the write contract)
        return Response::redirect('/challenges/view/' . $slug); // the Series idiom
    }

    #[AuthAttr]
    public function edit(string $slug): Response|string
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        try { $row = $this->challenges->own($slug, (int) $this->session->get('user_id')); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        return $this->form($row);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug): Response|string
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        $me = (int) $this->session->get('user_id');
        [$title, $summary, $membership, $error] = $this->challengeInput();
        if ($error !== null) return new Response($this->form(null, $error), 422);
        try { $this->challenges->update($slug, $title, $summary, $membership, $me); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        $this->staticCache()->purgeChallenge($slug);
        return Response::redirect('/challenges/view/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function delete(string $slug): Response
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        try { $this->challenges->delete($slug, (int) $this->session->get('user_id')); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        $this->staticCache()->purgeChallenge($slug);
        return Response::redirect('/challenges'); // the module index (the Lists delete idiom)
    }

    /** Prompt add: owner-only through the repository's own() (anyone else
     *  draws the 404 before validation); text 1-500, clamped, the note clamp
     *  idiom; an empty prompt re-renders the edit form with the reason. */
    #[AuthAttr] #[Post]
    public function prompt(string $slug): Response|string
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        $me = (int) $this->session->get('user_id');
        try { $row = $this->challenges->own($slug, $me); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        $text = substr(trim($this->request->postStr('prompt_text')), 0, 500);
        if ($text === '') return new Response($this->form($row, \App\Lang::t('challenges.prompt_required')), 422);
        $this->challenges->addPrompt($slug, $text, $me);
        $this->staticCache()->purgeChallenge($slug); // prompts change the public blob
        return Response::redirect('/challenges/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function promptremove(string $slug, string $promptId): Response
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        if (!$this->challenges->removePrompt($slug, (int) $promptId, (int) $this->session->get('user_id'))) {
            return new Response('Page not found', 404);
        }
        $this->staticCache()->purgeChallenge($slug); // prompts change the public blob
        return Response::redirect('/challenges/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function promptmove(string $slug, string $promptId, string $dir): Response
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        $dir = $dir === 'down' ? 'down' : 'up'; // junk coerces, the browse page-param philosophy
        try { $this->challenges->reorderPrompts($slug, (int) $promptId, $dir, (int) $this->session->get('user_id')); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        $this->staticCache()->purgeChallenge($slug); // the blob's order changed
        return Response::redirect('/challenges/edit/' . $slug);
    }

    /** The membership surface: a story-side author joins their story by slug.
     *  The addItem contract maps to HTTP: unknown slugs 404, closed
     *  challenges and foreign stories 422; 'pending' notifies the owner,
     *  any immediate confirm notifies the story's author when the actor is
     *  someone else (the owner/admin direct add and the pending upgrade,
     *  the same outcome as the confirm action). */
    #[AuthAttr] #[Post]
    public function join(string $slug): Response
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        $me = (int) $this->session->get('user_id');
        $storySlug = trim($this->request->postStr('story_slug'));
        try {
            $result = $this->challenges->addItem($slug, $storySlug, $me, $this->challenges->viewerIsAdmin($me));
        } catch (\RuntimeException $e) {
            return new Response($e->getMessage() === 'not found' ? 'Page not found' : $e->getMessage(), $e->getMessage() === 'not found' ? 404 : 422);
        }
        if ($result === 'pending') $this->notifyOwner($slug, $me, $storySlug);
        if ($result === 'added' || $result === 'confirmed') $this->notifyAuthor($storySlug, $me);
        $this->staticCache()->purgeChallenge($slug);
        return Response::redirect('/challenges/view/' . $slug);
    }

    /** Owner/admin: promote a pending submission and notify its author. */
    #[AuthAttr] #[Post]
    public function confirm(string $slug, string $itemId): Response
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        $me = (int) $this->session->get('user_id');
        try {
            $info = $this->challenges->confirmItem($slug, (int) $itemId, $me, $this->challenges->viewerIsAdmin($me));
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        if ($info !== null) {
            [$authorId, $title, $storySlug] = $info;
            if ($authorId !== $me) { // no self-congratulation (the KudosController idiom)
                (new \App\Notifications($this->db))->create($authorId, 'challenge_confirm', $this->storyId($storySlug), $me, $title);
            }
            $this->staticCache()->purgeChallenge($slug);
        }
        return Response::redirect('/challenges/view/' . $slug);
    }

    /** Owner, admin, or the story's author: pull a story out of a challenge. */
    #[AuthAttr] #[Post]
    public function remove(string $slug, string $storySlug): Response
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        if (!$this->challenges->removeItem($slug, $storySlug, (int) $this->session->get('user_id'), $this->challenges->viewerIsAdmin((int) $this->session->get('user_id')))) {
            return new Response('Page not found', 404);
        }
        $this->staticCache()->purgeChallenge($slug);
        return Response::redirect('/challenges/view/' . $slug);
    }

    /** The challenge owner gets one challenge_submit per pending submission; a
     *  pending result implies a non-owner actor, so no self-guard is needed
     *  here (the SeriesController idiom). */
    private function notifyOwner(string $slug, int $me, string $storySlug): void
    {
        $owner = $this->db->one('SELECT owner_id FROM challenges WHERE slug = ?', [$slug]);
        $story = $this->storyForNotify($storySlug);
        if ($owner === null || $story === null) return;
        (new \App\Notifications($this->db))->create((int) $owner['owner_id'], 'challenge_submit', (int) $story['id'], $me, (string) $story['title']);
    }

    /** The story's author learns their story landed (the immediate-confirm
     *  paths: the owner/admin direct add and the pending-row upgrade; the
     *  actor is never the author themselves on these paths, and the guard
     *  keeps a self-join silent anyway). */
    private function notifyAuthor(string $storySlug, int $me): void
    {
        $story = $this->storyForNotify($storySlug);
        if ($story !== null && (int) $story['author_id'] !== $me) {
            (new \App\Notifications($this->db))->create((int) $story['author_id'], 'challenge_confirm', (int) $story['id'], $me, (string) $story['title']);
        }
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

    private function staticCache(): \App\StaticCache\Cache
    {
        // Config-injected dir when present (the ListsController pattern), the
        // tree's public/cache otherwise: tests pin through-controller purges
        // without ever writing into the real dir.
        return new \App\StaticCache\Cache((string) (($this->app->config('static_cache', []) ?? [])['dir'] ?? dirname(__DIR__, 3) . '/public/cache'));
    }

    /** title 1-120, summary clamped to 2000 (plain text at rest, never
     *  markdown: challenges are metadata surfaces, the lists ruling),
     *  membership in the enum; junk membership 422s like the series form's
     *  enum fields. */
    private function challengeInput(): array
    {
        $title = trim($this->request->postStr('title'));
        $summary = substr(trim($this->request->postStr('summary')), 0, 2000);
        $membership = $this->request->postStr('membership');
        if ($title === '' || mb_strlen($title) > 120) {
            return [$title, $summary, $membership, \App\Lang::t('challenges.title_invalid')];
        }
        if (!in_array($membership, ['open', 'moderated', 'closed'], true)) {
            return [$title, $summary, $membership, \App\Lang::t('challenges.membership_invalid')];
        }
        return [$title, $summary, $membership, null];
    }

    /** $row null renders the create form; the edit row prefills, targets
     *  update, and carries the prompt management section. */
    private function form(?array $row, ?string $error = null): string
    {
        $title = $row === null ? \App\Lang::t('challenges.new') : \App\Lang::t('challenges.edit');
        return $this->view->render('challenges/form', [
            'title' => $title, 'head' => $this->head()->withTitle($title)->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'row' => $row, 'error' => $error,
            'prompts' => $row === null ? [] : $this->challenges->promptsForEdit((int) $row['id']),
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
