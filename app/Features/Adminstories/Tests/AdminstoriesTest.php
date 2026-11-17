<?php // app/Features/Adminstories/Tests/AdminstoriesTest.php
namespace App\Features\Adminstories\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The owner box on the story page: feature, stop featuring, move to another author. */
#[Medium]
final class AdminstoriesTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;
    private int $moderatorId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-adminstories-');
        $this->root = sys_get_temp_dir() . '/kiption-adminstories-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $hash = \App\Tests\Support\Hashes::of('password123');
        foreach ([['admin', 'admin', 1], ['member', 'member', 0], ['moderator', 'moderator', 0]] as [$name, $role, $isAdmin]) {
            $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                ["$name@e.test", $hash, "box$name", $role, $isAdmin, date('c'), date('c'), "box$name"]);
            $this->{$name . 'Id'} = (int) $this->db->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
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

    public function test_story_page_shows_owner_box_to_admin_only(): void
    {
        $admin = $this->client($this->adminId)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('action="/adminstories/featured/the-rabbit-hole"', $admin);
        $this->assertStringContainsString('Feature on home page', $admin);
        $this->assertStringContainsString('action="/adminstories/reassign/the-rabbit-hole"', $admin);
        foreach ([$this->memberId, $this->moderatorId, null] as $who) {
            $body = $this->client($who)->get('/story/view/the-rabbit-hole')->body;
            $this->assertStringNotContainsString('/adminstories/', $body);
        }
    }

    public function test_feature_and_unfeature_show_and_hide_the_home_shelf(): void
    {
        $admin = $this->client($this->adminId);
        $this->assertSame(302, $admin->postWithToken('/adminstories/featured/the-rabbit-hole')->status);
        $home = $this->client()->get('/')->body;
        $this->assertStringContainsString('id="home-featured"', $home);
        $this->assertStringContainsString('The Rabbit Hole', $home);
        $this->assertStringContainsString('Stop featuring', $admin->get('/story/view/the-rabbit-hole')->body);
        $this->assertSame(302, $admin->postWithToken('/adminstories/featured/the-rabbit-hole')->status);
        $this->assertStringNotContainsString('id="home-featured"', $this->client()->get('/')->body);
    }

    public function test_move_changes_the_author_and_both_author_pages(): void
    {
        $admin = $this->client($this->adminId);
        $this->assertSame(302, $admin->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        $this->assertSame('betafriend', $this->db->one('SELECT u.penname FROM users u JOIN stories s ON s.author_id = u.id WHERE s.slug = ?', ['the-rabbit-hole'])['penname']);
        $newPage = $this->client()->get('/user/stories/betafriend')->body;
        $this->assertStringContainsString('The Rabbit Hole', $newPage);
        $slug = (string) $this->db->one("SELECT profile_slug FROM users WHERE penname = 'Demo Author'")['profile_slug'];
        $this->assertStringNotContainsString('The Rabbit Hole', $this->client()->get('/user/stories/' . $slug)->body);
    }

    public function test_unknown_penname_is_rejected_and_nothing_moves(): void
    {
        $before = $this->db->one("SELECT author_id FROM stories WHERE slug = 'the-rabbit-hole'")['author_id'];
        $this->assertSame(404, $this->client($this->adminId)->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'nobody-here'])->status);
        $this->assertSame(404, $this->client($this->adminId)->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => ''])->status);
        $this->assertEquals($before, $this->db->one("SELECT author_id FROM stories WHERE slug = 'the-rabbit-hole'")['author_id']);
    }

    public function test_non_admins_get_403_and_missing_token_is_rejected(): void
    {
        foreach ([$this->memberId, $this->moderatorId] as $who) {
            $this->assertSame(403, $this->client($who)->postWithToken('/adminstories/featured/the-rabbit-hole')->status);
            $this->assertSame(403, $this->client($who)->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        }
        $res = $this->client($this->adminId)->post('/adminstories/featured/the-rabbit-hole');
        $this->assertSame(403, $res->status);
        $this->assertSame(0, (int) $this->db->one("SELECT featured FROM stories WHERE slug = 'the-rabbit-hole'")['featured']);
    }
}
