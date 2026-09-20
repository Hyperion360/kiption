<?php
namespace App\Controllers;
use App\Repositories\UserRepository;
use Kip\{App, Auth, Mailer, Http\Request, Http\Response, Session, View};
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
        return $this->view->render('auth/login', ['title' => 'Log in', 'head' => $this->head()->withTitle('Log in')->withCanonical('/auth/login')->withNoindex(),
            'csrf' => $this->session->csrfToken(), 'error' => null,
            'verified' => isset($this->request->get['verified'])]);
    }

    #[Post]
    public function attempt(): Response|string
    {
        $email = $this->request->postStr('email');
        if ($this->auth->throttled($email, $this->request->ip)) {
            return new Response(
                $this->view->render('auth/login', ['title' => 'Log in', 'csrf' => $this->session->csrfToken(),
                    'error' => 'Too many attempts, try again in 15 minutes.']),
                429
            );
        }
        if ($this->auth->attempt($email, $this->request->postStr('password'), $this->request->ip)) {
            $user = $this->users->findByEmail($email);
            if ($user !== null) {
                if ((int) $user['is_locked'] === 1) {
                    $this->auth->logout();
                    return $this->view->render('auth/login', ['title' => 'Log in', 'csrf' => $this->session->csrfToken(),
                        'error' => 'This account is locked.']);
                }
                if ($user['email_verified_at'] === null) {
                    $this->auth->logout();
                    return $this->view->render('auth/login', ['title' => 'Log in', 'csrf' => $this->session->csrfToken(),
                        'error' => 'Please verify your email first (check the link we sent).']);
                }
                if ($user['approved_at'] === null) {
                    $this->auth->logout();
                    return $this->view->render('auth/login', ['title' => 'Log in', 'csrf' => $this->session->csrfToken(),
                        'error' => 'Your account is awaiting approval.']);
                }
            }
            return Response::redirect('/');
        }
        return $this->view->render('auth/login', ['title' => 'Log in', 'csrf' => $this->session->csrfToken(), 'error' => 'Wrong email or password']);
    }

    public function register(): string
    {
        return $this->view->render('auth/register', [
            'title' => 'Register',
            'head' => $this->head()->withTitle('Register')->withCanonical('/auth/register')->withNoindex(),
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
            try {
                $this->mailer->send($email, 'Verify your account',
                    "Welcome to the archive. Confirm your address:\n\n{$url}\n\nThe link is valid for 24 hours.");
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
            'title' => 'Register',
            'head' => $this->head()->withTitle('Register')->withCanonical('/auth/register')->withNoindex(),
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
        return Response::redirect('/');
    }

    public function forgot(): string
    {
        return $this->view->render('auth/forgot', ['title' => 'Reset password',
            'head' => $this->head()->withTitle('Reset password')->withCanonical('/auth/forgot')->withNoindex(), 'sent' => false]);
    }

    #[Post]
    public function remind(): string
    {
        $email = $this->request->postStr('email');
        $token = $this->auth->createReset($email, $this->request->ip);
        if ($token !== null) {
            $url = rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/') . "/auth/reset/{$token}";
            try {
                $this->mailer->send($email, 'Reset your password',
                    "Someone (hopefully you) asked to reset the password for this address.\n\n"
                    . "Reset link (valid 30 minutes):\n{$url}\n\nIf this wasn't you, ignore this email.");
            } catch (\Throwable $e) {
                // A mailer failure must not become an account-existence oracle: the page
                // is identical either way, and the failure lands in the server log.
                error_log("Password-reset mail failed for a known address: {$e->getMessage()}");
            }
        }
        // Same page whether the account exists or not, no enumeration.
        return $this->view->render('auth/forgot', ['title' => 'Reset password', 'sent' => true]);
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
        return $this->view->render('auth/reset', ['title' => 'Choose a new password',
            'head' => $this->head()->withTitle('Choose a new password')->withCanonical($this->request->path)->withNoindex(),
            'token' => $token, 'error' => $error]);
    }
}
