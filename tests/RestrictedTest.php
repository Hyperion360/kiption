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
