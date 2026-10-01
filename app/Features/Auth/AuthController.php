<?php // app/Features/Auth/AuthController.php
namespace App\Features\Auth;
use App\Repositories\UserRepository;
use Kip\{App, Auth, Database, Mailer, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

final class AuthController
{
    public function __construct(
        private Auth $auth,
        private View $view,
        private Session $session,
        private Request $request,
        private Mailer $mailer,
        private App $app,
        private UserRepository $users,
        private Database $db,
    ) {}

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }

    public function login(): string
    {
        return $this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'), 'head' => $this->head()->withTitle(\App\Lang::t('auth.login.heading'))->withCanonical('/auth/login')->withNoindex(),
            'csrf' => $this->session->csrfToken(), 'error' => null,
            'verified' => isset($this->request->get['verified'])]);
    }

    #[Post]
    public function attempt(): Response|string
    {
        $email = $this->request->postStr('email');
        $password = $this->request->postStr('password');
        if ($this->auth->throttled($email, $this->request->ip)) {
            return new Response(
                $this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'), 'csrf' => $this->session->csrfToken(),
                    'error' => 'Too many attempts, try again in 15 minutes.']),
                429
            );
        }
        $ok = $this->auth->attempt($email, $password, $this->request->ip)
            || ($this->tryLegacyUpgrade($email, $password) && $this->auth->attempt($email, $password, $this->request->ip));
        if ($ok) {
            $user = $this->users->findByEmail($email);
            if ($user !== null) {
                if ((int) $user['is_locked'] === 1) {
                    $this->auth->logout();
                    return $this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'), 'csrf' => $this->session->csrfToken(),
                        'error' => 'This account is locked.']);
                }
                if ($user['email_verified_at'] === null) {
                    $this->auth->logout();
                    return $this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'), 'csrf' => $this->session->csrfToken(),
                        'error' => 'Please verify your email first (check the link we sent).']);
                }
                if ($user['approved_at'] === null) {
                    $this->auth->logout();
                    return $this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'), 'csrf' => $this->session->csrfToken(),
                        'error' => 'Your account is awaiting approval.']);
                }
            }
            return $this->redirectWithPrefCookies((int) ($user['id'] ?? 0));
        }
        return $this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'), 'csrf' => $this->session->csrfToken(), 'error' => 'Wrong email or password']);
    }

    /** The cookie-sync seam (phase 12d ruling 1): the prefs row is the source
     *  of truth, the 'lang' and 'theme' cookies are the runtime cache the
     *  render path reads (zero queries per page). The one SELECT rides this
     *  write path (budget-exempt); a member with no prefs row, or empty prefs,
     *  gets the plain redirect with no cookie directives. Each directive is
     *  one withAddedHeader leaf on the redirect: Response::send() emits list
     *  leaves with append semantics, so the session cookie the login just
     *  regenerated survives beside them. Flag-off skips the writes (the 12d
     *  off semantics): the stored pref goes inert instead of landing in the
     *  browser's runtime cache. */
    private function redirectWithPrefCookies(int $userId): Response
    {
        $redirect = Response::redirect('/');
        if ($userId === 0) return $redirect;
        $prefs = $this->db->one('SELECT lang, theme FROM user_prefs WHERE user_id = ?', [$userId]);
        $cookies = [];
        $lang = (string) ($prefs['lang'] ?? '');
        if (\App\Features::on('peruserlang') && preg_match('/^[a-z]{2}$/', $lang) === 1) {
            $cookies[] = 'lang=' . $lang . '; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax';
        }
        $theme = (string) ($prefs['theme'] ?? '');
        if (\App\Features::on('perusertheme') && ($theme === 'dark' || $theme === 'light')) {
            $cookies[] = \App\Theme::COOKIE . '=' . $theme . '; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax';
        }
        foreach ($cookies as $cookie) {
            $redirect = $redirect->withAddedHeader('Set-Cookie', $cookie);
        }
        return $redirect;
    }

    /** eFiction imports carry unsalted md5 hashes: on the first successful
     *  legacy verify, rehash to the modern algorithm, clear the legacy column,
     *  and send the we-moved note. Runs after the throttle gate, so guessing
     *  burns login_attempts rows exactly like a normal login failure.
     *  Returns whether an upgrade happened; the retry only runs then, so a
     *  wrong password still costs exactly one login_attempts row. */
    private function tryLegacyUpgrade(string $email, string $password): bool
    {
        $row = $this->db->one('SELECT id, legacy_md5 FROM users WHERE email = ? AND legacy_md5 IS NOT NULL', [$email]);
        if ($row === null || !hash_equals((string) $row['legacy_md5'], md5($password))) return false;
        $this->db->query('UPDATE users SET password_hash = ?, legacy_md5 = NULL WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        // we_moved carries no placeholders: the pair resolves straight from the row.
        [$subject, $body] = \App\Templates::get($this->db, 'we_moved', 'The archive moved',
            "The archive you were a member of has moved.\n\nGood news: the password you just used still works, "
            . "and it is now stored with modern hashing. No action needed; this is just a heads up.");
        try {
            $this->mailer->send($email, $subject, $body);
        } catch (\Throwable $e) {
            error_log("Legacy-upgrade mail failed: {$e->getMessage()}");
        }
        return true;
    }

    public function register(): string
    {
        return $this->view->render('auth/register', [
            'title' => \App\Lang::t('auth.register.title'),
            'head' => $this->head()->withTitle(\App\Lang::t('auth.register.title'))->withCanonical('/auth/register')->withNoindex(),
            'csrf' => $this->session->csrfToken(),
            'error' => null,
            'mode' => (string) $this->app->config('registration_mode', 'verify'),
        ]);
    }

    #[Post]
    public function store(): Response|string
    {
        $mode = (string) $this->app->config('registration_mode', 'verify');
        $penname = $this->request->postStr('penname');
        $email = $this->request->postStr('email');
        $password = $this->request->postStr('password');
        if ($password !== $this->request->postStr('confirm')) {
            return $this->registerError($mode, 'Passwords do not match.', $penname, $email);
        }
        [$ok, $error] = $this->users->register($mode, $penname, $email, $password, $this->request->postStr('invite'));
        if (!$ok) {
            return $this->registerError($mode, $error, $penname, $email);
        }
        // Invite mode lands in the member directory immediately (verified +
        // approved at insert); verify/approval modes complete later, where those
        // paths purge too. A purge with no directory change is a no-op.
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeAuthors();
        if ($mode === 'verify') {
            $raw = $this->users->createVerification($email);
            $url = rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/') . '/auth/verify/' . $raw;
            [$subject, $body] = \App\Templates::get($this->db, 'member_verify', 'Verify your account',
                "Welcome to the archive. Confirm your address:\n\n{url}\n\nThe link is valid for 24 hours.");
            try {
                $this->mailer->send($email, $subject, strtr($body, ['{url}' => $url]));
            } catch (\Throwable $e) {
                error_log("Verification mail failed: {$e->getMessage()}");
            }
        }
        return Response::redirect('/auth/login');
    }

    public function verify(string $token): Response
    {
        if ($this->users->consumeVerification($token)) {
            // The member just became email-verified; in verify mode registration
            // already approved them, so this is the moment they join the directory.
            (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeAuthors();
        }
        return Response::redirect('/auth/login?verified=1');
    }

    private function registerError(string $mode, string $error, string $penname, string $email): Response
    {
        return new Response($this->view->render('auth/register', [
            'title' => \App\Lang::t('auth.register.title'),
            'head' => $this->head()->withTitle(\App\Lang::t('auth.register.title'))->withCanonical('/auth/register')->withNoindex(),
            'csrf' => $this->session->csrfToken(),
            'error' => $error,
            'mode' => $mode,
            'penname' => $penname, 'email' => $email, 'invite' => $this->request->postStr('invite'),
        ]), 422);
    }

    #[AuthAttr] #[Post]
    public function logout(): Response
    {
        $this->auth->logout();
        // The cookie-sync mirror: the session dies, so the runtime cache dies
        // with it; the next render on this browser is the archive default,
        // not a stale member preference. One withAddedHeader leaf per cleared
        // cookie, the same chain shape the login sync uses.
        return Response::redirect('/')
            ->withAddedHeader('Set-Cookie', 'lang=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax')
            ->withAddedHeader('Set-Cookie', \App\Theme::COOKIE . '=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax');
    }

    public function forgot(): string
    {
        return $this->view->render('auth/forgot', ['title' => \App\Lang::t('auth.forgot.title'),
            'head' => $this->head()->withTitle(\App\Lang::t('auth.forgot.title'))->withCanonical('/auth/forgot')->withNoindex(), 'sent' => false]);
    }

    #[Post]
    public function remind(): string
    {
        $email = $this->request->postStr('email');
        $token = $this->auth->createReset($email, $this->request->ip);
        if ($token !== null) {
            $url = rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/') . "/auth/reset/{$token}";
            [$subject, $body] = \App\Templates::get($this->db, 'password_reset', 'Reset your password',
                "Someone (hopefully you) asked to reset the password for this address.\n\n"
                . "Reset link (valid 30 minutes):\n{url}\n\nIf this wasn't you, ignore this email.");
            // Deferred past the response: this send happens only for existing
            // accounts, so its duration must not show in the response time.
            $this->app->defer(function () use ($email, $subject, $body, $url): void {
                try {
                    $this->mailer->send($email, $subject, strtr($body, ['{url}' => $url]));
                } catch (\Throwable $e) {
                    // A mailer failure must not become an account-existence oracle: the page
                    // is identical either way, and the failure lands in the server log.
                    error_log("Password-reset mail failed for a known address: {$e->getMessage()}");
                }
            });
        }
        // Same page whether the account exists or not, no enumeration.
        return $this->view->render('auth/forgot', ['title' => \App\Lang::t('auth.forgot.title'), 'sent' => true]);
    }

    public function reset(string $token): string
    {
        return $this->resetView($token, null);
    }

    #[Post]
    public function confirm(): Response|string
    {
        $token = $this->request->postStr('token');
        $password = $this->request->postStr('password');
        if (strlen($password) < 8) {
            return new Response($this->resetView($token, 'Password must be at least 8 characters.'), 422);
        }
        if (!$this->auth->resetPassword($token, $password)) {
            return new Response($this->resetView($token, 'That reset link is invalid or has expired, request a new one.'), 422);
        }
        return Response::redirect('/auth/login');
    }

    private function resetView(string $token, ?string $error): string
    {
        return $this->view->render('auth/reset', ['title' => \App\Lang::t('auth.reset.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('auth.reset.heading'))->withCanonical($this->request->path)->withNoindex(),
            'token' => $token, 'error' => $error]);
    }
}
