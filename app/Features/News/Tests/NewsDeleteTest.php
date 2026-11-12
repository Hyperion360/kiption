<?php // app/Features/News/Tests/NewsDeleteTest.php
namespace App\Features\News\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Deleting a news post and the edit links that lead there. */
final class NewsDeleteTest extends TestCase
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
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-news-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-news-' . uniqid('', true);
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

    private function post(): int
    {
        $this->db->query('INSERT INTO news (author_id, title, body) VALUES (?, ?, ?)', [$this->adminId, 'Old news', 'Words.']);
        return (int) $this->db->lastInsertId();
    }

    public function test_admin_deletes_a_post_after_a_confirm_page(): void
    {
        $id = $this->post();
        $this->assertStringContainsString('href="/news/remove/' . $id . '"', $this->client($this->adminId)->get('/news/edit/' . $id)->body);
        $confirm = $this->client($this->adminId)->get('/news/remove/' . $id)->body;
        $this->assertStringContainsString('action="/news/delete/' . $id . '"', $confirm);
        $this->assertSame(302, $this->client($this->adminId)->postWithToken('/news/delete/' . $id)->status);
        $this->assertSame(404, $this->client()->get('/news/view/' . $id)->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM news WHERE id = ?', [$id])['c']);
    }

    public function test_admins_see_an_edit_link_on_the_post_and_others_do_not(): void
    {
        $id = $this->post();
        $link = 'href="/news/edit/' . $id . '"';
        $this->assertStringContainsString($link, $this->client($this->adminId)->get('/news/view/' . $id)->body);
        $this->assertStringNotContainsString($link, $this->client($this->heirId)->get('/news/view/' . $id)->body);
        $this->assertStringNotContainsString($link, $this->client()->get('/news/view/' . $id)->body);
    }

    public function test_only_admins_with_a_token_can_delete(): void
    {
        $id = $this->post();
        foreach ([$this->moderatorId, $this->heirId] as $who) {
            $this->assertSame(403, $this->client($who)->postWithToken('/news/delete/' . $id)->status);
            $this->assertSame(403, $this->client($who)->get('/news/remove/' . $id)->status);
        }
        $this->assertSame(403, $this->client($this->adminId)->post('/news/delete/' . $id)->status);
        $this->assertSame(404, $this->client($this->adminId)->postWithToken('/news/delete/99999')->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM news WHERE id = ?', [$id])['c']);
    }
}
