<?php // app/Features/Auth/Tests/AuthTest.php
namespace App\Features\Auth\Tests;
use Kip\App;
use Kip\Auth;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

// End-to-end coverage of the app's auth surface through the real routing,
// against the real 001_init schema: proves the migration stays compatible
// with the framework auth contract (users shape, login_attempts.kind,
// password_resets) that AuthController relies on.
final class AuthTest extends TestCase
{
    private App $app;
    private Database $db;

    protected function setUp(): void
    {
        $this->app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite::memory:'],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'base_url' => 'http://kiption.test',
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-auth-test.log', 'from' => 'noreply@kiption.test'],
        ]);
        $this->db = $this->app->container->make(Database::class);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    private function post(string $path, array $form): Response
    {
        $form['_token'] ??= $this->app->session->csrfToken();
        return $this->app->handle(new Request('POST', $path, [], $form, [], '127.0.0.1'));
    }

    private function registerUser(string $email = 'qa@kiption.test', string $password = 'password123'): void
    {
        (new Auth($this->db, $this->app->session))->register($email, $password);
        // Active by construction: the login gates (verified/approved) postdate this factory.
        $this->db->query('UPDATE users SET email_verified_at = ?, approved_at = ? WHERE email = ?',
            [date('c'), date('c'), $email]);
    }

    public function test_login_form_renders_with_csrf_and_labels(): void
    {
        $res = $this->app->handle(new Request('GET', '/auth/login', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('action="/auth/attempt"', $res->body);
        $this->assertStringContainsString('name="_token"', $res->body);
        $this->assertStringContainsString('autocomplete="current-password"', $res->body);
    }

    public function test_wrong_password_and_unknown_email_show_the_same_error(): void
    {
        $this->registerUser();
        $known = $this->post('/auth/attempt', ['email' => 'qa@kiption.test', 'password' => 'not-the-password']);
        $unknown = $this->post('/auth/attempt', ['email' => 'nobody@kiption.test', 'password' => 'whatever123']);
        $this->assertSame(200, $known->status);
        $this->assertSame($known->body, $unknown->body); // no account enumeration
        $this->assertStringContainsString('Wrong email or password', $known->body);
    }

    public function test_correct_credentials_redirect_home(): void
    {
        $this->registerUser();
        $res = $this->post('/auth/attempt', ['email' => 'qa@kiption.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location']);
    }

    public function test_logout_ends_the_session_and_redirects_home(): void
    {
        $this->registerUser();
        $this->post('/auth/attempt', ['email' => 'qa@kiption.test', 'password' => 'password123']);
        $this->assertSame(1, $this->app->session->peek('user_id'));
        $res = $this->post('/auth/logout', []);
        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location']);
        $this->assertNull($this->app->session->peek('user_id'));
    }

    public function test_sixth_failure_within_window_is_throttled(): void
    {
        $this->registerUser();
        for ($i = 0; $i < 5; $i++) {
            $res = $this->post('/auth/attempt', ['email' => 'qa@kiption.test', 'password' => 'wrong']);
            $this->assertSame(200, $res->status, "attempt {$i} should not be throttled yet");
        }
        $sixth = $this->post('/auth/attempt', ['email' => 'qa@kiption.test', 'password' => 'wrong']);
        $this->assertSame(429, $sixth->status);
        $this->assertStringContainsString('Too many attempts', $sixth->body);
    }

    public function test_password_reset_round_trip(): void
    {
        $this->registerUser();
        $token = (new Auth($this->db, $this->app->session))->createReset('qa@kiption.test', '127.0.0.1');
        $this->assertNotNull($token);

        $form = $this->app->handle(new Request('GET', "/auth/reset/{$token}", [], [], []));
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('Choose a new password', $form->body);

        $short = $this->post('/auth/confirm', ['token' => $token, 'password' => 'short']);
        $this->assertSame(422, $short->status);
        $this->assertStringContainsString('at least 8 characters', $short->body);

        $done = $this->post('/auth/confirm', ['token' => $token, 'password' => 'new-password-456']);
        $this->assertSame(302, $done->status);
        $this->assertSame('/auth/login', $done->headers['Location']);

        $relogin = $this->post('/auth/attempt', ['email' => 'qa@kiption.test', 'password' => 'new-password-456']);
        $this->assertSame(302, $relogin->status, 'new password must work immediately after reset');
    }

    public function test_forgot_page_is_identical_for_known_and_unknown_addresses(): void
    {
        $this->registerUser();
        $known = $this->post('/auth/remind', ['email' => 'qa@kiption.test']);
        $unknown = $this->post('/auth/remind', ['email' => 'ghost@kiption.test']);
        $this->assertSame(200, $known->status);
        $this->assertSame($known->body, $unknown->body);
        $this->assertStringContainsString('If that address has an account', $known->body);
    }
}
