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
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
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
        foreach (['/', '/browse', '/browse/recent', '/top', '/browse/category/general',
                  '/story/view/the-rabbit-hole', '/story/read/the-rabbit-hole/1',
                  '/story/read/the-rabbit-hole/2', '/story/read/the-rabbit-hole/3',
                  '/story/view/after-hours', '/story/read/after-hours/1',
                  '/series/view/down-the-rabbit-hole',
                  '/challenges',
                  '/page/view/about',
                  '/user/view/demo-author', '/user/stories/demo-author', '/user/view/betafriend',
                  '/browse/authors', '/browse/authors/b', '/browse/authors/d'] as $p) {
            $this->assertNotNull($cache->serve(new Request('GET', $p, [], [], [])), "{$p} built");
        }
        $this->assertSame(22, $count); // 4 fixed (home, browse, recent, top hub) + 1 category + rabbit(view+3 reads) + after(view+1 read) + 1 series + 1 seeded about page + demo(view+stories) + beta(view) + directory(index + b for betafriend + d for demo-author) + the adult read builds as its anonymous variant (the gate page, 200) + news index + 1 seeded news item (Task 4: /news + SELECT id FROM news rows) + the challenges index (Task 3; the seeded challenge has no visible items, so its view page never joins the enumeration, finding 10)
    }

    public function test_prune_empties_the_layer(): void
    {
        Builder::build($this->config(), $this->cacheDir);
        Builder::prune($this->cacheDir, dirname(__DIR__) . '/app');
        $cache = new Cache($this->cacheDir);
        $this->assertNull($cache->serve(new Request('GET', '/', [], [], [])));
    }
}
