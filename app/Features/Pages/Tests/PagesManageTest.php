<?php // app/Features/Pages/Tests/PagesManageTest.php
namespace App\Features\Pages\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The admin pages list. */
final class PagesManageTest extends TestCase
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
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-pages-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-pages-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->adminId = $this->user('roster-admin', 'admin');
        $this->targetId = $this->user('leaving-member', 'member');
        $this->heirId = $this->user('heir-member', 'member');
        $this->moderatorId = $this->user('roster-mod', 'moderator');
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

    public function test_admin_sees_every_page_with_edit_and_delete_links(): void
    {
        $this->db->query("INSERT INTO pages (slug, title, body) VALUES ('spare', 'Spare page', 'x')");
        $this->db->query("INSERT OR IGNORE INTO pages (slug, title, body) VALUES ('about', 'About', 'x')");
        $res = $this->client($this->adminId)->get('/pages/manage');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="/page/edit/spare"', $res->body);
        $this->assertStringContainsString('href="/page/remove/spare"', $res->body);
        $this->assertStringContainsString('href="/page/edit/about"', $res->body);
        $this->assertStringNotContainsString('href="/page/remove/about"', $res->body, 'protected pages get no delete link');
        $this->assertStringContainsString('href="/page/new"', $res->body);
    }

    public function test_only_admins_can_open_it(): void
    {
        $this->assertSame(403, $this->client($this->moderatorId)->get('/pages/manage')->status);
        $this->assertSame(403, $this->client($this->heirId)->get('/pages/manage')->status);
        $this->assertNotSame(200, $this->client()->get('/pages/manage')->status);
    }
}
