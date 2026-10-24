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
        $this->assertSame($this->dir . '/browse/recent/index.html', $this->cache->fileFor('/browse/recent'));
        $this->assertSame($this->dir . '/series/index.html', $this->cache->fileFor('/series'));
        $this->assertSame($this->dir . '/story/read/x/2/index.html', $this->cache->fileFor('/story/read/x/2'));
        $this->assertSame($this->dir . '/top/index.html', $this->cache->fileFor('/top'));
    }

    /** The one directory resolution every write path shares: config wins,
     *  the app tree's public/cache is the fallback. */
    public function test_configured_dir_honors_config_and_falls_back(): void
    {
        $app = new \Kip\App(['static_cache' => ['dir' => '/tmp/kiption-configured']]);
        $this->assertSame('/tmp/kiption-configured', Cache::configuredDir($app));
        $bare = new \Kip\App([]);
        $this->assertStringEndsWith('/public/cache', Cache::configuredDir($bare));
        $this->assertSame(
            (new Cache(Cache::configuredDir($app)))->fileFor('/top'),
            Cache::configured($app)->fileFor('/top'),
            'the factory builds a cache against the same configured directory'
        );
    }

    public function test_file_for_rejects_non_whitelisted_paths(): void
    {
        $this->assertNull($this->cache->fileFor('/admin/members'));
        $this->assertNull($this->cache->fileFor('/auth/login'));
        $this->assertNull($this->cache->fileFor('/story/read/..%2Fx'));
        $this->assertNull($this->cache->fileFor('/browse/category/UPPER'));
        $this->assertNull($this->cache->fileFor('/story/read/x/99999999999999999999'));
    }

    /** The reader fragment (comp M3): a query-free URL whose cache status
     *  rests entirely on the path-prefix whitelist, so the fragment prefix
     *  is simply never listed - members' fragments carry a session token in
     *  the kudos form, and a personalized response must never land in a
     *  cache file. Never stored, never served, not even a 200 guest render. */
    public function test_story_fragment_is_never_cached_nor_served(): void
    {
        $req = new Request('GET', '/story/fragment/the-rabbit-hole/2', [], [], []);
        $this->assertNull($this->cache->fileFor($req->path), 'the fragment prefix is not whitelisted');
        $this->assertFalse($this->cache->isCacheable($req));
        $this->cache->maybeStore($req, new Response('<div class="chapter-unit">x</div>', 200));
        $this->assertNull($this->cache->serve($req), 'nothing was stored to serve');
    }

    public function test_is_cacheable_matrix(): void
    {
        $yes = new Request('GET', '/story/view/x', [], [], []);
        $this->assertTrue($this->cache->isCacheable($yes));
        $this->assertFalse($this->cache->isCacheable(new Request('POST', '/story/view/x', [], [], [])), 'POST never');
        $this->assertFalse($this->cache->isCacheable(new Request('HEAD', '/story/view/x', [], [], [])), 'HEAD falls through');
        $this->assertFalse($this->cache->isCacheable(new Request('GET', '/story/view/x', ['page' => '2'], [], [])), 'query never');
        $this->assertFalse($this->cache->isCacheable(new Request('GET', '/browse/recent', ['fragment' => '1'], [], [])), 'fragment never (infinite scroll)');
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
        // A HIT is a stored-eligible page leaving the origin: the shared-cache
        // header rides it, so the edge may hold what the file layer already holds.
        $this->assertSame('public, s-maxage=14400', $hit->headers['Cache-Control']);
    }

    public function test_serve_miss_is_null(): void
    {
        $this->assertNull($this->cache->serve(new Request('GET', '/story/view/x', [], [], [])));
    }

    public function test_maybe_store_refuses_unsafe_responses(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $err = new Response('err', 500);
        $cookied = new Response('ok', 200, ['Set-Cookie' => 'theme=light']);
        $this->cache->maybeStore($req, $err);
        $this->cache->maybeStore($req, $cookied);
        $this->assertNull($this->cache->serve($req));
        // The cache-control seam rides the same refusal: a response this
        // layer refuses never tells a shared cache it may hold it.
        $this->assertSame('private, no-store', $this->cache->cacheControlFor($req, $err), 'non-200 is never shareable');
        $this->assertSame('private, no-store', $this->cache->cacheControlFor($req, $cookied), 'a Set-Cookie response is personalized');
    }

    /** The shared-cache contract in one place: exactly the responses
     *  maybeStore would store may carry the public header; everything
     *  else (query-bearing, cookie-bearing, POST, non-whitelisted,
     *  noindexed) is private and uncacheable. Same predicate, so the
     *  file cache and the Cache-Control emission can never drift. */
    public function test_cache_control_matrix_matches_store_eligibility(): void
    {
        $eligible = new Request('GET', '/story/view/x', [], [], []);
        $this->assertSame('public, s-maxage=14400', $this->cache->cacheControlFor($eligible, new Response('x', 200)));
        $this->assertSame('private, no-store', $this->cache->cacheControlFor(
            new Request('GET', '/story/view/x', ['page' => '2'], [], []), new Response('x', 200)), 'query-bearing never');
        $this->assertSame('private, no-store', $this->cache->cacheControlFor(
            new Request('GET', '/story/view/x', [], [], ['theme' => 'sepia']), new Response('x', 200)), 'cookie-bearing never');
        $this->assertSame('private, no-store', $this->cache->cacheControlFor(
            new Request('POST', '/story/view/x', [], [], []), new Response('x', 200)), 'POST never');
        $this->assertSame('private, no-store', $this->cache->cacheControlFor(
            new Request('GET', '/auth/login', [], [], []), new Response('x', 200)), 'non-whitelisted route never');
        $this->assertSame('private, no-store', $this->cache->cacheControlFor(
            $eligible, new Response('x', 200, ['X-Robots-Tag' => 'noindex'])), 'noindexed never');
        // A page-shape 304 repeats the stored 200's policy (RFC 9111 4.3.4:
        // caches update the stored entry's headers from the 304's), with the
        // same guards: whitelisted cookieless GET only, clean headers only.
        $this->assertSame('public, s-maxage=14400', $this->cache->cacheControlFor(
            $eligible, new Response('', 304, ['ETag' => '"sha256"'])), 'a page 304 repeats the page policy');
        $this->assertSame('private, no-store', $this->cache->cacheControlFor(
            new Request('GET', '/auth/login', [], [], []), new Response('', 304)), 'a 304 outside the whitelist stays private');
        $this->assertSame('private, no-store', $this->cache->cacheControlFor(
            $eligible, new Response('', 304, ['Set-Cookie' => 'sid=1'])), 'a cookied 304 stays private');
        $this->assertSame('private, no-store', $this->cache->cacheControlFor(
            $eligible, new Response('', 304, ['X-Robots-Tag' => 'noindex'])), 'a noindexed 304 stays private');
        // A HEAD poll revalidates like a GET: the entry survives.
        $this->assertSame('public, s-maxage=14400', $this->cache->cacheControlFor(
            new Request('HEAD', '/story/view/x', [], [], []), new Response('', 304, ['ETag' => '"x"'])), 'a HEAD 304 repeats the page policy');
        // Only the noindex directive withholds (the framework's stance);
        // other robots directives do not personalize a response.
        $this->assertSame('public, s-maxage=14400', $this->cache->cacheControlFor(
            $eligible, new Response('x', 200, ['X-Robots-Tag' => 'max-image-preview:large'])), 'a non-noindex robots directive still caches');
    }

    /** The write path is the 304 rule's mirror and its guard: a 304 carries
     *  the public policy so held entries survive revalidation, but its empty
     *  body must never replace a stored page. */
    public function test_a_304_never_fills_the_cache_file(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('<html>real</html>', 200));
        $stored = $this->cache->serve($req);
        $this->assertNotNull($stored);
        $this->cache->maybeStore($req, new Response('', 304, ['ETag' => '"sha256"']));
        $after = $this->cache->serve($req);
        $this->assertNotNull($after, 'the stored page survives');
        $this->assertSame($stored->body, $after->body, 'the 304 wrote nothing over it');
    }

    /** The syndication XML's public lifetime: the one app-served shape a
     *  shared cache may hold. The path whitelist matches the feed routes and
     *  nothing else (the subscribe page is a personalized HTML page, slug
     *  shapes match the router's), a non-200 stays private, and a
     *  Set-Cookie never rides a shared cache. */
    public function test_feed_control_times_only_the_syndication_xml(): void
    {
        $feed = new Response('<?xml?>', 200);
        $cc = 'public, max-age=300, s-maxage=3600';
        foreach (['/feed', '/rss', '/feed/category/fantasy', '/feed/author/wren_field'] as $path) {
            $this->assertSame($cc, Cache::feedControl(new Request('GET', $path, [], [], []), $feed), $path);
        }
        $this->assertNull(Cache::feedControl(new Request('GET', '/feed/subscribe', [], [], []), $feed), 'the subscribe page is personalized HTML');
        $this->assertNull(Cache::feedControl(new Request('GET', '/feed/author/Wrenfield', [], [], []), $feed), 'slug shapes match the router');
        $this->assertNull(Cache::feedControl(new Request('GET', '/feed/author/x/extra', [], [], []), $feed));
        $this->assertNull(Cache::feedControl(new Request('GET', '/feed', [], [], []), new Response('<?xml?>', 404)), 'non-200 is never shareable');
        $this->assertNull(Cache::feedControl(new Request('HEAD', '/feed', [], [], []), $feed), 'a HEAD response never rides the public lifetime (its body was stripped)');
        // A HEAD-sourced 304 repeats the stored policy like a GET's: HEAD
        // polls (feed readers use them) must not evict the CDN entry.
        $this->assertSame($cc, Cache::feedControl(new Request('HEAD', '/feed', [], [], []), new Response('', 304, ['ETag' => '"x"'])), 'a HEAD 304 repeats the feed policy');
        // A conditional GET's 304 must repeat the Cache-Control the 200 would
        // send (RFC 9111): Kip answers If-None-Match on every feed 200 with a
        // 304, and a shared cache replaces the stored entry's policy with the
        // 304's, so the fallback would flip the CDN entry to no-store on the
        // first revalidation.
        $this->assertSame($cc, Cache::feedControl(new Request('GET', '/feed', [], [], []), new Response('', 304, ['ETag' => '"sha256"'])), 'a 304 repeats the 200 policy');
        $this->assertNull(Cache::feedControl(new Request('GET', '/feed/subscribe', [], [], []), new Response('', 304)), 'a 304 outside the feed paths stays private');
        $this->assertNull(Cache::feedControl(new Request('GET', '/feed', [], [], []),
            new Response('<?xml?>', 200, ['Set-Cookie' => 'sid=1'])), 'a Set-Cookie response is personalized');
        // The seam hands the feed its lifetime and keeps the page rule intact;
        // with the static cache disabled outright the front controller calls
        // feedControl directly, so the static method is the whole fallback.
        $this->assertSame($cc, $this->cache->cacheControlFor(new Request('GET', '/feed', [], [], []), $feed));
        $this->assertSame('private, no-store',
            $this->cache->cacheControlFor(new Request('GET', '/feed/subscribe', [], [], []), $feed));
    }

    public function test_maybe_store_refuses_a_list_valued_set_cookie(): void
    {
        // The multi-cookie emission shape since the Kip 0.5 sync (login,
        // logout, prefs save). The refusal scans header NAMES, never values,
        // so a two-leaf Set-Cookie refuses exactly like the scalar one; this
        // pins it because before Kip's withAddedHeader such a Response could
        // not even be constructed.
        $req = new Request('GET', '/story/view/x', [], [], []);
        $twoLeaves = new Response('ok', 200, ['Set-Cookie' => [
            'lang=xx; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax',
            'theme=dark; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax',
        ]]);
        $this->assertSame(['lang=xx; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax',
            'theme=dark; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax'], $twoLeaves->headers['Set-Cookie']);
        $this->cache->maybeStore($req, $twoLeaves);
        $this->assertNull($this->cache->serve($req), 'a list-valued Set-Cookie never lands in the file cache');
    }

    /** The refusal is name-based and casing-proof: a future writer emitting
     *  'SET-COOKIE' (or any other spelling) must not slip a personalized
     *  header past the guard into a cache file. */
    public function test_maybe_store_refuses_set_cookie_in_any_casing(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('ok', 200, ['SET-COOKIE' => 'theme=light']));
        $this->assertNull($this->cache->serve($req), 'an upper-cased Set-Cookie never lands in the file cache');
        $this->cache->maybeStore($req, new Response('ok', 200, ['sEt-CoOkIe' => 'theme=light']));
        $this->assertNull($this->cache->serve($req), 'mixed casing refuses too');
    }

    /** The noindex refusal is name-based and casing-proof, same stance as
     *  the Set-Cookie refusal: a writer emitting 'X-ROBOTS-TAG' must not
     *  slip a noindexed page into the anonymous cache. An EMPTY noindex
     *  header still stores (nothing was asserted). */
    public function test_maybe_store_refuses_x_robots_tag_in_any_casing(): void
    {
        $req = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($req, new Response('ok', 200, ['X-ROBOTS-TAG' => 'noindex']));
        $this->assertNull($this->cache->serve($req), 'upper-cased X-Robots-Tag refuses');
        $this->cache->maybeStore($req, new Response('ok', 200, ['x-robots-tag' => 'noindex']));
        $this->assertNull($this->cache->serve($req), 'lower-cased refuses too');
        $this->cache->maybeStore($req, new Response('ok', 200, ['X-Robots-Tag' => '']));
        $this->assertNotNull($this->cache->serve($req), 'an empty noindex header stores');
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
        (new \Kip\Migrations\Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $app = new \Kip\App([
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'app_dir' => dirname(__DIR__) . '/app',
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

    /** C12: the theme and reader cookies the redesign rides must not change
     *  the anonymous bytes: a cookieless story/view or story/read render
     *  carries no data-theme and none of the six typography attributes, so
     *  every cookieless visitor (and the static cache between them) sees the
     *  same markup and theme/prefs stay purely client-side state. */
    public function test_cookieless_story_renders_carry_no_theme_or_reader_pref_attributes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kiption-wire-') . '.sqlite';
        $dsn = 'sqlite:' . $path;
        $db = new \Kip\Database($dsn);
        (new \Kip\Migrations\Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $app = new \Kip\App([
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'app_dir' => dirname(__DIR__) . '/app',
            'db' => ['dsn' => $dsn],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-static-upl'],
        ]);
        foreach (['/story/view/the-rabbit-hole', '/story/read/the-rabbit-hole/1'] as $uri) {
            $res = $app->handle(new Request('GET', $uri, [], [], []));
            $this->assertSame(200, $res->status, $res->body);
            foreach (['data-theme', 'data-size', 'data-typeface', 'data-spacing', 'data-paragraphs', 'data-width', 'data-mode'] as $attr) {
                $this->assertStringNotContainsString($attr . '=', $res->body,
                    "{$uri}: {$attr} would make the cached anonymous bytes vary by reader");
            }
        }
        @unlink($path); @unlink($path . '-wal'); @unlink($path . '-shm');
    }

    /** C12: the flip side of neutrality. A theme=sepia or reader=<prefs>
     *  cookie makes the request live: it is never served the anonymous
     *  variant, and its own render never lands in the file cache. */
    public function test_theme_and_reader_cookies_refuse_serve_and_store(): void
    {
        $anon = new Request('GET', '/story/view/x', [], [], []);
        $this->cache->maybeStore($anon, new Response('anon', 200));
        $this->assertNotNull($this->cache->serve($anon), 'the anonymous variant fills first');
        $this->assertNull($this->cache->serve(new Request('GET', '/story/view/x', [], [], ['theme' => 'sepia'])),
            'a theme cookie is never served the anonymous page');
        $this->assertNull($this->cache->serve(new Request('GET', '/story/view/x', [], [], ['reader' => '21-sans-airy-spaced-wide-pages'])),
            'a reader cookie is never served the anonymous page');
        // Neither cooks a variant into storage, even with a clean response.
        $this->cache->purgeAll();
        $this->cache->maybeStore(new Request('GET', '/story/view/x', [], [], ['theme' => 'night']), new Response('themed', 200));
        $this->cache->maybeStore(new Request('GET', '/story/view/x', [], [], ['reader' => '21-serif-regular-indented-medium-scroll']), new Response('typed', 200));
        $this->assertNull($this->cache->serve($anon), 'no cookied variant was stored');
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
        foreach (['/story/view/x', '/story/read/x/1', '/story/read/x/2', '/browse', '/', '/top', '/series', '/story/view/y'] as $p) {
            $this->cache->maybeStore(new Request('GET', $p, [], [], []), new Response($p, 200));
        }
        $this->cache->purgeStory('x', ['general']);
        foreach (['/story/view/x', '/story/read/x/1', '/story/read/x/2'] as $p) {
            $this->assertNull($this->cache->serve(new Request('GET', $p, [], [], [])), "{$p} purged");
        }
        $this->assertNull($this->cache->serve(new Request('GET', '/', [], [], [])), 'home purged');
        $this->assertNull($this->cache->serve(new Request('GET', '/browse', [], [], [])), 'browse purged');
        $this->assertNull($this->cache->serve(new Request('GET', '/series', [], [], [])), 'series index purged (its counts ride story visibility)');
        $this->assertNull($this->cache->serve(new Request('GET', '/top', [], [], [])), 'top hub purged (engagement rides purgeStory)');
        $this->assertNotNull($this->cache->serve(new Request('GET', '/story/view/y', [], [], [])), 'unrelated story survives');
    }

    /** A series write (create, rename, items) changes its index row too, so
     *  purgeSeries drops the /series index along with the series page. */
    public function test_purge_series_removes_its_page_and_the_index(): void
    {
        foreach (['/series', '/series/view/a', '/series/view/b'] as $p) {
            $this->cache->maybeStore(new Request('GET', $p, [], [], []), new Response($p, 200));
        }
        $this->cache->purgeSeries('a');
        $this->assertNull($this->cache->serve(new Request('GET', '/series/view/a', [], [], [])), 'its page purged');
        $this->assertNull($this->cache->serve(new Request('GET', '/series', [], [], [])), 'the index purged');
        $this->assertNotNull($this->cache->serve(new Request('GET', '/series/view/b', [], [], [])), 'another series survives');
    }

    public function test_purge_story_hits_its_categories(): void
    {
        $this->cache->maybeStore(new Request('GET', '/browse/category/general', [], [], []), new Response('c', 200));
        $this->cache->purgeStory('x', ['general']);
        $this->assertNull($this->cache->serve(new Request('GET', '/browse/category/general', [], [], [])));
    }
}
