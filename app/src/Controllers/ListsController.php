<?php // app/src/Controllers/ListsController.php
namespace App\Controllers;
use Kip\{App, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\ListsRepository;

final class ListsController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Session $session,
        private App $app,
        private ListsRepository $lists,
    ) {}

    public function view(string $slug): Response|string
    {
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
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
            'navFile' => (string) $this->app->config('nav_file', ''),
            'list' => $list, 'items' => $items,
            'isOwner' => $isOwner, 'me' => $me,
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
        try { $row = $this->lists->own($slug, (int) $this->session->get('user_id')); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        return $this->form($row);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug): Response|string
    {
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
        $me = (int) $this->session->get('user_id');
        try { $this->lists->delete($slug, $me); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        $this->staticCache()->purgeList($slug);
        return Response::redirect('/lists'); // the member's lists index (Task 2)
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

    /** $row null renders the create form; the edit row prefills and targets update. */
    private function form(?array $row, ?string $error = null): string
    {
        $title = $row === null ? \App\Lang::t('lists.new') : \App\Lang::t('lists.edit');
        return $this->view->render('lists/form', [
            'title' => $title, 'head' => $this->head()->withTitle($title)->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'row' => $row, 'error' => $error, 'csrf' => $this->session->csrfToken(), 'loggedIn' => true,
        ]);
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
