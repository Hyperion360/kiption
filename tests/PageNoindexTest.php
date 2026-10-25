<?php // tests/PageNoindexTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The per-page noindex flag (pages.noindex, migration 045): the admin
 *  checkbox, the reader render (meta plus X-Robots-Tag, the empty-body
 *  idiom) on both /page/view and the marketing top-level spelling, and the
 *  sitemap's exclusion. */
final class PageNoindexTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-noidx-') . '.sqlite';
        $this->root = tempnam(sys_get_temp_dir(), 'kiption-noidx-');
        unlink($this->root);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('noidxadmin@e.test', ?, 'noidxadmin', 'admin', 1, ?, ?, 'noidxadmin')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->adminId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function insertPage(string $slug, int $noindex): void
    {
        $this->db->query('INSERT INTO pages (slug, title, body, noindex) VALUES (?, ?, ?, ?)',
            [$slug, 'Page ' . $slug, 'Body of ' . $slug . '.', $noindex]);
    }

    public function test_flag_set_renders_meta_and_header(): void
    {
        $this->insertPage('hidden', 1);
        $res = $this->client()->get('/page/view/hidden');
        $this->assertSame(200, $res->status);
        $this->assertSame('noindex', $res->headers['X-Robots-Tag'] ?? null);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $res->body);
    }

    public function test_flag_unset_renders_neither(): void
    {
        $this->insertPage('open', 0);
        $res = $this->client()->get('/page/view/open');
        $this->assertSame(200, $res->status);
        $this->assertArrayNotHasKey('X-Robots-Tag', $res->headers);
        $this->assertStringNotContainsString('content="noindex"', $res->body);
    }

    public function test_marketing_spelling_applies_the_flag_too(): void
    {
        // dmca is a TopPaths slug: it serves at /dmca through MarketingPage.
        $this->db->query('UPDATE pages SET noindex = 1, body = ? WHERE slug = ?', ['The DMCA page body.', 'dmca']);
        $res = $this->client()->get('/dmca');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertSame('noindex', $res->headers['X-Robots-Tag'] ?? null);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $res->body);
    }

    public function test_admin_form_round_trips_the_checkbox(): void
    {
        $admin = $this->client($this->adminId);
        $this->assertSame(302, $admin->postWithToken('/page/create',
            ['slug' => 'flagged', 'title' => 'Flagged', 'body' => 'Body.', 'noindex' => '1'])->status);
        $this->assertSame(1, (int) $this->db->one("SELECT noindex FROM pages WHERE slug = 'flagged'")['noindex']);
        $form = $admin->get('/page/edit/flagged')->body;
        $this->assertStringContainsString('name="noindex" value="1" checked', $form, 'the edit form prefills the box');
        // unchecked boxes never POST (the settings-board rule): update without it clears the flag
        $this->assertSame(302, $admin->postWithToken('/page/update/flagged',
            ['title' => 'Flagged', 'body' => 'Body.', 'noindex' => ''])->status);
        $this->assertSame(0, (int) $this->db->one("SELECT noindex FROM pages WHERE slug = 'flagged'")['noindex']);
        $this->assertStringNotContainsString('name="noindex" value="1" checked', $admin->get('/page/edit/flagged')->body);
    }

    public function test_sitemap_excludes_flagged_rows(): void
    {
        $this->insertPage('listed', 0);
        $this->insertPage('unlisted', 1);
        \App\Seo\Sitemap::writeAll($this->db, $this->root, 'https://archive.example', []);
        $pages = (string) file_get_contents($this->root . '/sitemap-pages.xml');
        $this->assertStringContainsString('https://archive.example/page/view/listed', $pages);
        $this->assertStringNotContainsString('unlisted', $pages, 'a noindex row is never advertised');
    }
}
