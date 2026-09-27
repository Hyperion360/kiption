<?php // app/src/Controllers/UserController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Mailer, Session, View};
use Kip\Routing\{Auth as AuthAttr, Get, Post};
use App\Repositories\UserRepository;

final class UserController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Session $session,
        private App $app,
        private UserRepository $users,
        private Database $db,
        private Mailer $mailer,
    ) {}

    public function view(string $slug): Response|string
    {
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $profile = $this->users->findByProfileSlug($slug);
        if ($profile === null) return new Response('Page not found', 404);
        $site = (string) $this->app->config('site_name', 'Kiption');
        $head = $this->head()->withTitle($profile['penname'])
            ->withDescription(($profile['bio'] ?? '') !== '' ? (string) $profile['bio'] : \App\Lang::t('user.meta_stories_by', ['name' => $profile['penname'], 'site' => $site]))
            ->withCanonical('/user/view/' . $slug);
        // Person JSON-LD url is absolute: Head.php's own rule, the series ruling
        $head = $head->withJsonLd(['@context' => 'https://schema.org', '@type' => 'Person',
            'name' => $profile['penname'], 'url' => $head->url('/user/view/' . $slug)]);
        if ($profile['avatar_path'] !== null && (string) $profile['avatar_path'] !== '') {
            // og:image needs an absolute URL; url() is Head's own absolutizer
            $head = $head->withOgImage($head->url((string) $profile['avatar_path']));
        }
        return $this->view->render('user/view', [
            'title' => $profile['penname'],
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'slug' => $slug,
            'profile' => $profile,
            'me' => $me,
            // The mute button needs a token, but a cookieless render must not
            // grow one (the cacheable-path rule): the ChallengesController idiom.
            'csrf' => $me !== 0 ? $this->session->csrfToken() : '',
            'loggedIn' => $me !== 0,
        ]);
    }

    public function stories(string $slug): Response|string
    {
        [$perPage, $offset] = $this->paginate();
        // ?sort= is whitelisted in PHP before the repository interpolates it;
        // junk coerces to 'recent' (the BrowseController page-param philosophy)
        $sort = ($this->request->get['sort'] ?? '') === 'alpha' ? 'alpha' : 'recent';
        $tab = $this->users->storiesTab($slug, $sort, $perPage, $offset);
        if ($tab === null) return new Response('Page not found', 404);
        return $this->renderTab($slug, $tab, \App\Lang::t('user.stories_by'), '/user/stories/' . $slug);
    }

    public function favorites(string $slug): Response|string
    {
        [$perPage, $offset] = $this->paginate();
        $tab = $this->users->favoritesTab($slug, $perPage, $offset);
        if ($tab === null) return new Response('Page not found', 404);
        return $this->renderTab($slug, $tab, \App\Lang::t('user.favorites_of'), '/user/favorites/' . $slug);
    }

    /** Both verbs share the /user/contact/{slug} action name (the router derives
     *  it from the URL segment), so one multi-verb action dispatches: GET renders
     *  the form, POST runs the send path. */
    #[AuthAttr] #[Get] #[Post]
    public function contact(string $slug): Response|string
    {
        if (($r = \App\Features::guard('contact')) !== null) return $r;
        if ($this->request->method === 'POST') return $this->send($slug);
        $target = $this->users->findByProfileSlug($slug);
        if ($target === null || (int) $target['id'] === (int) $this->session->get('user_id')) {
            return new Response('Page not found', 404);
        }
        return $this->renderContactForm($slug, $target, null, false);
    }

    /** Mailbombing guard: 3 messages per sender per hour, enforced by ONE
     *  guarded INSERT (the report-intake pattern): the hour-window count rides
     *  inside the INSERT..SELECT, and rowCount() is the throttle signal, so two
     *  concurrent sends cannot both pass a check-then-act count (the exact race
     *  the Phase 6b pass fixed for the guest review throttle). The log row is
     *  spent even when the transport fails, so a flaky mailer cannot be used to
     *  probe the limit. */
    private function send(string $slug): Response|string
    {
        $me = (int) $this->session->get('user_id');
        $target = $this->users->findByProfileSlug($slug);
        if ($target === null || (int) $target['id'] === $me) return new Response('Page not found', 404);
        $body = trim($this->request->postStr('body'));
        if ($body === '' || strlen($body) > 5000) {
            return new Response($this->renderContactForm($slug, $target, 'Message must be 1 to 5000 characters.', false), 422);
        }
        $guard = $this->db->query(
            "INSERT INTO contact_log (sender_id, target_id)
             SELECT ?, ? WHERE (SELECT COUNT(*) FROM contact_log
                                WHERE sender_id = ? AND created_at > strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-1 hour')) < 3",
            [$me, (int) $target['id'], $me]
        );
        if ($guard->rowCount() === 0) {
            return new Response($this->renderContactForm($slug, $target, 'You have sent several messages recently, try again later.', false), 429);
        }
        $sender = $this->db->one('SELECT penname, profile_slug FROM users WHERE id = ?', [$me]);
        $base = rtrim((string) $this->app->config('base_url', ''), '/');
        // {message} is the member's typed body: it rides the interpolation pair,
        // never the template text, so a message quoting a placeholder stays literal.
        [$subject, $tpl] = \App\Templates::get($this->db, 'member_contact', 'Message from {penname}',
            "{message}\n\nReply via {url}");
        $pairs = [
            '{message}' => $body, '{penname}' => (string) $sender['penname'],
            '{sender_slug}' => (string) $sender['profile_slug'],
            '{url}' => "{$base}/user/contact/{$sender['profile_slug']}",
        ];
        try {
            $this->mailer->send((string) $target['email'], strtr($subject, $pairs), strtr($tpl, $pairs));
        } catch (\Throwable $e) {
            error_log("Contact mail failed: {$e->getMessage()}");
            return new Response($this->renderContactForm($slug, $target, 'The message could not be sent, try again later.', false), 500);
        }
        return $this->renderContactForm($slug, $target, null, true);
    }

    /** The form and the sent page share one view; the target's address is the
     *  mail transport's business, never rendered. */
    private function renderContactForm(string $slug, array $target, ?string $error, bool $sent): string
    {
        $contactTitle = \App\Lang::t('user.contact_heading', ['name' => $target['penname']]);
        return $this->view->render('user/contact', [
            'title' => $contactTitle,
            'head' => $this->head()->withTitle($contactTitle)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'slug' => $slug,
            'target' => $target,
            'error' => $error,
            'sent' => $sent,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
        ]);
    }

    /** Both tabs reuse browse/recent.php verbatim; an empty tab is the
     *  empty-category precedent: meta noindex plus the X-Robots-Tag header. */
    private function renderTab(string $slug, array $tab, string $prefix, string $baseUrl): Response|string
    {
        $profile = $tab['profile'];
        $title = $prefix . $profile['penname'];
        $head = $this->head()->withTitle($title)
            ->withDescription(\App\Lang::t('user.listing_meta', ['title' => $title, 'site' => (string) $this->app->config('site_name', 'Kiption')]))
            ->withCanonical($baseUrl);
        $data = [
            'title' => $title,
            'head' => $tab['stories'] === [] ? $head->withNoindex() : $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'stories' => $tab['stories'],
            'page' => $this->page(),
            'baseUrl' => $baseUrl,
        ];
        return $tab['stories'] === []
            ? (new Response($this->view->render('browse/recent', $data), 200))->withHeader('X-Robots-Tag', 'noindex')
            : $this->view->render('browse/recent', $data);
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

    /** Overflow guard: (page - 1) * perPage must stay an int, or a hostile
     *  page=99999999999999999999 makes the offset a float and the repository
     *  TypeError turns the listing into a 500. Cap page so it cannot. */
    private function paginate(): array
    {
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        $page = min($this->page(), intdiv(PHP_INT_MAX, $perPage));
        return [$perPage, ($page - 1) * $perPage];
    }
}
