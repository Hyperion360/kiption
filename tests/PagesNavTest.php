<?php // tests/PagesNavTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class PagesNavTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminUserId = 0;
    private int $memberUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-nav-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-nav-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        // admin + member fixtures, the SeriesRepositoryTest idiom
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('navadmin@e.test', ?, 'navadmin', 'admin', 1, ?, ?, 'navadmin')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('navmember@e.test', ?, 'navmember', ?, ?, 'navmember')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->memberUserId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function navFile(): string
    {
        return $this->root . '/nav.json';
    }

    private function adminId(): int
    {
        return $this->adminUserId;
    }

    private function memberId(): int
    {
        return $this->memberUserId;
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->navFile(),
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_nav_artifact_rebuilds_and_reads(): void
    {
        $file = $this->root . '/nav.json';
        $db = $this->db();
        $this->assertSame([], \App\NavLinks::all($file), 'missing file reads empty');
        $this->assertSame([], \App\NavLinks::all(''), 'empty path reads empty (review blocker 1: file_get_contents would throw)');
        $db->query('INSERT INTO nav_links (label, url, position, is_hidden) VALUES (?,?,?,?)', ['About', '/page/about', 1, 0]);
        $db->query('INSERT INTO nav_links (label, url, position, is_hidden) VALUES (?,?,?,?)', ['Hidden', '/page/x', 2, 1]);
        \App\NavLinks::rebuild($db, $file);
        $this->assertSame([['label' => 'About', 'url' => '/page/about']], \App\NavLinks::all($file), 'hidden links skipped, ordered');
        // corrupt file reads empty, never errors
        file_put_contents($file, '{not json');
        $this->assertSame([], \App\NavLinks::all($file));
    }

    public function test_layout_renders_dynamic_links_and_admin_can_manage_them(): void
    {
        $db = $this->db();
        $db->query('INSERT INTO nav_links (label, url, position) VALUES (?,?,?)', ['About', '/page/about', 1]);
        \App\NavLinks::rebuild($db, $this->navFile());
        $body = $this->client()->get('/browse')->body;
        $this->assertStringContainsString('href="/page/about"', $body);
        $this->assertStringContainsString('About', $body);
        // admin management: create + the artifact rebuilds
        $admin = $this->client($this->adminId());
        $res = $admin->postWithToken('/nav/create', ['label' => 'Rules', 'url' => '/page/rules', 'position' => '2', 'is_hidden' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame([['label' => 'About', 'url' => '/page/about'], ['label' => 'Rules', 'url' => '/page/rules']],
            \App\NavLinks::all($this->navFile()));
        // junk url rejected; member and guest barred
        $this->assertSame(422, $admin->postWithToken('/nav/create', ['label' => 'X', 'url' => 'javascript:alert(1)', 'position' => '3', 'is_hidden' => ''])->status);
        $this->assertSame(302, $this->client()->get('/nav')->status, 'auth redirect');
        $this->assertSame(403, $this->client($this->memberId())->post('/nav/create', ['label' => 'X', 'url' => '/x', 'position' => '1', 'is_hidden' => ''])->status, 'CSRF-before-auth 403');
    }

    public function test_nav_edit_update_delete_and_hidden_flag(): void
    {
        $db = $this->db();
        $db->query('INSERT INTO nav_links (label, url, position) VALUES (?,?,?)', ['About', '/page/about', 1]);
        \App\NavLinks::rebuild($db, $this->navFile());
        $admin = $this->client($this->adminId());
        $id = (int) $db->one("SELECT id FROM nav_links WHERE label = 'About'")['id'];
        $this->assertSame(200, $admin->get('/nav/edit/' . $id)->status);
        // label cap, junk position coerced, is_hidden checkbox honored
        $this->assertSame(422, $admin->postWithToken('/nav/create', ['label' => str_repeat('x', 41), 'url' => '/x', 'position' => '1', 'is_hidden' => ''])->status);
        $this->assertSame(302, $admin->postWithToken('/nav/update/' . $id, ['label' => 'About Us', 'url' => '/page/about-us', 'position' => 'junk', 'is_hidden' => '1'])->status);
        $row = $db->one('SELECT label, url, position, is_hidden FROM nav_links WHERE id = ?', [$id]);
        $this->assertSame('About Us', $row['label']);
        $this->assertSame('/page/about-us', $row['url']);
        $this->assertSame(0, (int) $row['position'], 'junk position coerces to 0');
        $this->assertSame(1, (int) $row['is_hidden']);
        $this->assertSame([], \App\NavLinks::all($this->navFile()), 'hidden link drops out of the artifact');
        // the index lists hidden links too
        $this->assertStringContainsString('About Us', $admin->get('/nav')->body);
        // members are barred from the admin surface even with a valid token
        $this->assertSame(403, $this->client($this->memberId())->get('/nav')->status);
        $this->assertSame(403, $this->client($this->memberId())->postWithToken('/nav/delete/' . $id)->status);
        // delete removes the row and rebuilds the artifact
        $this->assertSame(302, $admin->postWithToken('/nav/delete/' . $id)->status);
        $this->assertSame([], $db->all('SELECT * FROM nav_links'));
        $this->assertSame([], \App\NavLinks::all($this->navFile()), 'artifact rebuilt after delete');
        // unknown ids 404
        $this->assertSame(404, $admin->get('/nav/edit/99999')->status);
        $this->assertSame(404, $admin->postWithToken('/nav/update/99999', ['label' => 'X', 'url' => '/x', 'position' => '1', 'is_hidden' => ''])->status);
    }

    public function test_auth_page_renders_nav_free_at_200(): void
    {
        // the recorded auth-page nav gap: AuthController omits navFile (like it
        // omits theme), so auth surfaces keep the minimal chrome; finding 1's
        // guard makes the omission safe by construction, pinned here.
        $db = $this->db();
        $db->query('INSERT INTO nav_links (label, url, position) VALUES (?,?,?)', ['About', '/page/about', 1]);
        \App\NavLinks::rebuild($db, $this->navFile());
        $res = $this->client()->get('/auth/login');
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('href="/page/about"', $res->body);
        $this->assertStringContainsString('href="/browse"', $res->body); // hardcoded layout links still render
    }
}
