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

    public function test_purge_all_removes_files_but_keeps_dir(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('x', 200));
        $this->cache->purgeAll();
        $this->assertNull($this->cache->serve($req));
        $this->assertDirectoryExists($this->dir);
    }
}
