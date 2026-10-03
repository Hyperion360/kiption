<?php // app/Features/Lists/ListsController.php
namespace App\Features\Lists;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\ListsRepository;

final class ListsController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Session $session,
        private App $app,
        private Database $db, // App\Viewer::id's epoch re-validation
        private ListsRepository $lists,
    ) {}

    public function view(string $slug): Response|string
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        // owner checks ride the validated viewer: a session revoked by a
        // password change must not open the owner's private list
        $me = \App\Viewer::id($this->request, $this->session, $this->db);
        $list = $this->lists->view($slug, $me);
        if ($list === null) return new Response('Page not found', 404);
        $items = $list['items'];
        unset($list['items']);
        $isOwner = $me !== 0 && $me === $list['owner_id'];
        // Private lists are owner-only surfaces: noindex, nothing worth caching
        // or indexing; public ones are canonical, indexable pages.
        $head = $this->head()->withTitle($list['title'])
            ->withDescription($list['summary'] !== '' ? $list['summary'] : \App\Lang::t('lists.meta_by', ['name' => $list['owner_penname']]))
            ->withCanonical('/lists/view/' . $slug);
        if ($list['is_public'] !== 1) $head = $head->withNoindex();
        return $this->view->render('lists/view', [
            'title' => $list['title'],
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'list' => $list, 'items' => $items,
            'isOwner' => $isOwner, 'me' => $me,
            'csrf' => $me !== 0 ? $this->session->csrfToken() : '',
            'loggedIn' => $me !== 0,
        ]);
    }

    #[AuthAttr]
    public function index(): Response|string
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        $title = \App\Lang::t('lists.index');
        return $this->view->render('lists/index', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/lists')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'rows' => $this->lists->indexFor((int) $this->session->get('user_id')),
            'loggedIn' => true,
        ]);
    }

    #[AuthAttr]
    public function new(): Response|string
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        return $this->form(null);
    }

    #[AuthAttr] #[Post]
    public function create(): Response|string
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        [$title, $summary, $isPublic, $error] = $this->listInput();
        if ($error !== null) return new Response($this->form(null, $error), 422);
        $me = (int) $this->session->get('user_id');
        $slug = $this->lists->create($me, $title, $summary, $isPublic);
        $this->staticCache()->purgeList($slug); // the write contract, even for a never-cached fresh page
        return Response::redirect('/lists/view/' . $slug);
    }

    #[AuthAttr]
    public function edit(string $slug): Response|string
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        try { $row = $this->lists->own($slug, (int) $this->session->get('user_id')); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        return $this->form($row);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug): Response|string
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        $me = (int) $this->session->get('user_id');
        [$title, $summary, $isPublic, $error] = $this->listInput();
        if ($error !== null) return new Response($this->form(null, $error), 422);
        try { $this->lists->update($slug, $title, $summary, $isPublic, $me); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        $this->staticCache()->purgeList($slug);
        return Response::redirect('/lists/view/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function delete(string $slug): Response
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        $me = (int) $this->session->get('user_id');
        try { $this->lists->delete($slug, $me); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        $this->staticCache()->purgeList($slug);
        return Response::redirect('/lists'); // the member's lists index (Task 2)
    }

    /** Add-by-slug: any validated, non-deleted story joins the owner's list
     *  (restricted included: their list, their eyes). Honest 422s re-render
     *  the management form with the reason; a stranger's list is own()'s 404. */
    #[AuthAttr] #[Post]
    public function item(string $slug): Response|string
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        $me = (int) $this->session->get('user_id');
        $note = substr(trim($this->request->postStr('note')), 0, 500);
        try {
            $this->lists->addItem($slug, trim($this->request->postStr('story_slug')), $note, $me);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'not found') return new Response('Page not found', 404);
            try { $row = $this->lists->own($slug, $me); }
            catch (\RuntimeException) { return new Response('Page not found', 404); }
            return new Response($this->form($row, $this->itemError($e->getMessage())), 422);
        }
        $this->staticCache()->purgeList($slug); // every list write path (finding 4)
        return Response::redirect('/lists/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function remove(string $slug, string $storySlug): Response
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        if (!$this->lists->removeItem($slug, $storySlug, (int) $this->session->get('user_id'))) {
            return new Response('Page not found', 404);
        }
        $this->staticCache()->purgeList($slug);
        return Response::redirect('/lists/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function move(string $slug, string $itemId, string $dir): Response
    {
        if (($r = \App\Features::guard('lists')) !== null) return $r;
        $dir = $dir === 'down' ? 'down' : 'up'; // junk coerces, the browse page-param philosophy
        try { $this->lists->move($slug, (int) $itemId, $dir, (int) $this->session->get('user_id')); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        $this->staticCache()->purgeList($slug); // positions live on the list page
        return Response::redirect('/lists/edit/' . $slug);
    }

    /** The repository's reject codes as Lang-keyed form errors. */
    private function itemError(string $code): string
    {
        return match ($code) {
            'unvalidated' => \App\Lang::t('lists.err_unvalidated'),
            'duplicate' => \App\Lang::t('lists.err_duplicate'),
            default => \App\Lang::t('lists.err_unknown_story'),
        };
    }

    /** Title 1-120, summary clamped to 500 (plain text at rest, never
     *  markdown: lists are metadata surfaces, recorded). is_public coerces:
     *  only the literal '1' is public, anything else (absent checkbox, junk)
     *  is private, the safe default. */
    private function listInput(): array
    {
        $title = trim($this->request->postStr('title'));
        $summary = substr(trim($this->request->postStr('summary')), 0, 500);
        $isPublic = $this->request->postStr('is_public') === '1' ? 1 : 0;
        if ($title === '' || mb_strlen($title) > 120) {
            return [$title, $summary, $isPublic, 'Title must be 1 to 120 characters.'];
        }
        return [$title, $summary, $isPublic, null];
    }

    /** $row null renders the create form; the edit row prefills, targets
     *  update, and carries the item management section. */
    private function form(?array $row, ?string $error = null): string
    {
        $title = $row === null ? \App\Lang::t('lists.new') : \App\Lang::t('lists.edit');
        return $this->view->render('lists/form', [
            'title' => $title, 'head' => $this->head()->withTitle($title)->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'row' => $row, 'error' => $error,
            'items' => $row === null ? [] : $this->lists->itemsForEdit((int) $row['id']),
            'csrf' => $this->session->csrfToken(), 'loggedIn' => true,
        ]);
    }

    private function staticCache(): \App\StaticCache\Cache
    {
        // Config-injected dir when present (the AdminstoriesController/Importer
        // pattern), the tree's public/cache otherwise: tests pin
        // through-controller purges without ever writing into the real dir.
        return \App\StaticCache\Cache::configured($this->app);
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
