<?php // tests/InboxTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class InboxTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-inbox-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        (new \App\Notifications($this->db))->create(1, 'kudos', 1, 1, 'The Rabbit Hole'); // actor 1 exists
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(int $as): TestClient
    {
        return (new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-inbox-upl'],  // AccountController autowires Storage
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->actingAs($as);
    }

    public function test_inbox_requires_login_and_lists(): void
    {
        $app = new App(['env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views', 'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:']]);
        $this->assertSame(302, (new TestClient($app))->get('/notifications')->status);
        $res = $this->client(1)->get('/notifications');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('unread', $res->body);
    }

    public function test_mark_all_read(): void
    {
        $client = $this->client(1);
        $res = $client->postWithToken('/notifications/read');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertNotNull($this->db->one('SELECT read_at FROM notifications WHERE user_id = 1')['read_at']);
    }

    public function test_layout_links_inbox_for_members(): void
    {
        $this->assertStringContainsString('href="/notifications"', $this->client(1)->get('/account')->body);
    }
}
