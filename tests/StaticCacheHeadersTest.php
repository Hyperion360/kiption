<?php // tests/StaticCacheHeadersTest.php
namespace App\Tests;
use App\StaticCache\Cache;
use Kip\Http\{Request, Response};
use PHPUnit\Framework\TestCase;

/** Browsers revalidate every cached page; the edge keeps its own four-hour copy
 *  under the headers CDNs read first (and Cloudflare strips). */
final class StaticCacheHeadersTest extends TestCase
{
    private string $dir = '';
    private Cache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kiption-cc-' . uniqid();
        mkdir($this->dir . '/story/view', 0777, true);
        $this->cache = new Cache($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function store(): Request
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('<p>hi</p>', 200));
        return $req;
    }

    public function test_cached_hit_tells_browsers_to_revalidate_and_the_edge_to_keep(): void
    {
        $hit = $this->cache->serve($this->store());
        $this->assertSame('public, no-cache', $hit->headers['Cache-Control']);
        $this->assertSame('public, max-age=14400', $hit->headers['Cloudflare-CDN-Cache-Control']);
        $this->assertSame('public, max-age=14400', $hit->headers['CDN-Cache-Control']);
    }

    public function test_a_hit_carries_an_etag_and_answers_a_matching_one_with_304(): void
    {
        $req = $this->store();
        $etag = $this->cache->serve($req)->headers['ETag'];
        $this->assertSame('"' . hash('sha256', '<p>hi</p>') . '"', $etag);
        foreach ([$etag, 'W/' . $etag, '"nope", ' . $etag] as $sent) {
            $again = $this->cache->serve(new Request('GET', '/story/view/x', [], [], [], '127.0.0.1', ['if-none-match' => $sent]));
            $this->assertSame(304, $again->status, $sent);
            $this->assertSame('', $again->body);
            $this->assertSame('public, no-cache', $again->headers['Cache-Control']);
            $this->assertSame('public, max-age=14400', $again->headers['Cloudflare-CDN-Cache-Control']);
        }
        $stale = $this->cache->serve(new Request('GET', '/story/view/x', [], [], [], '127.0.0.1', ['if-none-match' => '"old"']));
        $this->assertSame(200, $stale->status);
    }

    public function test_only_the_public_policy_gets_edge_headers(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $page = Cache::stamp($this->cache, $req, new Response('x', 200));
        $this->assertSame('public, no-cache', $page->headers['Cache-Control']);
        $this->assertSame('public, max-age=14400', $page->headers['Cloudflare-CDN-Cache-Control']);

        $err = Cache::stamp($this->cache, $req, new Response('x', 500));
        $this->assertSame('private, no-store', $err->headers['Cache-Control']);
        $this->assertArrayNotHasKey('Cloudflare-CDN-Cache-Control', $err->headers);
        $this->assertArrayNotHasKey('CDN-Cache-Control', $err->headers);

        $feed = Cache::stamp($this->cache, new Request('GET', '/feed', [], [], []), new Response('<rss/>', 200));
        $this->assertSame('public, max-age=300, s-maxage=3600', $feed->headers['Cache-Control']);
        $this->assertArrayNotHasKey('Cloudflare-CDN-Cache-Control', $feed->headers);

        $off = Cache::stamp(null, $req, new Response('x', 200)); // static cache disabled
        $this->assertSame('private, no-store', $off->headers['Cache-Control']);
        $this->assertArrayNotHasKey('Cloudflare-CDN-Cache-Control', $off->headers);
    }

    public function test_a_page_304_from_the_app_carries_the_edge_header(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $r = Cache::stamp($this->cache, $req, new Response('', 304, ['ETag' => '"abc"']));
        $this->assertSame('public, no-cache', $r->headers['Cache-Control']);
        $this->assertSame('public, max-age=14400', $r->headers['Cloudflare-CDN-Cache-Control']);
        $cookied = Cache::stamp($this->cache, $req, new Response('', 304, ['Set-Cookie' => 'sid=1']));
        $this->assertSame('private, no-store', $cookied->headers['Cache-Control']);
        $this->assertArrayNotHasKey('Cloudflare-CDN-Cache-Control', $cookied->headers);
    }
}
