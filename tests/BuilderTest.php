<?php // tests/BuilderTest.php
namespace App\Tests;
use App\StaticCache\{Builder, Cache};
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    private string $path = '';
    private string $dsn = '';
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-build-') . '.sqlite';
        $this->dsn = 'sqlite:' . $this->path;
        $this->cacheDir = sys_get_temp_dir() . '/kiption-buildcache-' . uniqid();
        $db = new Database($this->dsn);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
    }

    private function config(): array
    {
        return [
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'app_dir' => dirname(__DIR__) . '/app',
            'db' => ['dsn' => $this->dsn],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            // the standing convention (mail + uploads on every test App): the
            // Builder renders /user/... pages and UserController needs its Mailer
            'mail' => ['transport' => 'log', 'log_path' => $this->cacheDir . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-build-upl'],
        ];
    }

    public function test_build_writes_every_cacheable_page(): void
    {
        $count = Builder::build($this->config(), $this->cacheDir);
        $cache = new Cache($this->cacheDir);
        foreach (['/', '/browse', '/browse/recent', '/series', '/top', '/browse/category/general',
                  '/story/view/the-rabbit-hole', '/story/read/the-rabbit-hole/1',
                  '/story/read/the-rabbit-hole/2', '/story/read/the-rabbit-hole/3',
                  '/story/view/after-hours', '/story/read/after-hours/1',
                  '/series/view/down-the-rabbit-hole',
                  '/challenges',
                  '/page/view/about',
                  '/dmca',
                  '/user/view/demo-author', '/user/stories/demo-author', '/user/view/betafriend',
                  '/browse/authors', '/browse/authors/b', '/browse/authors/d'] as $p) {
            $this->assertNotNull($cache->serve(new Request('GET', $p, [], [], [])), "{$p} built");
        }
        $this->assertSame(25, $count); // 4 fixed (home, browse, recent, series index) + top hub + 1 category + rabbit(view+3 reads) + after(view+1 read) + 1 series + 3 seeded pages (about + content-policy + dmca; migrations 036/037; dmca builds at its TOP-LEVEL spelling /dmca since Task 5, never /page/view/dmca, which 301s) + demo(view+stories) + beta(view) + directory(index + b for betafriend + d for demo-author) + the adult read builds as its anonymous variant (the gate page, 200) + news index + 1 seeded news item (Task 4: /news + SELECT id FROM news rows) + the challenges index (Task 3; the seeded challenge has no visible items, so its view page never joins the enumeration, finding 10)
    }

    public function test_prune_empties_the_layer(): void
    {
        Builder::build($this->config(), $this->cacheDir);
        Builder::prune($this->cacheDir, 'sqlite:' . $this->path . '.cache');
        $cache = new Cache($this->cacheDir);
        $this->assertNull($cache->serve(new Request('GET', '/', [], [], [])));
    }

    public function test_prune_clears_configured_cache_in_place_for_open_connections(): void
    {
        $cacheDb = $this->path . '.cache';
        $codeDir = $this->path . '.code';
        mkdir($codeDir);
        file_put_contents($codeDir . '/cache.sqlite', 'immutable code sentinel');
        $old = new Database('sqlite:' . $cacheDb);
        $new = new Database('sqlite:' . $cacheDb);
        (new \Kip\Cache\PageCache($old))->put('/stale', '', new \Kip\Http\Response('stale'), ['pages']);
        try {
            Builder::prune($this->cacheDir, 'sqlite:' . $cacheDb);
            $this->assertFileExists($cacheDb);
            $this->assertSame(0, (int) $old->one('SELECT COUNT(*) n FROM pages')['n']);
            $this->assertSame(0, (int) $new->one('SELECT COUNT(*) n FROM page_tags')['n']);
            $old->query("INSERT INTO pages (key, body, headers, etag, created_at) VALUES ('next', 'next', '{}', 'x', 1)");
            $this->assertSame('next', $new->one("SELECT body FROM pages WHERE key = 'next'")['body']);
            $this->assertSame('immutable code sentinel', file_get_contents($codeDir . '/cache.sqlite'));
        } finally {
            unset($old, $new);
            foreach (['', '-wal', '-shm'] as $suffix) @unlink($cacheDb . $suffix);
            @unlink($codeDir . '/cache.sqlite');
            @rmdir($codeDir);
        }
    }

    public function test_cache_database_path_handles_uri_parameters_and_memory(): void
    {
        self::assertSame('/tmp/cache.sqlite', Builder::cacheDbPath('sqlite:/tmp/cache.sqlite?mode=ro'));
        self::assertNull(Builder::cacheDbPath('sqlite::memory:'));
        self::assertNull(Builder::cacheDbPath('sqlite:relative.sqlite'));
        self::assertNull(Builder::cacheDbPath('mysql:cache'));
    }
}
