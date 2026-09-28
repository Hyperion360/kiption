<?php // tests/FavoritesTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class FavoritesTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $fanId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-fav-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('fav2@e.test', ?, 'favorfan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->fanId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-fav-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_favorite_requires_login(): void
    {
        // gate before CSRF since kip b29e269: a tokenless guest POST to an
        // #[Auth]+#[Post] route gets the login 302 whatever the token, and
        // the redirect precedes all controller code, so nothing is toggled
        $this->assertSame(302, $this->client()->post('/favorites/toggle/the-rabbit-hole')->status);
        $this->assertSame(0, (int) $this->db->one(
            "SELECT COUNT(*) c FROM favorites WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole')")['c'], 'no favorite written by a guest');
    }

    public function test_favorite_toggle_and_author_notification(): void
    {
        $client = $this->client($this->fanId);
        $res = $client->postWithToken('/favorites/toggle/the-rabbit-hole');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM favorites WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole')")['c']);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE kind = 'favorite' AND user_id = 1")['c']);
        $body = $client->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Favorites: 1', $body);
        $this->assertStringContainsString('In your favorites', $body);
        $res = $client->postWithToken('/favorites/toggle/the-rabbit-hole'); // toggle off
        $this->assertSame(302, $res->status);
        $this->assertSame(0, (int) $this->db->one(
            "SELECT COUNT(*) c FROM favorites WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole')")['c']);
        // notification fires on add only: the off-toggle must not have spoken
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE kind = 'favorite' AND user_id = 1")['c']);
    }

    public function test_favorites_page_lists_stories(): void
    {
        $client = $this->client($this->fanId);
        $client->postWithToken('/favorites/toggle/the-rabbit-hole');
        $res = $client->get('/favorites');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('name="robots" content="noindex"', $res->body);
    }

    public function test_favorites_shelf_plan_is_sort_index_backed(): void
    {
        // favoritesRows orders one member's shelf by created_at DESC; without
        // an ordered index SQLite materializes a TEMP B-TREE per render. The
        // (user_id, created_at DESC) index walks the shelf pre-sorted, same
        // standard the inbox shape set with idx_notifications_user.
        $plan = $this->db->all(
            'EXPLAIN QUERY PLAN SELECT s.slug, s.title, s.summary, s.updated_at,
                    (SELECT COUNT(*) FROM story_kudos k WHERE k.story_id = s.id) AS kudos_count
             FROM favorites f JOIN stories s ON s.id = f.story_id
             WHERE f.user_id = ? AND s.deleted_at IS NULL
             ORDER BY f.created_at DESC LIMIT 100', [$this->fanId]);
        $text = implode(' ', array_column($plan, 'detail'));
        $this->assertStringNotContainsString('SCAN', $text);
        $this->assertStringNotContainsString('TEMP B-TREE', $text);
    }
}
