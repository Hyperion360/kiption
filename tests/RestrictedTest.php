<?php // tests/RestrictedTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class RestrictedTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rest-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function app(array $overrides = []): App
    {
        return new App(array_merge([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-rest-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-rest-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides));
    }

    public function test_guests_get_404_members_read(): void
    {
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(404, (new TestClient($this->app()))->get('/story/view/the-rabbit-hole')->status);
        $this->assertSame(404, (new TestClient($this->app()))->get('/story/read/the-rabbit-hole/1')->status);
        $res = (new TestClient($this->app()))->actingAs(1)->get('/story/view/the-rabbit-hole');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Registered readers only', $res->body);
    }

    public function test_restricted_story_never_fills_static_cache(): void
    {
        // TestClient drives App::handle directly; the static layer lives in index.php,
        // so the fill is asserted against the Cache itself (StaticCacheTest's pattern)
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $app = $this->app();
        $client = new TestClient($app);
        $res = $client->get('/story/view/the-rabbit-hole'); // 404
        $this->assertSame(404, $res->status);
        $tmpDir = sys_get_temp_dir() . '/kiption-rest-cache-' . bin2hex(random_bytes(4));
        $cache = new \App\StaticCache\Cache($tmpDir);
        $request = new \Kip\Http\Request('GET', '/story/view/the-rabbit-hole', [], [], []);
        $cache->maybeStore($request, $res);
        $this->assertNull($cache->serve($request));
        foreach (glob($tmpDir . '/*') ?: [] as $f) { /* dir cleanup */
            is_dir($f) ? exec('rm -rf ' . escapeshellarg($f)) : @unlink($f);
        }
        @rmdir($tmpDir);
    }

    public function test_restricted_story_hides_from_anonymous_listings_and_feeds(): void
    {
        // Listings and feeds are guest surfaces; a restricted story's title and
        // summary are content, so they must not render there (the story page
        // itself 404s, but the leak would also bake into cached listing files).
        $this->db->query("UPDATE stories SET is_restricted = 1, language = 'en' WHERE slug = 'the-rabbit-hole'");
        $this->db->query("UPDATE stories SET language = 'en' WHERE slug = 'after-hours'");
        $guest = new TestClient($this->app());
        foreach (['/browse/recent', '/browse/category/general', '/feed', '/rss'] as $page) {
            $body = $guest->get($page)->body;
            $this->assertStringNotContainsString('The Rabbit Hole', $body, "{$page} leaked a restricted story title");
            $this->assertStringContainsString('After Hours', $body, "{$page} must still list unrestricted stories");
        }
        $body = $guest->get('/browse', ['language' => 'en'])->body;
        $this->assertStringContainsString('Stories in en', $body); // the filter engaged the listing
        $this->assertStringContainsString('After Hours', $body);
        $this->assertStringNotContainsString('The Rabbit Hole', 'language-filtered browse leaked a restricted story title');
    }

    public function test_guest_writes_to_restricted_story_404(): void
    {
        // The story page 404s for guests; the write paths must agree, or a
        // guest POST is an existence oracle (302 vs 404) and injects guest
        // content into a members-only review section / kudos count.
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $guest = new TestClient($this->app());
        $res = $guest->post('/review/add/the-rabbit-hole', ['body' => 'Sneak.', 'rating' => '', 'guest_name' => 'Gatecrash']);
        $this->assertSame(404, $res->status, $res->body);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM reviews WHERE body = 'Sneak.'")['c']);
        $res = $guest->post('/kudos/add/the-rabbit-hole', []);
        $this->assertSame(404, $res->status, $res->body);
        $this->assertSame(0, (int) $this->db->one(
            "SELECT COUNT(*) c FROM story_kudos k JOIN stories s ON s.id = k.story_id WHERE s.slug = 'the-rabbit-hole'")['c']);
        // Members still can: registered readers may review and kudos what they can read.
        $res = (new TestClient($this->app()))->actingAs(1)
            ->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Member view.', 'rating' => '', 'guest_name' => '']);
        $this->assertSame(302, $res->status, $res->body);
    }

    public function test_toggle_restricts_purges_and_updates(): void
    {
        $client = (new TestClient($this->app()))->actingAs(1);
        $res = $client->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.',
             'rating_id' => (string) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'],
             'categories' => [(string) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id']],
             'restricted' => '1']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one("SELECT is_restricted FROM stories WHERE slug = 'the-rabbit-hole'")['is_restricted']);
    }
}
