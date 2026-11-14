<?php // app/Features/Account/Tests/PasswordChangeTest.php
namespace App\Features\Account\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Changing the password while signed in (/account/password). */
final class PasswordChangeTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-pw-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function app(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-pw-mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-pw-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function member(): TestClient
    {
        $id = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        return (new TestClient($this->app()))->actingAs($id);
    }

    private function form(array $over = []): array
    {
        return $over + ['current_password' => 'password123', 'new_password' => 'brand-new-pass', 'confirm_password' => 'brand-new-pass'];
    }

    private function hash(): string
    {
        return (string) $this->db->one("SELECT password_hash FROM users WHERE penname = 'betafriend'")['password_hash'];
    }

    public function test_the_form_is_on_the_settings_page(): void
    {
        $body = $this->member()->get('/account/settings')->body;
        $this->assertStringContainsString('action="/account/password"', $body);
    }

    public function test_success_lets_you_sign_in_with_the_new_password_only(): void
    {
        $me = $this->member();
        $res = $me->postWithToken('/account/password', $this->form());
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringContainsString('done=password', (string) ($res->headers['Location'] ?? ''));
        $this->assertTrue(password_verify('brand-new-pass', $this->hash()));
        $this->assertFalse(password_verify('password123', $this->hash()));
        $guest = new TestClient($this->app());
        $this->assertSame(302, $guest->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'brand-new-pass'])->status);
        $other = new TestClient($this->app());
        $this->assertStringContainsString('Wrong email or password', $other->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123'])->body);
    }

    public function test_this_session_stays_in_and_another_one_is_signed_out(): void
    {
        $me = $this->member();
        $elsewhere = $this->member();
        $this->assertSame(200, $elsewhere->get('/account/settings')->status);
        $me->postWithToken('/account/password', $this->form());
        $this->assertSame(200, $me->get('/account/settings')->status, 'the session that changed it stays in');
        $this->assertNotSame(200, $elsewhere->get('/account/settings')->status, 'the old session no longer works');
    }

    public function test_wrong_current_password_is_refused_and_nothing_changes(): void
    {
        $before = $this->hash();
        $res = $this->member()->postWithToken('/account/password', $this->form(['current_password' => 'nope-nope']));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('That is not your current password.', $res->body);
        $this->assertSame($before, $this->hash());
    }

    public function test_mismatched_confirmation_is_refused(): void
    {
        $before = $this->hash();
        $res = $this->member()->postWithToken('/account/password', $this->form(['confirm_password' => 'different-pass']));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('do not match', $res->body);
        $this->assertSame($before, $this->hash());
    }

    public function test_short_new_password_is_refused(): void
    {
        $res = $this->member()->postWithToken('/account/password', $this->form(['new_password' => 'short', 'confirm_password' => 'short']));
        $this->assertSame(422, $res->status);
        $this->assertTrue(password_verify('password123', $this->hash()));
    }

    public function test_missing_csrf_token_is_refused(): void
    {
        $res = $this->member()->post('/account/password', $this->form());
        $this->assertSame(403, $res->status);
        $this->assertTrue(password_verify('password123', $this->hash()));
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        $res = (new TestClient($this->app()))->postWithToken('/account/password', $this->form());
        $this->assertStringNotContainsString('done=password', (string) ($res->headers['Location'] ?? ''));
        $this->assertTrue(password_verify('password123', $this->hash()));
    }

    public function test_repeated_wrong_passwords_are_throttled_even_for_the_right_one(): void
    {
        $me = $this->member();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(422, $me->postWithToken('/account/password', $this->form(['current_password' => 'wrong' . $i]))->status);
        }
        $res = $me->postWithToken('/account/password', $this->form());
        $this->assertSame(429, $res->status);
        $this->assertTrue(password_verify('password123', $this->hash()), 'the throttled request changed nothing');
    }

    public function test_an_unused_sign_in_link_dies_with_the_password_change(): void
    {
        $id = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $links = new \App\LoginLinks($this->db);
        $token = $links->mint($id, 1800);
        $this->member()->postWithToken('/account/password', $this->form());
        $this->assertFalse($links->peek($token));
    }

    public function test_a_right_password_after_wrong_ones_still_works(): void
    {
        // checkPassword's contract: a wrong try spends a slot, a right one
        // clears them. 4 wrong + 2 right exceeds the cap unless success cleared it.
        $me = $this->member();
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(422, $me->postWithToken('/account/password', $this->form(['current_password' => 'wrong' . $i]))->status);
        }
        $this->assertSame(302, $me->postWithToken('/account/password', $this->form())->status, 'the right password clears the spent slots');
        $this->assertSame(302, $me->postWithToken('/account/password', $this->form(['current_password' => 'brand-new-pass', 'new_password' => 'brand-new-pass-2', 'confirm_password' => 'brand-new-pass-2']))->status, 'a second change in the same window succeeds');
    }
}
