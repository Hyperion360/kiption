<?php // app/Features/Page/Tests/PageDeleteTest.php
namespace App\Features\Page\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Deleting a page; the pages the site links to by name are protected. */
final class PageDeleteTest extends TestCase
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
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-page-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-page-' . uniqid('', true);
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

    private function page(string $slug): void
    {
        $this->db->query('INSERT INTO pages (slug, title, body) VALUES (?, ?, ?)', [$slug, 'Spare page', 'Words.']);
    }

    private function has(string $slug): bool
    {
        return $this->db->one('SELECT slug FROM pages WHERE slug = ?', [$slug]) !== null;
    }

    public function test_admin_deletes_a_page_after_a_confirm_page(): void
    {
        $this->page('spare');
        $this->assertStringContainsString('href="/page/remove/spare"', $this->client($this->adminId)->get('/page/edit/spare')->body);
        $this->assertStringContainsString('action="/page/delete/spare"', $this->client($this->adminId)->get('/page/remove/spare')->body);
        $this->assertSame(302, $this->client($this->adminId)->postWithToken('/page/delete/spare')->status);
        $this->assertFalse($this->has('spare'));
        $this->assertSame(404, $this->client()->get('/page/view/spare')->status);
    }

    public function test_protected_pages_cannot_be_deleted(): void
    {
        foreach (['about', 'content-policy', 'dmca'] as $slug) {
            $this->db->query("INSERT OR IGNORE INTO pages (slug, title, body) VALUES (?, 'T', 'B')", [$slug]);
            $this->assertSame(422, $this->client($this->adminId)->postWithToken('/page/delete/' . $slug)->status, $slug);
            $this->assertTrue($this->has($slug), $slug);
            $this->assertStringNotContainsString('href="/page/remove/' . $slug . '"', $this->client($this->adminId)->get('/page/edit/' . $slug)->body, $slug);
            $this->assertStringNotContainsString('action="/page/delete/' . $slug . '"', $this->client($this->adminId)->get('/page/remove/' . $slug)->body, $slug);
        }
    }

    public function test_admins_see_an_edit_link_on_a_page_and_others_do_not(): void
    {
        $this->page('spare');
        $link = 'href="/page/edit/spare"';
        $this->assertStringContainsString($link, $this->client($this->adminId)->get('/page/view/spare')->body);
        $this->assertStringNotContainsString($link, $this->client($this->heirId)->get('/page/view/spare')->body);
        $this->assertStringNotContainsString($link, $this->client()->get('/page/view/spare')->body);
    }

    public function test_only_admins_with_a_token_can_delete(): void
    {
        $this->page('spare');
        foreach ([$this->moderatorId, $this->heirId] as $who) {
            $this->assertSame(403, $this->client($who)->postWithToken('/page/delete/spare')->status);
            $this->assertSame(403, $this->client($who)->get('/page/remove/spare')->status);
        }
        $this->assertSame(403, $this->client($this->adminId)->post('/page/delete/spare')->status);
        $this->assertSame(404, $this->client($this->adminId)->postWithToken('/page/delete/no-such')->status);
        $this->assertTrue($this->has('spare'));
    }
}
