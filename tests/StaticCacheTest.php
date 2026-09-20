<?php // tests/StaticCacheTest.php
namespace App\Tests;
use App\StaticCache\Cache;
use Kip\Http\{Request, Response};
use PHPUnit\Framework\TestCase;

final class StaticCacheTest extends TestCase
{
    private string $dir = '';
    private Cache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kiption-static-' . uniqid();
        mkdir($this->dir . '/story/view', 0777, true);
        $this->cache = new Cache($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_file_for_maps_paths_to_index_html(): void
    {
        $this->assertSame($this->dir . '/index.html', $this->cache->fileFor('/'));
        $this->assertSame($this->dir . '/browse/index.html', $this->cache->fileFor('/browse'));
        $this->assertSame($this->dir . '/story/read/x/2/index.html', $this->cache->fileFor('/story/read/x/2'));
        $this->assertSame($this->dir . '/top/index.html', $this->cache->fileFor('/top'));
    }

    public function test_file_for_rejects_non_whitelisted_paths(): void
    {
        $this->assertNull($this->cache->fileFor('/admin/members'));
        $this->assertNull($this->cache->fileFor('/auth/login'));
        $this->assertNull($this->cache->fileFor('/story/read/..%2Fx'));
        $this->assertNull($this->cache->fileFor('/browse/category/UPPER'));
        $this->assertNull($this->cache->fileFor('/story/read/x/99999999999999999999'));
    }

    public function test_is_cacheable_matrix(): void
    {
        $yes = new Request('GET', '/story/view/x', [], [], []);
        $this->assertTrue($this->cache->isCacheable($yes));
        $this->assertFalse($this->cache->isCacheable(new Request('POST', '/story/view/x', [], [], [])), 'POST never');
        $this->assertFalse($this->cache->isCacheable(new Request('HEAD', '/story/view/x', [], [], [])), 'HEAD falls through');
        $this->assertFalse($this->cache->isCacheable(new Request('GET', '/story/view/x', ['page' => '2'], [], [])), 'query never');
        $this->assertFalse($this->cache->isCacheable(new Request('GET', '/story/view/x', [], [], ['theme' => 'light'])), 'cookies never');
        $this->assertFalse($this->cache->isCacheable(new Request('GET', '/auth/login', [], [], [])), 'non-whitelisted route');
    }

    public function test_put_then_serve_roundtrip_with_hit_header(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('<html>x</html>', 200));
        $hit = $this->cache->serve($req);
        $this->assertNotNull($hit);
        $this->assertSame(200, $hit->status);
        $this->assertSame('<html>x</html>', $hit->body);
        $this->assertSame('HIT', $hit->headers['X-Static-Cache']);
    }

    public function test_serve_miss_is_null(): void
    {
        $this->assertNull($this->cache->serve(new Request('GET', '/story/view/x', [], [], [])));
    }

    public function test_maybe_store_refuses_unsafe_responses(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('err', 500));
        $this->cache->maybeStore($req, new Response('ok', 200, ['Set-Cookie' => 'theme=light']));
        $this->assertNull($this->cache->serve($req));
    }

    public function test_maybe_store_refuses_noindexed_responses(): void
    {
        $req = new Request('GET', '/browse/category/junk', [], [], []);
        $this->cache->maybeStore($req, new Response('empty', 200, ['X-Robots-Tag' => 'noindex']));
        $this->assertNull($this->cache->serve($req));
    }

    public function test_purge_all_removes_files_but_keeps_dir(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('x', 200));
        $this->cache->purgeAll();
        $this->assertNull($this->cache->serve($req));
        $this->assertDirectoryExists($this->dir);
    }

    public function test_fill_via_app_render_then_hit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kiption-wire-') . '.sqlite';
        $dsn = 'sqlite:' . $path;
        $db = new \Kip\Database($dsn);
        (new \Kip\Migrations\Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($db);
        $app = new \Kip\App([
            'env' => 'prod',
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => $dsn],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-static-upl'],
        ]);
        $req = new Request('GET', '/story/view/the-rabbit-hole', [], [], []);
        $res = $app->handle($req);
        $this->cache->maybeStore($req, $res);
        $hit = $this->cache->serve($req);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('The Rabbit Hole', $hit->body);
        @unlink($path); @unlink($path . '-wal'); @unlink($path . '-shm');
    }

    public function test_cookie_bearing_request_never_served_from_cache(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('anon', 200));
        $cookied = new Request('GET', '/story/view/x', [], [], ['age_ok' => '1']);
        $this->assertNull($this->cache->serve($cookied));
    }

    public function test_maintenance_marker_purges_once(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('x', 200));
        $this->cache->maintenancePurge(true);
        $this->assertNull($this->cache->serve($req), 'purged when maintenance turns on');
        $this->cache->maintenancePurge(true);
        $this->assertFileExists($this->dir . '/.maintenance-purged');
        $this->cache->maintenancePurge(false);
        $this->assertFileDoesNotExist($this->dir . '/.maintenance-purged');
    }

    public function test_purge_story_removes_story_and_collection_pages(): void
    {
        foreach (['/story/view/x', '/story/read/x/1', '/story/read/x/2', '/browse', '/', '/top', '/story/view/y'] as $p) {
            $this->cache->maybeStore(new Request('GET', $p, [], [], []), new Response($p, 200));
        }
        $this->cache->purgeStory('x', ['general']);
        foreach (['/story/view/x', '/story/read/x/1', '/story/read/x/2'] as $p) {
            $this->assertNull($this->cache->serve(new Request('GET', $p, [], [], [])), "{$p} purged");
        }
        $this->assertNull($this->cache->serve(new Request('GET', '/', [], [], [])), 'home purged');
        $this->assertNull($this->cache->serve(new Request('GET', '/browse', [], [], [])), 'browse purged');
        $this->assertNull($this->cache->serve(new Request('GET', '/top', [], [], [])), 'top hub purged (engagement rides purgeStory)');
        $this->assertNotNull($this->cache->serve(new Request('GET', '/story/view/y', [], [], [])), 'unrelated story survives');
    }

    public function test_purge_story_hits_its_categories(): void
    {
        $this->cache->maybeStore(new Request('GET', '/browse/category/general', [], [], []), new Response('c', 200));
        $this->cache->purgeStory('x', ['general']);
        $this->assertNull($this->cache->serve(new Request('GET', '/browse/category/general', [], [], [])));
    }
}
