<?php // app/src/Controllers/ChallengesController.php
namespace App\Controllers;
use Kip\{App, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\ChallengesRepository;

/** The member surface of the challenges module: CRUD plus prompt management,
 *  the ListsController mirror (guard-first, #[AuthAttr] writes, ownership via
 *  the repository's own() so a stranger's slug is a 404). The public pages,
 *  story membership, and notifications land with the public surface (next
 *  task); create redirects to the edit form until then. */
final class ChallengesController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Session $session,
        private App $app,
        private ChallengesRepository $challenges,
    ) {}

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
        return Response::redirect('/challenges/edit/' . $slug);
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
        return Response::redirect('/challenges/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function delete(string $slug): Response
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        try { $this->challenges->delete($slug, (int) $this->session->get('user_id')); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        return Response::redirect('/account'); // the StoryController delete idiom: no module index yet
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
        if ($text === '') return new Response($this->form($row, 'Prompt text is required.'), 422);
        $this->challenges->addPrompt($slug, $text, $me);
        return Response::redirect('/challenges/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function promptremove(string $slug, string $promptId): Response
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        if (!$this->challenges->removePrompt($slug, (int) $promptId, (int) $this->session->get('user_id'))) {
            return new Response('Page not found', 404);
        }
        return Response::redirect('/challenges/edit/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function promptmove(string $slug, string $promptId, string $dir): Response
    {
        if (($r = \App\Features::guard('challenges')) !== null) return $r;
        $dir = $dir === 'down' ? 'down' : 'up'; // junk coerces, the browse page-param philosophy
        try { $this->challenges->reorderPrompts($slug, (int) $promptId, $dir, (int) $this->session->get('user_id')); }
        catch (\RuntimeException) { return new Response('Page not found', 404); }
        return Response::redirect('/challenges/edit/' . $slug);
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
            return [$title, $summary, $membership, 'Title must be 1 to 120 characters.'];
        }
        if (!in_array($membership, ['open', 'moderated', 'closed'], true)) {
            return [$title, $summary, $membership, 'Membership must be open, moderated, or closed.'];
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
