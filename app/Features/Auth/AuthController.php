<?php // app/Features/Auth/AuthController.php
namespace App\Features\Auth;
use App\Repositories\UserRepository;
use Kip\{App, Auth, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

final class AuthController
{
    public function __construct(
        private Auth $auth,
        private View $view,
        private Session $session,
        private Request $request,
        private \App\MailGuard $mailer,
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
            'csrf' => $this->session->csrfToken(), 'error' => isset($this->request->get['locked']) ? \App\Lang::t('auth.err_locked') : null,
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
                    'error' => \App\Lang::t('auth.err_throttle')]),
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
                        'error' => \App\Lang::t('auth.err_locked')]);
                }
                if ($user['email_verified_at'] === null) {
                    $this->auth->logout();
                    return $this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'), 'csrf' => $this->session->csrfToken(),
                        'error' => \App\Lang::t('auth.err_unverified')]);
                }
                if ($user['approved_at'] === null) {
                    $this->auth->logout();
                    return $this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'), 'csrf' => $this->session->csrfToken(),
                        'error' => \App\Lang::t('auth.err_unapproved')]);
                }
            }
            return $this->redirectWithPrefCookies((int) ($user['id'] ?? 0));
        }
        return $this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'), 'csrf' => $this->session->csrfToken(), 'error' => \App\Lang::t('auth.err_wrong')]);
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
            $cookies[] = \App\Cookie::long('lang', $lang);
        }
        $theme = (string) ($prefs['theme'] ?? '');
        // The post-026 value set: a member's stored sepia/night/paper row
        // re-syncs its cookie on a new device. auto never reaches a row (the
        // write points store paper for it), and it is skipped here too.
        if (\App\Features::on('perusertheme') && \in_array($theme, \App\Theme::VALUES, true) && $theme !== 'auto') {
            $cookies[] = \App\Cookie::pref(\App\Theme::COOKIE, $theme);
        } elseif (($own = \App\Theme::current($this->request)) !== null) {
            // Auto stored: this browser keeps its own theme (D4), re-issued
            // script-writable. An older login minted it HttpOnly, which the
            // toggle cannot overwrite; legacy light/dark land as paper/night.
            $cookies[] = \App\Cookie::pref(\App\Theme::COOKIE, $own);
        }
        foreach ($cookies as $cookie) {
            $redirect = $redirect->withAddedHeader('Set-Cookie', $cookie);
        }
        return $redirect;
    }

    /** eFiction imports carry unsalted md5 or legacy bcrypt hashes: on the
     *  first successful legacy verify, rehash to the modern algorithm, clear
     *  BOTH legacy columns, and send the we-moved note. Runs after the
     *  throttle gate, so guessing burns login_attempts rows exactly like a
     *  normal login failure. md5 is checked first and bcrypt only as an
     *  else, so a corrupt double-legacy row has exactly one comparison path
     *  (and one login_attempts burn per wrong try).
     *  Returns whether an upgrade happened; the retry only runs then, so a
     *  wrong password still costs exactly one login_attempts row. */
    private function tryLegacyUpgrade(string $email, string $password): bool
    {
        $row = $this->db->one('SELECT id, legacy_md5, legacy_bcrypt FROM users WHERE email = ? AND (legacy_md5 IS NOT NULL OR legacy_bcrypt IS NOT NULL)', [$email]);
        if ($row === null) return false;
        $verified = $row['legacy_md5'] !== null && hash_equals((string) $row['legacy_md5'], md5($password));
        if (!$verified && $row['legacy_bcrypt'] !== null) $verified = password_verify($password, (string) $row['legacy_bcrypt']);
        if (!$verified) return false;
        $this->db->query('UPDATE users SET password_hash = ?, legacy_md5 = NULL, legacy_bcrypt = NULL WHERE id = ?',
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
            'terms_url' => \App\Notices::termsUrl((string) $this->app->config('signup_terms_url', '')),
        ] + $this->checkFields());
    }

    private function signupCheck(): \App\SignupCheck
    {
        return new \App\SignupCheck($this->db, $this->session, (int) $this->app->config('signup_min_seconds', 3));
    }

    /** The view variables for the signup spam checks: the signed fill-time
     *  stamp and the owner's question, when there is one. */
    private function checkFields(): array
    {
        $check = $this->signupCheck();
        return ['stamp' => $check->stamp(), 'question' => $check->question()['question'] ?? null];
    }

    #[Post]
    public function store(): Response|string
    {
        $mode = (string) $this->app->config('registration_mode', 'verify');
        $penname = $this->request->postStr('penname');
        $email = $this->request->postStr('email');
        $password = $this->request->postStr('password');
        $refusal = $this->signupCheck()->verify([
            \App\SignupCheck::HONEYPOT => $this->request->postStr(\App\SignupCheck::HONEYPOT),
            \App\SignupCheck::STAMP => $this->request->postStr(\App\SignupCheck::STAMP),
            \App\SignupCheck::ANSWER => $this->request->postStr(\App\SignupCheck::ANSWER),
        ]);
        if ($refusal !== null) return $this->registerError($mode, \App\Lang::t($refusal), $penname, $email);
        if ($password !== $this->request->postStr('confirm')) {
            return $this->registerError($mode, \App\Lang::t('auth.err_mismatch'), $penname, $email);
        }
        if (($blocked = \App\Features\Wordfilter\WordFilter::refusal($this->db, $penname)) !== null) return $this->registerError($mode, $blocked, $penname, $email);
        [$ok, $error] = $this->users->register($mode, $penname, $email, $password, $this->request->postStr('invite'));
        if (!$ok) {
            return $this->registerError($mode, $error, $penname, $email);
        }
        // Invite mode lands in the member directory immediately (verified +
        // approved at insert); verify/approval modes complete later, where those
        // paths purge too. A purge with no directory change is a no-op.
        (\App\StaticCache\Cache::configured($this->app))->purgeAuthors();
        if ($mode === 'verify') {
            $raw = $this->users->createVerification($email);
            $url = rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/') . '/auth/verify/' . $raw;
            [$subject, $body] = \App\Templates::get($this->db, 'member_verify', 'Verify your account',
                "Welcome to the archive. Confirm your address:\n\n{url}\n\nThe link is valid for 24 hours.");
            try {
                $this->mailer->send($email, $subject, strtr($body, ['{url}' => $url]), \App\MailGuard::CRITICAL);
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
            (\App\StaticCache\Cache::configured($this->app))->purgeAuthors();
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
            'terms_url' => \App\Notices::termsUrl((string) $this->app->config('signup_terms_url', '')),
            'penname' => $penname, 'email' => $email, 'invite' => $this->request->postStr('invite'),
        ] + $this->checkFields()), 422);
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
            ->withAddedHeader('Set-Cookie', \App\Cookie::long('lang', ''))
            ->withAddedHeader('Set-Cookie', \App\Cookie::pref(\App\Theme::COOKIE, ''));
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
                    $this->mailer->send($email, $subject, strtr($body, ['{url}' => $url]), \App\MailGuard::CRITICAL);
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
            return new Response($this->resetView($token, \App\Lang::t('auth.err_short')), 422);
        }
        // Read before resetPassword(), which deletes the row on success.
        $email = $this->db->one('SELECT email FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)])['email'] ?? null;
        if (!$this->auth->resetPassword($token, $password)) {
            return new Response($this->resetView($token, \App\Lang::t('auth.err_reset')), 422);
        }
        // Opening a link mailed to the address proves the member owns it, so a
        // completed reset also verifies the email. Without this a member who
        // never got the verification mail resets, then still cannot sign in.
        if ($email !== null) $this->markVerified('email = ?', (string) $email);
        return Response::redirect('/auth/login');
    }

    private function resetView(string $token, ?string $error): string
    {
        return $this->view->render('auth/reset', ['title' => \App\Lang::t('auth.reset.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('auth.reset.heading'))->withCanonical($this->request->path)->withNoindex(),
            'token' => $token, 'error' => $error]);
    }

    public function link(): Response
    {
        return $this->linkView(null, 200);
    }

    #[Post]
    public function send(): Response
    {
        $email = $this->request->postStr('email');
        $token = (new \App\LoginLinks($this->db))->request($email, $this->request->ip);
        if ($token !== null) {
            $url = \App\LoginLinks::url((string) $this->app->config('base_url', 'http://localhost:8080'), $token);
            [$subject, $body] = \App\Templates::get($this->db, 'signin_link', ...\App\Templates::pair('signin_link'));
            // Deferred past the response, like remind(): this send happens only
            // for real accounts, so its duration must not show in the timing.
            $this->app->defer(function () use ($email, $subject, $body, $url): void {
                try {
                    $this->mailer->send($email, $subject, strtr($body, ['{url}' => $url]), \App\MailGuard::CRITICAL);
                } catch (\Throwable $e) {
                    error_log("Sign-in link mail failed for a known address: {$e->getMessage()}");
                }
            });
        }
        // Same page whether the account exists or not, no enumeration.
        return $this->linkView('sent', 200);
    }

    /** The emailed link lands here. Opening it never signs in: mail scanners
     *  prefetch links, so the visitor presses a button that POSTs to enter(). */
    public function magic(): Response
    {
        $token = (string) ($this->request->get['t'] ?? '');
        if (!(new \App\LoginLinks($this->db))->peek($token)) return $this->linkView('expired', 410);
        $heading = \App\Lang::t('auth.link.magic_heading');
        $html = $this->view->render('auth/magic', ['title' => $heading,
            'head' => $this->head()->withTitle($heading)->withCanonical('/auth/magic')->withNoindex(),
            'csrf' => $this->session->csrfToken(), 'token' => $token]);
        // The page carries a live credential: never let it leave as a
        // Referer, and never let any cache keep it.
        return (new Response($html, 200))
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    #[Post]
    public function enter(): Response
    {
        $user = (new \App\LoginLinks($this->db))->consume($this->request->postStr('t'));
        if ($user === null) return $this->linkView('expired', 410);
        // The link went to the account's address, so using it verifies the
        // email, the same as the verification link would.
        if ($user['email_verified_at'] === null) {
            // Someone may have pre-registered this address with a password of
            // their own: verifying by link retires that password too.
            $changed = $this->db->query('UPDATE users SET email_verified_at = ?, password_hash = ? WHERE id = ? AND email_verified_at IS NULL',
                [date('c'), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), (int) $user['id']])->rowCount();
            if ($changed > 0) (\App\StaticCache\Cache::configured($this->app))->purgeAuthors();
            $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$user['id']]) ?? $user;
        }
        $refusal = match (true) {
            (int) $user['is_locked'] === 1 => \App\Lang::t('auth.err_locked'),
            $user['email_verified_at'] === null => \App\Lang::t('auth.err_unverified'),
            $user['approved_at'] === null => \App\Lang::t('auth.err_unapproved'),
            default => null,
        };
        if ($refusal !== null) {
            return new Response($this->view->render('auth/login', ['title' => \App\Lang::t('auth.login.heading'),
                'csrf' => $this->session->csrfToken(), 'error' => $refusal]), 403);
        }
        // Kip\Auth::establishSession's order (private upstream): a new session
        // id first, so a planted id never carries the signed-in state.
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $this->session->rotateCsrf();
        $this->session->set('user_id', (int) $user['id']);
        $this->session->set('pwd_epoch', substr((string) $user['password_hash'], 0, Auth::EPOCH_LEN));
        return $this->redirectWithPrefCookies((int) $user['id']);
    }

    /** Stamp email_verified_at once (never move an existing stamp). A newly
     *  verified, approved member joins the directory, as in verify(). */
    private function markVerified(string $where, int|string $value): void
    {
        $changed = $this->db->query("UPDATE users SET email_verified_at = ? WHERE {$where} AND email_verified_at IS NULL",
            [date('c'), $value])->rowCount();
        if ($changed > 0) (\App\StaticCache\Cache::configured($this->app))->purgeAuthors();
    }

    /** @param null|'sent'|'expired' $state */
    private function linkView(?string $state, int $status): Response
    {
        $title = \App\Lang::t('auth.link.title');
        return new Response($this->view->render('auth/link', ['title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/auth/link')->withNoindex(),
            'state' => $state]), $status);
    }

}
