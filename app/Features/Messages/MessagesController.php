<?php // app/Features/Messages/MessagesController.php
namespace App\Features\Messages;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\MessageRepository;
use App\Repositories\UserRepository;

/** The private-message surfaces: inbox, thread, compose, send. Every action
 *  is member-gated and guard-first on the 'pms' flag. The compose/send
 *  targets resolve by PROFILE_SLUG through findByProfileSlug (finding 1, the
 *  contact idiom: is_locked = 0 and penname not null are already enforced,
 *  and the router's segment whitelist matches its slug regex), self and
 *  unknown targets 404 exactly like the profile page would, and bodies clamp
 *  to 1-5000 characters (the contact idiom). */
final class MessagesController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        if (($r = \App\Features::guard('pms')) !== null) return $r;
        $rows = (new MessageRepository($this->db))->inbox($this->uid());
        $title = \App\Lang::t('messages.heading');
        return $this->view->render('messages/index', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/messages')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $rows,
            'loggedIn' => true,
        ]);
    }

    /** The penname-resolved thread: one anchor-row query carries the partner
     *  and the windowed messages (the authorFeed shape), and the open itself
     *  marks the thread read (idempotently, after the fetch). */
    #[AuthAttr]
    public function view(string $slug): Response|string
    {
        if (($r = \App\Features::guard('pms')) !== null) return $r;
        $rows = (new MessageRepository($this->db))->thread($this->uid(), $slug);
        if ($rows === null) return new Response('Page not found', 404);
        $title = \App\Lang::t('messages.with', ['name' => $rows[0]['partner_name']]);
        return $this->view->render('messages/thread', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/messages/view/' . $slug)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $rows,
            'me' => $this->uid(),
            'slug' => $slug,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
        ]);
    }

    #[AuthAttr]
    public function new(string $slug): Response|string
    {
        if (($r = \App\Features::guard('pms')) !== null) return $r;
        $partner = (new UserRepository($this->db))->findByProfileSlug($slug);
        if ($partner === null || (int) $partner['id'] === $this->uid()) {
            return new Response('Page not found', 404);
        }
        return $this->renderForm($slug, $partner, null);
    }

    #[AuthAttr] #[Post]
    public function send(string $slug): Response
    {
        if (($r = \App\Features::guard('pms')) !== null) return $r;
        $partner = (new UserRepository($this->db))->findByProfileSlug($slug);
        if ($partner === null || (int) $partner['id'] === $this->uid()) {
            return new Response('Page not found', 404);
        }
        $body = trim($this->request->postStr('body'));
        if ($body === '' || mb_strlen($body) > 5000) {
            return new Response($this->renderForm($slug, $partner, \App\Lang::t('messages.body_invalid')), 422);
        }
        (new MessageRepository($this->db))->send($this->uid(), (int) $partner['id'], $body);
        return Response::redirect('/messages/view/' . $slug);
    }

    /** The compose page and the send-path 422 share one view; the target's
     *  address never renders anywhere (the contact rule). */
    private function renderForm(string $slug, array $partner, ?string $error): string
    {
        $title = \App\Lang::t('messages.compose', ['name' => $partner['penname']]);
        return $this->view->render('messages/form', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'slug' => $slug,
            'partner' => $partner,
            'error' => $error,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
        ]);
    }

    private function uid(): int
    {
        return (int) $this->session->get('user_id');
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
