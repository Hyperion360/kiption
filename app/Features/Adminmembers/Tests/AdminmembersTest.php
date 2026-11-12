<?php // app/Features/Adminmembers/Tests/AdminmembersTest.php
namespace App\Features\Adminmembers\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Lock, unlock and delete a member from the roster. */
final class AdminmembersTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $targetId = 0;
    private int $heirId = 0;
    private int $moderatorId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-adminmembers-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-adminmembers-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->adminId = $this->user('roster-admin', 'admin');
        $this->targetId = $this->user('leaving-member', 'member');
        $this->heirId = $this->user('heir-member', 'member');
        $this->moderatorId = $this->user('roster-mod', 'moderator');
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES (?, ?, ?, ?, 2, 1)',
            ['Leaving Tale', 'leaving-tale', 'S.', $this->targetId]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function user(string $name, string $role): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ["$name@e.test", password_hash('password123', PASSWORD_DEFAULT), $name, $role, $role === 'admin' ? 1 : 0, date('c'), date('c'), $name]);
        return (int) $this->db->lastInsertId();
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    private function locked(int $id): int
    {
        return (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$id])['is_locked'];
    }

    public function test_roster_offers_lock_unlock_and_delete(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertStringContainsString('action="/adminmembers/lock/' . $this->targetId . '"', $body);
        $this->assertStringContainsString('href="/adminmembers/remove/' . $this->targetId . '"', $body);
        $this->assertStringNotContainsString('action="/adminmembers/lock/' . $this->adminId . '"', $body, 'no lock button on your own row');
        $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $this->targetId);
        $this->assertStringContainsString('action="/adminmembers/unlock/' . $this->targetId . '"', $this->client($this->adminId)->get('/adminmembers')->body);
    }

    public function test_locking_signs_out_a_member_who_is_already_in(): void
    {
        $other = $this->user('second-admin', 'admin');
        $session = $this->client($other);
        $this->assertSame(200, $session->get('/adminmembers')->status, 'signed in before the lock');
        $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $other);
        $res = $session->get('/adminmembers');
        $this->assertSame(302, $res->status, 'sent to sign in on the next request');
        $this->assertSame('/auth/login?locked=1', $res->headers['Location'] ?? null);
    }

    public function test_deleting_signs_out_a_member_who_is_already_in(): void
    {
        \App\Adminness::setRole($this->db, $this->heirId, 'admin');
        $heir = $this->client($this->heirId);
        $this->assertSame(200, $heir->get('/adminmembers')->status);
        $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->heirId, ['stories' => 'remove']);
        $this->assertSame(302, $heir->get('/adminmembers')->status);
    }

    public function test_locked_member_cannot_sign_in_and_unlock_restores_it(): void
    {
        $admin = $this->client($this->adminId);
        $this->assertSame(302, $admin->postWithToken('/adminmembers/lock/' . $this->targetId)->status);
        $this->assertSame(1, $this->locked($this->targetId));
        $res = $this->client()->post('/auth/attempt', ['email' => 'leaving-member@e.test', 'password' => 'password123']);
        $this->assertStringContainsString('This account is locked.', $res->body);
        $this->assertSame(302, $admin->postWithToken('/adminmembers/unlock/' . $this->targetId)->status);
        $this->assertSame(0, $this->locked($this->targetId));
        $this->assertSame(302, $this->client()->post('/auth/attempt', ['email' => 'leaving-member@e.test', 'password' => 'password123'])->status);
    }

    public function test_cannot_lock_yourself(): void
    {
        $this->assertSame(422, $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $this->adminId)->status);
        $this->assertSame(0, $this->locked($this->adminId));
    }

    public function test_non_admins_and_missing_tokens_are_refused(): void
    {
        foreach (['/adminmembers/lock/', '/adminmembers/unlock/', '/adminmembers/destroy/'] as $p) {
            $this->assertSame(403, $this->client($this->moderatorId)->postWithToken($p . $this->targetId)->status, $p);
            $this->assertSame(403, $this->client($this->heirId)->postWithToken($p . $this->targetId)->status, $p);
            $this->assertSame(403, $this->client($this->adminId)->post($p . $this->targetId)->status, $p . ' without a token');
        }
        $this->assertSame(403, $this->client($this->moderatorId)->get('/adminmembers/remove/' . $this->targetId)->status);
        $this->assertSame(0, $this->locked($this->targetId));
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM stories WHERE author_id = ? AND deleted_at IS NULL', [$this->targetId])['c']);
    }

    public function test_delete_page_asks_what_to_do_with_the_stories(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers/remove/' . $this->targetId)->body;
        $this->assertStringContainsString('action="/adminmembers/destroy/' . $this->targetId . '"', $body);
        $this->assertStringContainsString('name="stories" value="move"', $body);
        $this->assertStringContainsString('name="stories" value="remove"', $body);
        $this->assertStringContainsString('name="penname"', $body);
        $this->assertStringContainsString('Stories by this member: 1', $body);
    }

    public function test_delete_and_keep_moves_the_stories_to_another_member(): void
    {
        $res = $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => 'heir-member']);
        $this->assertSame(302, $res->status, $res->body);
        $story = $this->db->one("SELECT author_id, deleted_at FROM stories WHERE slug = 'leaving-tale'");
        $this->assertSame($this->heirId, (int) $story['author_id']);
        $this->assertNull($story['deleted_at']);
        $gone = $this->db->one('SELECT email, penname, is_locked, role FROM users WHERE id = ?', [$this->targetId]);
        $this->assertNull($gone['penname']);
        $this->assertSame(1, (int) $gone['is_locked']);
        $this->assertStringNotContainsString('leaving-member@e.test', $gone['email']);
        $this->assertSame(200, $this->client()->get('/story/view/leaving-tale')->status);
        $this->assertStringContainsString('Wrong email or password', $this->client()->post('/auth/attempt', ['email' => 'leaving-member@e.test', 'password' => 'password123'])->body);
    }

    public function test_delete_and_remove_soft_deletes_the_stories(): void
    {
        $res = $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'remove']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertNotNull($this->db->one("SELECT deleted_at FROM stories WHERE slug = 'leaving-tale'")['deleted_at']);
        $this->assertSame(404, $this->client()->get('/story/view/leaving-tale')->status);
        $this->assertNull($this->db->one('SELECT penname FROM users WHERE id = ?', [$this->targetId])['penname']);
    }

    public function test_delete_refuses_bad_choices_and_changes_nothing(): void
    {
        $admin = $this->client($this->adminId);
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->adminId, ['stories' => 'remove'])->status, 'not yourself');
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => 'no-such-person'])->status);
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => 'leaving-member'])->status, 'not to themselves');
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'sideways'])->status);
        $this->assertSame('leaving-member', $this->db->one('SELECT penname FROM users WHERE id = ?', [$this->targetId])['penname']);
        $this->assertSame(404, $admin->postWithToken('/adminmembers/destroy/99999', ['stories' => 'remove'])->status);
    }

    public function test_a_locked_admin_cannot_delete_anyone(): void
    {
        $other = $this->user('second-admin', 'admin');
        $this->db->query('UPDATE users SET is_locked = 1 WHERE id = ?', [$other]);
        // a locked admin is signed out, so the only working admin cannot be removed by them
        $res = $this->client($other)->postWithToken('/adminmembers/destroy/' . $this->adminId, ['stories' => 'remove']);
        $this->assertSame(302, $res->status);
        $this->assertSame('roster-admin', $this->db->one('SELECT penname FROM users WHERE id = ?', [$this->adminId])['penname']);
    }

    public function test_a_member_with_no_stories_can_be_deleted_without_a_choice(): void
    {
        $res = $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->heirId, ['stories' => 'remove']);
        $this->assertSame(302, $res->status);
        $this->assertNull($this->db->one('SELECT penname FROM users WHERE id = ?', [$this->heirId])['penname']);
    }
}
