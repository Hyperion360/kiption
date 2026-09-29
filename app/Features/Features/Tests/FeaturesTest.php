<?php // app/Features/Features/Tests/FeaturesTest.php
namespace App\Features\Features\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\{Request, Response};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class FeaturesTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminUserId = 0;
    private int $memberUserId = 0;
    private int $moderatorUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-flags-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-flags-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        // Finding 2: Features::init is global state. reset() nulls the memo and
        // drops the DB handle so no later suite in this single phpunit process
        // (alphabetical file order puts this class before the unedited ones)
        // inherits a memo pointing at this unlinking temp DB.
        \App\Features::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    private function db(): Database
    {
        return $this->db;
    }

    public function test_uninit_resolves_from_the_inventory_alone(): void
    {
        // Finding 1 (load-bearing): before any init(), on() answers from the
        // inventory const alone, all true, no DB handle. Every legacy suite
        // builds App without init-ing Features; a skipped init in production
        // degrades to current behavior instead of bricking the archive.
        \App\Features::reset();
        foreach (array_keys(\App\Features::INVENTORY) as $key) {
            $this->assertTrue(\App\Features::on($key), "uninit {$key} must default on");
        }
        $this->assertFalse(\App\Features::on('no_such_flag'), 'unknown keys fail closed even uninit');
    }

    public function test_defaults_overrides_and_unknown_keys(): void
    {
        \App\Features::init($this->db(), []);
        $this->assertTrue(\App\Features::on('news'), 'inventory default: on');
        $this->assertTrue(\App\Features::on('contact'));
        $this->assertFalse(\App\Features::on('no_such_flag'), 'unknown keys are OFF (fail closed)');
        // a DB row overrides the default
        $this->db()->query('INSERT INTO feature_flags (key, enabled) VALUES (?, 0)', ['news']);
        \App\Features::init($this->db(), []); // re-init re-reads
        $this->assertFalse(\App\Features::on('news'), 'DB row disables');
        // config defaults flow through
        \App\Features::init($this->db(), ['contact' => false]);
        $this->assertFalse(\App\Features::on('contact'), 'config default off');
        \App\Features::toggle('news', true); // void; the row now reads on
        $this->assertTrue(\App\Features::on('news'));
    }

    public function test_toggle_persists_invalidates_the_memo_and_validates(): void
    {
        \App\Features::init($this->db(), []);
        $this->assertTrue(\App\Features::on('news')); // builds the memo
        \App\Features::toggle('news', false);         // INSERT OR REPLACE + memo drop
        $this->assertFalse(\App\Features::on('news'), 'memo invalidated, DB re-read');
        $row = $this->db()->one('SELECT enabled FROM feature_flags WHERE key = ?', ['news']);
        $this->assertNotNull($row, 'toggle wrote the row');
        $this->assertSame(0, (int) $row['enabled']);
        \App\Features::toggle('news', true);
        $this->assertTrue(\App\Features::on('news'), 'toggled back on');
        $this->expectException(\InvalidArgumentException::class);
        \App\Features::toggle('no_such_flag', true); // unknown key: never an insert
    }

    public function test_toggle_before_init_throws_logic_exception(): void
    {
        // The Task 1 execution ruling: toggle() before init() cannot silently
        // no-op (a call with no DB handle would vanish without writing a row
        // and without an error); it throws instead. reset() first so this
        // test owns the class state whatever ran before it.
        \App\Features::reset();
        $this->expectException(\LogicException::class);
        \App\Features::toggle('news', false);
    }

    public function test_all_lists_every_inventory_key(): void
    {
        \App\Features::init($this->db(), ['search' => false]);
        $all = \App\Features::all();
        $this->assertSame(array_keys(\App\Features::INVENTORY), array_keys($all));
        foreach ($all as $key => $entry) {
            $this->assertSame($key === 'search' ? false : true, $entry['on']);
            $this->assertSame('features.' . $key . '.desc', $entry['desc'], 'desc carries the lang key');
        }
    }

    public function test_reset_returns_to_the_inventory_state(): void
    {
        \App\Features::init($this->db(), ['search' => false]);
        $this->assertFalse(\App\Features::on('search'), 'config default off before reset');
        \App\Features::reset();
        $this->assertTrue(\App\Features::on('search'), 'inventory default after reset');
        // The DB handle is dropped too: a row landing afterwards cannot be read.
        $this->db()->query('INSERT INTO feature_flags (key, enabled) VALUES (?, 0)', ['news']);
        $this->assertTrue(\App\Features::on('news'), 'no DB handle: inventory alone');
    }

    public function test_guard_is_null_when_on_and_the_byte_identical_404_when_off(): void
    {
        \App\Features::reset();
        $this->assertNull(\App\Features::guard('news'), 'uninit: the flag answers on, no guard response');
        \App\Features::init($this->db(), []);
        $this->assertNull(\App\Features::guard('news'), 'flag on: the guard stays out of the way');
        $this->db()->query('INSERT INTO feature_flags (key, enabled) VALUES (?, 0)', ['news']);
        \App\Features::init($this->db(), []); // re-init re-reads the row
        $r = \App\Features::guard('news');
        $this->assertInstanceOf(Response::class, $r);
        // Finding 10: byte-identical to the router's own 404, no gratuitous oracle
        $canonical = new Response('Page not found', 404);
        $this->assertSame($canonical->body, $r->body);
        $this->assertSame($canonical->status, $r->status);
        $this->assertSame($canonical->headers, $r->headers);
    }

    public function test_features_board_lists_every_key_behind_the_admin_gate(): void
    {
        $this->seed();
        \App\Features::init($this->db(), []);
        $res = $this->client($this->adminUserId)->get('/features');
        $this->assertSame(200, $res->status);
        foreach (array_keys(\App\Features::INVENTORY) as $key) {
            $this->assertStringContainsString($key, $res->body, "the board lists {$key}");
            $this->assertStringContainsString('action="/features/toggle/' . $key . '"', $res->body, "toggle POST for {$key}");
        }
        $this->assertStringContainsString(\App\Lang::t('features.news.desc'), $res->body, 'descriptions render from the lang pack');
        $this->assertStringContainsString(\App\Lang::t('features.on'), $res->body, 'state badge for a default-on flag');
        // The SQL admin gate (the Adminstories idiom): members and moderators
        // 403, guests hit the auth redirect. The toggle POST re-gates the same way.
        $this->assertSame(403, $this->client($this->memberUserId)->get('/features')->status);
        $this->assertSame(403, $this->client($this->moderatorUserId)->get('/features')->status);
        $this->assertSame(302, $this->client()->get('/features')->status);
        $this->assertSame(403, $this->client($this->memberUserId)->postWithToken('/features/toggle/news')->status);
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM feature_flags')['c'], 'no row from a barred POST');
    }

    public function test_toggle_rejects_unknown_keys_with_422(): void
    {
        $this->seed();
        \App\Features::init($this->db(), []);
        $admin = $this->client($this->adminUserId);
        $this->assertSame(422, $admin->postWithToken('/features/toggle/no-such-flag')->status);
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM feature_flags')['c'], 'never an insert outside the inventory');
        // the known path still works after the rejection
        $this->assertSame(302, $admin->postWithToken('/features/toggle/news')->status);
        $this->assertSame(0, (int) $this->db()->one('SELECT enabled FROM feature_flags WHERE key = ?', ['news'])['enabled'], 'flipped off');
    }

    public function test_toggle_purges_both_cache_layers_and_refreshes_the_sitemap(): void
    {
        $this->seed();
        $cacheDir = $this->root . '/static';
        $publicDir = $this->root . '/public';
        mkdir($cacheDir, 0775, true);
        mkdir($publicDir, 0775, true);
        \App\Features::init($this->db(), []);
        $this->assertSame(1, (int) $this->db()->one('SELECT COUNT(*) c FROM news')['c'], 'the seed carries one news item');

        // Layer 1, a stored static file (the StaticCacheTest idiom): a cached
        // /news page that must not outlive the news toggle.
        $static = new \App\StaticCache\Cache($cacheDir);
        $newsReq = new Request('GET', '/news', [], [], []);
        $static->maybeStore($newsReq, new Response('cached news page', 200));
        $this->assertNotNull($static->serve($newsReq));

        // Layer 2, a framework page-cache row: one cookieless guest GET through
        // a throwaway App whose cache_db + app_dir live in the temp root.
        $guest = $this->client();
        $this->assertSame('MISS', $guest->get('/browse')->headers['X-Kip-Cache'] ?? null);
        $cacheDb = new Database('sqlite:' . $this->root . '/app/cache.sqlite');
        $this->assertSame(1, (int) $cacheDb->one('SELECT COUNT(*) c FROM pages')['c'], 'the guest browse landed a framework cache row');

        // The sitemap with news ON: the segment exists and the index lists it.
        \App\Seo\Sitemap::writeAll($this->db(), $publicDir, 'https://archive.example', []);
        $this->assertFileExists($publicDir . '/sitemap-news.xml');
        $this->assertStringContainsString('sitemap-news.xml', (string) file_get_contents($publicDir . '/sitemap.xml'));

        // THE PINNED PURGE (findings 3c + 12): a real admin POST flips news off
        // and every derived layer drops the now-hidden surface immediately.
        $admin = $this->client($this->adminUserId, ['public_dir' => $publicDir]);
        $this->assertSame(302, $admin->postWithToken('/features/toggle/news')->status);
        $this->assertSame(0, (int) $this->db()->one('SELECT enabled FROM feature_flags WHERE key = ?', ['news'])['enabled']);
        $this->assertFalse(\App\Features::on('news'), 'toggle dropped the memo: fresh resolution');
        $this->assertNull($static->serve($newsReq), 'static file unlinked');
        $this->assertFileDoesNotExist($this->root . '/app/cache.sqlite', 'framework cache file unlinked');
        $this->assertFileDoesNotExist($publicDir . '/sitemap-news.xml', 'the news segment file is gone');
        $this->assertStringNotContainsString('sitemap-news.xml', (string) file_get_contents($publicDir . '/sitemap.xml'), 'the index no longer lists it');

        // Toggling back on restores the segment: the refresh runs both ways.
        $this->assertSame(302, $admin->postWithToken('/features/toggle/news')->status);
        $this->assertFileExists($publicDir . '/sitemap-news.xml');
        $this->assertStringContainsString('sitemap-news.xml', (string) file_get_contents($publicDir . '/sitemap.xml'));
    }

    public function test_sitemap_news_segment_follows_the_flag(): void
    {
        $this->seed();
        $publicDir = $this->root . '/public';
        // Uninit = on (finding 1): every existing SitemapTest-style call with
        // no Features state keeps its news segment, zero edits.
        \App\Features::reset();
        \App\Seo\Sitemap::writeAll($this->db(), $publicDir, 'https://archive.example', []);
        $this->assertFileExists($publicDir . '/sitemap-news.xml', 'uninit: the segment builds');
        // Off: the segment drops and the index stops listing it.
        \App\Features::init($this->db(), ['news' => false]);
        \App\Seo\Sitemap::writeAll($this->db(), $publicDir, 'https://archive.example', []);
        $this->assertFileDoesNotExist($publicDir . '/sitemap-news.xml');
        $this->assertStringNotContainsString('sitemap-news.xml', (string) file_get_contents($publicDir . '/sitemap.xml'));
    }

    public function test_builder_gates_the_four_flaggable_blocks_on_its_own_config(): void
    {
        $this->seed();
        // A public reading list holding a visible story, so the lists block has
        // something to build when on.
        $this->db()->query("INSERT INTO reading_lists (owner_id, title, slug, is_public)
            SELECT id, 'My list', 'my-list', 1 FROM users WHERE penname = 'Demo Author'");
        $this->db()->query("INSERT INTO reading_list_items (list_id, story_id)
            SELECT l.id, s.id FROM reading_lists l, stories s WHERE l.slug = 'my-list' AND s.slug = 'the-rabbit-hole'");
        $cacheDir = $this->root . '/build';
        // no public_dir in the config: the build's sitemap pass stays skipped
        $config = $this->config(['features' => ['news' => false, 'toplists' => false, 'lists' => false, 'directory' => false]]);
        \App\StaticCache\Builder::build($config, $cacheDir);
        $cache = new \App\StaticCache\Cache($cacheDir);
        foreach (['/top', '/news', '/news/view/1', '/lists/view/my-list', '/browse/authors', '/browse/authors/d'] as $p) {
            $this->assertNull($cache->serve(new Request('GET', $p, [], [], [])), "{$p} gated off in the enumeration");
        }
        foreach (['/', '/browse', '/story/view/the-rabbit-hole', '/user/view/demo-author'] as $p) {
            $this->assertNotNull($cache->serve(new Request('GET', $p, [], [], [])), "{$p} is core and stays");
        }
        // Self-cleaning (finding 3b): the build inits Features from its own
        // config, then resets in a finally, so the off-config leaves no bleed.
        $this->assertTrue(\App\Features::on('news'), 'build reset the class: uninit inventory answers on');
    }

    /** The seed plus the admin/member/moderator fixtures (the AdminToolsTest idiom). */
    private function seed(): void
    {
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('flagsadmin@e.test', ?, 'flagsadmin', 'admin', 1, ?, ?, 'flagsadmin')",
            [$hash, date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('flagsmember@e.test', ?, 'flagsmember', ?, ?, 'flagsmember')",
            [$hash, date('c'), date('c')]);
        $this->memberUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, email_verified_at, approved_at, profile_slug) VALUES ('flagsmod@e.test', ?, 'flagsmoderator', 'moderator', ?, ?, 'flagsmoderator')",
            [$hash, date('c'), date('c')]);
        $this->moderatorUserId = (int) $this->db->lastInsertId();
    }

    /** The test App config: static_cache + app_dir point INSIDE the temp root,
     *  so the toggle purge never touches the repo's own cache files. */
    private function config(array $extra = []): array
    {
        return $extra + [
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
            'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__, 4) . '/app/Features',
            'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'],
        ];
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        $client = new TestClient(new App($this->config($extra)));
        return $as === null ? $client : $client->actingAs($as);
    }
}
