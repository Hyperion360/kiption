<?php // app/src/Controllers/ChapterController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\AuthoringRepository;

final class ChapterController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function new(string $slug): Response|string
    {
        return $this->form($slug, null, null);
    }

    #[AuthAttr]
    public function edit(string $slug, int $position): Response|string
    {
        return $this->form($slug, $position, null);
    }

    #[AuthAttr] #[Post]
    public function create(string $slug): Response|string
    {
        [$title, $content, $before, $after] = $this->chapterInput();
        if (trim($content) === '') {
            return new Response($this->form($slug, null, 'Chapter text is required.'), 422);
        }
        try {
            [$cats] = $this->repo()->createChapter($slug, $this->uid(), $title, $content, $before, $after, $this->autoValidates());
        } catch (\RuntimeException) {
            return new Response('Page not found', 404); // non-owned or unknown story, same contract as story writes
        }
        $this->purge($slug, $cats);
        return Response::redirect('/story/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug, int $position): Response|string
    {
        [$title, $content, $before, $after] = $this->chapterInput();
        try {
            [$cats] = $this->repo()->updateChapter($slug, $position, $this->uid(), $title, $content, $before, $after);
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $this->purge($slug, $cats);
        return Response::redirect('/story/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function delete(string $slug, int $position): Response
    {
        try {
            [$cats] = $this->repo()->deleteChapter($slug, $position, $this->uid());
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $this->purge($slug, $cats);
        return Response::redirect('/story/edit/' . $slug);
    }

    private function form(string $slug, ?int $position, ?string $error): Response|string
    {
        $row = $this->repo()->chapterFormData($slug, $position, $this->uid());
        if ($row === null) return new Response('Page not found', 404);
        return $this->view->render('chapter/form', [
            'title' => $position === null ? 'New chapter' : 'Edit chapter',
            'head' => $this->head()->withTitle($position === null ? 'New chapter' : 'Edit chapter')
                ->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'story' => $row,
            'position' => $position,
            'csrf' => $this->session->csrfToken(),
            'error' => $error,
        ]);
    }

    private function repo(): AuthoringRepository { return new AuthoringRepository($this->db); }
    private function uid(): int { return (int) $this->session->get('user_id'); }

    private function autoValidates(): bool
    {
        $role = (string) ($this->db->one('SELECT role FROM users WHERE id = ?', [$this->uid()])['role'] ?? 'member');
        return !((bool) $this->app->config('validation_required', true))
            || in_array($role, ['validated_author', 'moderator', 'admin'], true);
    }

    private function purge(string $slug, array $cats): void
    {
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeStory($slug, $cats);
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }

    /** @return array{string,string,string,string} */
    private function chapterInput(): array
    {
        $p = $this->request->post;
        return [trim((string) ($p['title'] ?? '')), (string) ($p['content'] ?? ''),
            trim((string) ($p['notes_before'] ?? '')), trim((string) ($p['notes_after'] ?? ''))];
    }
}
