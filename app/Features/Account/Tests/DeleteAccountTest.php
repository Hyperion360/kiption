<?php // app/Features/Account/Tests/DeleteAccountTest.php
namespace App\Features\Account\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Deleting your own account (/account/delete, /account/destroy). */
#[Medium]
final class DeleteAccountTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-del-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
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
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-del-mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-del-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function id(string $penname): int
    {
        return (int) $this->db->one('SELECT id FROM users WHERE penname = ?', [$penname])['id'];
    }

    private function as(string $penname): TestClient
    {
        return (new TestClient($this->app()))->actingAs($this->id($penname));
    }

    private function storiesOf(int $id): int
    {
        return (int) $this->db->one('SELECT COUNT(*) c FROM stories WHERE author_id = ?', [$id])['c'];
    }

    private function exists(string $penname): bool
    {
        return $this->db->one('SELECT 1 AS x FROM users WHERE penname = ?', [$penname]) !== null;
    }

    private function body(string $stories = 'keep', string $pw = 'password123'): array
    {
        return ['stories' => $stories, 'current_password' => $pw];
    }

    public function test_the_confirm_page_deletes_nothing_and_is_login_gated(): void
    {
        $page = $this->as('Demo Author')->get('/account/delete');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('action="/account/destroy"', $page->body);
        $this->assertTrue($this->exists('Demo Author'));
        $this->assertSame(302, (new TestClient($this->app()))->get('/account/delete')->status);
    }

    public function test_keep_moves_the_stories_to_anonymous(): void
    {
        $demo = $this->id('Demo Author');
        $before = $this->storiesOf($demo);
        $this->assertGreaterThan(0, $before);
        $res = $this->as('Demo Author')->postWithToken('/account/destroy', $this->body('keep'));
        $this->assertSame(302, $res->status, $res->body);
        $this->assertFalse($this->exists('Demo Author'));
        $anon = (int) $this->db->one('SELECT id FROM users WHERE email = ?', [\App\AccountSecurity::ANON_EMAIL])['id'];
        $this->assertSame($before, $this->storiesOf($anon));
        $row = $this->db->one('SELECT penname, email_verified_at, approved_at FROM users WHERE id = ?', [$anon]);
        $this->assertSame('Anonymous', $row['penname']);
        $this->assertNull($row['approved_at']);
        $this->assertFalse(password_verify('', (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$anon])['password_hash']));
    }

    public function test_remove_deletes_the_stories_too(): void
    {
        $demo = $this->id('Demo Author');
        $ids = array_column($this->db->all('SELECT id FROM stories WHERE author_id = ?', [$demo]), 'id');
        $this->assertNotEmpty($ids);
        $res = $this->as('Demo Author')->postWithToken('/account/destroy', $this->body('remove'));
        $this->assertSame(302, $res->status, $res->body);
        $this->assertFalse($this->exists('Demo Author'));
        foreach ($ids as $sid) $this->assertNull($this->db->one('SELECT 1 AS x FROM stories WHERE id = ?', [$sid]));
        $this->assertNull($this->db->one('SELECT 1 AS x FROM users WHERE email = ?', [\App\AccountSecurity::ANON_EMAIL]), 'no placeholder needed');
    }

    public function test_the_session_ends_and_the_old_password_no_longer_signs_in(): void
    {
        $me = $this->as('betafriend');
        $this->assertSame(302, $me->postWithToken('/account/destroy', $this->body('keep'))->status);
        $this->assertSame(302, $me->get('/account/settings')->status, 'bounced to sign in');
        $this->assertStringContainsString('Wrong email or password',
            (new TestClient($this->app()))->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123'])->body);
    }

    public function test_a_second_anonymous_run_reuses_the_placeholder(): void
    {
        $this->as('Demo Author')->postWithToken('/account/destroy', $this->body('keep'));
        $this->as('betafriend')->postWithToken('/account/destroy', $this->body('keep'));
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM users WHERE email = ?', [\App\AccountSecurity::ANON_EMAIL])['c']);
    }

    public function test_wrong_password_refuses_and_keeps_everything(): void
    {
        $res = $this->as('Demo Author')->postWithToken('/account/destroy', $this->body('remove', 'nope-nope'));
        $this->assertSame(422, $res->status);
        $this->assertTrue($this->exists('Demo Author'));
        $this->assertGreaterThan(0, $this->storiesOf($this->id('Demo Author')));
    }

    public function test_no_choice_and_bad_choice_are_refused(): void
    {
        $this->assertSame(422, $this->as('Demo Author')->postWithToken('/account/destroy', ['current_password' => 'password123'])->status);
        $this->assertSame(422, $this->as('Demo Author')->postWithToken('/account/destroy', $this->body('bogus'))->status);
        $this->assertTrue($this->exists('Demo Author'));
    }

    public function test_missing_csrf_and_guest_are_refused(): void
    {
        $this->assertSame(403, $this->as('Demo Author')->post('/account/destroy', $this->body())->status);
        $guest = (new TestClient($this->app()))->postWithToken('/account/destroy', $this->body());
        $this->assertSame('/auth/login', $guest->headers['Location'] ?? '');
        $this->assertTrue($this->exists('Demo Author'));
    }

    public function test_the_last_admin_cannot_delete_themselves(): void
    {
        $id = $this->id('betafriend');
        \App\Adminness::setRole($this->db, $id, 'admin');
        $this->assertStringContainsString('only admin', $this->as('betafriend')->get('/account/delete')->body);
        $res = $this->as('betafriend')->postWithToken('/account/destroy', $this->body('keep'));
        $this->assertSame(422, $res->status);
        $this->assertTrue($this->exists('betafriend'));
    }

    public function test_an_admin_with_another_admin_can_delete(): void
    {
        \App\Adminness::setRole($this->db, $this->id('betafriend'), 'admin');
        \App\Adminness::setRole($this->db, $this->id('Demo Author'), 'admin');
        $res = $this->as('betafriend')->postWithToken('/account/destroy', $this->body('keep'));
        $this->assertSame(302, $res->status, $res->body);
        $this->assertFalse($this->exists('betafriend'));
    }

    public function test_a_locked_second_admin_does_not_count(): void
    {
        \App\Adminness::setRole($this->db, $this->id('betafriend'), 'admin');
        \App\Adminness::setRole($this->db, $this->id('Demo Author'), 'admin');
        $this->db->query('UPDATE users SET is_locked = 1 WHERE penname = ?', ['Demo Author']);
        $this->assertSame(422, $this->as('betafriend')->postWithToken('/account/destroy', $this->body('keep'))->status);
    }

    public function test_repeated_wrong_passwords_are_throttled(): void
    {
        $me = $this->as('Demo Author');
        for ($i = 0; $i < 5; $i++) $me->postWithToken('/account/destroy', $this->body('remove', 'bad' . $i));
        $this->assertSame(429, $me->postWithToken('/account/destroy', $this->body('remove'))->status);
        $this->assertTrue($this->exists('Demo Author'));
    }
}
