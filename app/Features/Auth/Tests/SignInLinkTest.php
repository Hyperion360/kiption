<?php // app/Features/Auth/Tests/SignInLinkTest.php
namespace App\Features\Auth\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\LoginLinks;
use Kip\App;
use Kip\Auth;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

// One-time sign-in links: the token lifecycle in App\LoginLinks and the four
// routes that use it (/auth/link, /auth/send, /auth/magic, /auth/enter).
#[Medium]
final class SignInLinkTest extends TestCase
{
    private App $app;
    private Database $db;

    protected function setUp(): void
    {
        $this->boot([]);
    }

    private function boot(array $extra): void
    {
        $this->app = new App($extra + [
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite::memory:'],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'base_url' => 'http://kiption.test',
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-signin-link-test.log', 'from' => 'noreply@kiption.test'],
        ]);
        $this->db = $this->app->container->make(Database::class);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    private function get(string $path, array $query = []): Response
    {
        return $this->app->handle(new Request('GET', $path, $query, [], []));
    }

    private function post(string $path, array $form): Response
    {
        $form['_token'] ??= $this->app->session->csrfToken();
        return $this->app->handle(new Request('POST', $path, [], $form, [], '127.0.0.1'));
    }

    private function registerUser(string $email = 'qa@kiption.test', string $password = 'password123'): int
    {
        (new Auth($this->db, $this->app->session))->register($email, $password);
        $this->db->query('UPDATE users SET email_verified_at = ?, approved_at = ? WHERE email = ?',
            [date('c'), date('c'), $email]);
        return (int) $this->db->one('SELECT id FROM users WHERE email = ?', [$email])['id'];
    }

    private function links(): LoginLinks
    {
        return new LoginLinks($this->db);
    }

    // --- the token lifecycle ---

    public function test_an_overlong_address_is_refused_before_it_is_stored(): void
    {
        $this->assertNull($this->links()->request(str_repeat('a', 300) . '@example.test', '203.0.113.9'));
        $this->assertNull($this->db->one('SELECT 1 AS x FROM login_attempts WHERE length(email) > 254'));
    }

    public function test_a_minted_link_signs_in_once(): void
    {
        $id = $this->registerUser();
        $token = $this->links()->mint($id, 1800);
        $this->assertSame(64, strlen($token));
        $this->assertTrue($this->links()->peek($token));
        $this->assertSame($id, (int) $this->links()->consume($token)['id']);
        $this->assertNull($this->links()->consume($token));
        $this->assertFalse($this->links()->peek($token));
    }

    public function test_the_database_never_holds_the_raw_token(): void
    {
        $token = $this->links()->mint($this->registerUser(), 1800);
        $row = $this->db->one('SELECT token_hash FROM login_links');
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
    }

    public function test_an_expired_link_is_refused_and_removed(): void
    {
        $token = $this->links()->mint($this->registerUser(), 1800);
        $this->db->query('UPDATE login_links SET expires_at = ?', [date('c', time() - 1)]);
        $this->assertFalse($this->links()->peek($token));
        $this->assertNull($this->links()->consume($token));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM login_links')['c']);
    }

    public function test_a_password_change_voids_outstanding_links(): void
    {
        $id = $this->registerUser();
        $token = $this->links()->mint($id, 1800);
        $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?', [\App\Tests\Support\Hashes::of('another-pass1'), $id]);
        $this->assertFalse($this->links()->peek($token));
        $this->assertNull($this->links()->consume($token));
    }

    public function test_minting_replaces_the_previous_link(): void
    {
        $id = $this->registerUser();
        $first = $this->links()->mint($id, 1800);
        $second = $this->links()->mint($id, 1800);
        $this->assertNull($this->links()->consume($first));
        $this->assertSame($id, (int) $this->links()->consume($second)['id']);
    }

    public function test_the_lifetime_is_clamped_to_seven_days(): void
    {
        $this->links()->mint($this->registerUser(), 365 * 86400);
        $expires = strtotime((string) $this->db->one('SELECT expires_at FROM login_links')['expires_at']);
        $this->assertLessThanOrEqual(time() + LoginLinks::MAX_TTL, $expires);
        $this->assertGreaterThan(time() + LoginLinks::MAX_TTL - 60, $expires);
    }

    public function test_minting_for_a_missing_user_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->links()->mint(999, 1800);
    }

    public function test_malformed_tokens_are_refused(): void
    {
        foreach (['', '../x', str_repeat('A', 64), str_repeat('a', 63)] as $bad) {
            $this->assertFalse($this->links()->peek($bad));
            $this->assertNull($this->links()->consume($bad));
        }
    }

    public function test_request_is_throttled_per_address_and_silent_for_unknown_addresses(): void
    {
        $this->registerUser('qa@kiption.test');
        $this->assertNull($this->links()->request('nobody@kiption.test', '10.0.0.1'));
        for ($i = 0; $i < 3; $i++) $this->assertNotNull($this->links()->request('qa@kiption.test', '10.0.0.' . ($i + 2)));
        $this->assertNull($this->links()->request('qa@kiption.test', '10.0.0.9'));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM login_attempts WHERE kind = 'link' AND email = 'nobody@kiption.test'")['c']);
    }

    public function test_request_throttle_ignores_email_case_and_padding(): void
    {
        $this->registerUser('qa@kiption.test');
        foreach (['QA@kiption.test', ' Qa@Kiption.test', 'qa@kiption.TEST'] as $i => $e) {
            $this->assertNotNull($this->links()->request($e, '10.0.1.' . $i));
        }
        $this->assertNull($this->links()->request('qa@kiption.test', '10.0.1.9'));
    }

    public function test_request_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) $this->links()->request("n{$i}@kiption.test", '10.0.0.1');
        $this->registerUser('qa@kiption.test');
        $this->assertNull($this->links()->request('qa@kiption.test', '10.0.0.1'));
        $this->assertNotNull($this->links()->request('qa@kiption.test', '10.0.0.2'));
    }

    public function test_link_throttle_rows_leave_the_password_login_budget_alone(): void
    {
        $this->registerUser('qa@kiption.test');
        for ($i = 0; $i < 3; $i++) $this->links()->request('qa@kiption.test', '127.0.0.1');
        $this->assertFalse((new Auth($this->db, $this->app->session))->throttled('qa@kiption.test', '127.0.0.1'));
    }

    // --- the routes ---

    public function test_the_click_through_page_shows_a_button_and_does_not_consume(): void
    {
        $token = $this->links()->mint($this->registerUser(), 1800);
        $res = $this->get('/auth/magic', ['t' => $token]);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('action="/auth/enter"', $res->body);
        $this->assertStringContainsString('name="_token"', $res->body);
        $this->assertStringContainsString('value="' . $token . '"', $res->body);
        $this->assertSame('no-referrer', $res->headers['Referrer-Policy']);
        $this->assertSame('private, no-store', $res->headers['Cache-Control']);
        $this->assertTrue($this->links()->peek($token));
    }

    public function test_the_click_through_page_is_never_stored_by_the_page_cache(): void
    {
        $this->boot(['cache_db' => ['dsn' => 'sqlite::memory:']]);
        $token = $this->links()->mint($this->registerUser(), 1800);
        $first = $this->get('/auth/magic', ['t' => $token]);
        $second = $this->get('/auth/magic', ['t' => $token]);
        $this->assertNotSame('HIT', $first->headers['X-Kip-Cache'] ?? null);
        $this->assertNotSame('HIT', $second->headers['X-Kip-Cache'] ?? null);
        // Control: the cache is live in this app, so a page that stores does HIT.
        $this->get('/auth/link');
        $this->assertSame('HIT', $this->get('/auth/link')->headers['X-Kip-Cache'] ?? null);
    }

    public function test_entering_signs_in_and_the_link_cannot_be_reused(): void
    {
        $id = $this->registerUser();
        $token = $this->links()->mint($id, 1800);
        $res = $this->post('/auth/enter', ['t' => $token]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location']);
        $this->assertSame($id, (int) $this->app->session->peek('user_id'));
        $this->assertTrue((new Auth($this->db, $this->app->session))->sessionValid());
        $again = $this->post('/auth/enter', ['t' => $token]);
        $this->assertSame(410, $again->status);
        $this->assertStringContainsString('action="/auth/send"', $again->body);
    }

    public function test_an_expired_link_offers_a_new_one(): void
    {
        $res = $this->get('/auth/magic', ['t' => str_repeat('a', 64)]);
        $this->assertSame(410, $res->status);
        $this->assertStringContainsString('expired or was already used', $res->body);
        $this->assertStringContainsString('action="/auth/send"', $res->body);
    }

    public function test_a_missing_token_shows_the_expired_page(): void
    {
        $this->assertSame(410, $this->get('/auth/magic')->status);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'locked' => ['UPDATE users SET is_locked = 1 WHERE id = ?', 'This account is locked.'];
        yield 'unapproved' => ['UPDATE users SET approved_at = NULL WHERE id = ?', 'Your account is awaiting approval.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_blocked_accounts_get_the_password_login_refusal(string $sql, string $message): void
    {
        $id = $this->registerUser();
        $this->db->query($sql, [$id]);
        $token = $this->links()->mint($id, 1800);
        $res = $this->post('/auth/enter', ['t' => $token]);
        $this->assertSame(403, $res->status);
        $this->assertStringContainsString($message, $res->body);
        $this->assertNull($this->app->session->peek('user_id'));
    }

    public function test_using_a_link_verifies_an_unverified_email(): void
    {
        $id = $this->registerUser();
        $this->db->query('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$id]);
        $res = $this->post('/auth/enter', ['t' => $this->links()->mint($id, 1800)]);
        $this->assertSame(302, $res->status);
        $this->assertSame($id, (int) $this->app->session->peek('user_id'));
        $this->assertNotNull($this->db->one('SELECT email_verified_at FROM users WHERE id = ?', [$id])['email_verified_at']);
    }

    public function test_a_link_that_verifies_an_account_replaces_the_pre_registered_password(): void
    {
        $id = $this->registerUser('victim@kiption.test', 'squatter-password1');
        $this->db->query('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$id]);
        $old = (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash'];
        $res = $this->post('/auth/enter', ['t' => $this->links()->mint($id, 1800)]);
        $this->assertSame(302, $res->status);
        $new = (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash'];
        $this->assertNotSame($old, $new);
        $this->assertFalse(password_verify('squatter-password1', $new));
        $this->assertSame($id, (int) $this->app->session->peek('user_id'));
        $this->assertSame(substr($new, 0, Auth::EPOCH_LEN), $this->app->session->peek('pwd_epoch'));
        $this->assertTrue((new Auth($this->db, $this->app->session))->sessionValid());
    }

    public function test_a_link_for_an_already_verified_account_keeps_its_password(): void
    {
        $id = $this->registerUser();
        $old = (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash'];
        $this->post('/auth/enter', ['t' => $this->links()->mint($id, 1800)]);
        $this->assertSame($old, (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash']);
    }

    public function test_a_completed_password_reset_verifies_the_email(): void
    {
        $id = $this->registerUser();
        $this->db->query('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$id]);
        $token = (new Auth($this->db, $this->app->session))->createReset('qa@kiption.test', '127.0.0.1');
        $this->assertSame(302, $this->post('/auth/confirm', ['token' => $token, 'password' => 'a-new-password1'])->status);
        $this->assertNotNull($this->db->one('SELECT email_verified_at FROM users WHERE id = ?', [$id])['email_verified_at']);
        // The member the reset was for can now sign in with the new password.
        $this->assertSame(302, $this->post('/auth/attempt', ['email' => 'qa@kiption.test', 'password' => 'a-new-password1'])->status);
    }

    public function test_a_failed_reset_verifies_nothing(): void
    {
        $id = $this->registerUser();
        $this->db->query('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$id]);
        $this->post('/auth/confirm', ['token' => str_repeat('b', 64), 'password' => 'a-new-password1']);
        $this->assertNull($this->db->one('SELECT email_verified_at FROM users WHERE id = ?', [$id])['email_verified_at']);
    }

    public function test_send_answers_the_same_page_for_known_and_unknown_addresses(): void
    {
        $this->registerUser();
        $known = $this->post('/auth/send', ['email' => 'qa@kiption.test']);
        $unknown = $this->post('/auth/send', ['email' => 'nobody@kiption.test']);
        $this->assertSame(200, $known->status);
        $this->assertSame($known->body, $unknown->body);
        $this->assertStringContainsString('a sign-in link is on its way', $known->body);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM login_links')['c']);
    }

    public function test_the_request_form_posts_to_send(): void
    {
        $res = $this->get('/auth/link');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('action="/auth/send"', $res->body);
    }

    public function test_the_login_page_offers_a_sign_in_link(): void
    {
        $this->assertStringContainsString('href="/auth/link"', $this->get('/auth/login')->body);
    }

    public function test_the_token_never_reaches_the_request_log(): void
    {
        $token = $this->links()->mint($this->registerUser(), 1800);
        $this->get('/auth/magic', ['t' => $token]);
        $this->post('/auth/enter', ['t' => $token]);
        $rows = $this->app->container->make(\Kip\RequestLog::class)->recent(10);
        $this->assertContains('/auth/magic', array_column($rows, 'path'), 'the visit was logged, so the check below is real');
        foreach ($rows as $row) {
            $this->assertStringNotContainsString($token, (string) json_encode($row));
        }
    }

    public function test_the_url_uses_the_query_string(): void
    {
        $this->assertSame('https://a.test/auth/magic?t=abc', LoginLinks::url('https://a.test/', 'abc'));
    }


    public function test_request_finds_an_account_whose_stored_email_has_uppercase(): void
    {
        $id = $this->registerUser('mixed@kiption.test');
        $this->db->query("UPDATE users SET email = 'Mixed@Kiption.test' WHERE id = ?", [$id]);
        $this->assertNotNull($this->links()->request('mixed@kiption.test', '10.0.3.1'));
    }
}
