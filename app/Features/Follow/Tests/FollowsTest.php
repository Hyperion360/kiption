<?php // app/Features/Follow/Tests/FollowsTest.php
namespace App\Features\Follow\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class FollowsTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-follow-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('f@e.test', ?, 'fanperson', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->memberId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(int $as): TestClient
    {
        return (new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-follow-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->actingAs($as);
    }

    public function test_follow_notifies_once_and_unfollow_refollow_stays_silent(): void
    {
        $client = $this->client($this->memberId);
        $res = $client->postWithToken('/follow/author/1');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM notifications WHERE kind = 'follow' AND user_id = 1")['c']);
        $client->postWithToken('/follow/author/1'); // idempotent, no second notification
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM notifications WHERE kind = 'follow' AND user_id = 1")['c']);
        $this->db->query('DELETE FROM follows'); // hard unfollow
        $client->postWithToken('/follow/author/1'); // re-follow: still silent (suppression check)
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM notifications WHERE kind = 'follow' AND user_id = 1")['c']);
    }

    public function test_no_self_follow(): void
    {
        $res = $this->client(1)->postWithToken('/follow/author/1');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM follows')['c']);
    }

    public function test_follow_unknown_author_404s(): void
    {
        // the follows FK would bubble a 500 error page for a crafted id;
        // the house contract for engagement writes is a 404, no row written
        $this->assertSame(404, $this->client($this->memberId)->postWithToken('/follow/author/99999')->status);
        $this->assertSame(404, $this->client($this->memberId)->postWithToken('/follow/author/zero')->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM follows')['c']);
    }

    public function test_notify_mode_cycles(): void
    {
        $client = $this->client($this->memberId);
        $client->postWithToken('/follow/author/1');
        $this->assertSame('site', $this->db->one('SELECT notify_mode FROM follows WHERE follower_id = ?', [$this->memberId])['notify_mode']);
        $client->postWithToken('/follow/mode/1');
        $this->assertSame('email', $this->db->one('SELECT notify_mode FROM follows WHERE follower_id = ?', [$this->memberId])['notify_mode']);
        $client->postWithToken('/follow/mode/1');
        $this->assertSame('digest', $this->db->one('SELECT notify_mode FROM follows WHERE follower_id = ?', [$this->memberId])['notify_mode']);
        $client->postWithToken('/follow/mode/1');
        $this->assertSame('site', $this->db->one('SELECT notify_mode FROM follows WHERE follower_id = ?', [$this->memberId])['notify_mode']);
    }

    public function test_account_lists_following(): void
    {
        $this->client($this->memberId)->postWithToken('/follow/author/1');
        $body = $this->client($this->memberId)->get('/account')->body;
        $this->assertStringContainsString('Demo Author', $body);
        $this->assertStringContainsString('notify mode: site', $body);
    }
}
