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
        (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
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
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-build-upl'],
        ];
    }

    public function test_build_writes_every_cacheable_page(): void
    {
        $count = Builder::build($this->config(), $this->cacheDir);
        $cache = new Cache($this->cacheDir);
        foreach (['/', '/browse', '/browse/recent', '/browse/category/general',
                  '/story/view/the-rabbit-hole', '/story/read/the-rabbit-hole/1',
                  '/story/read/the-rabbit-hole/2', '/story/read/the-rabbit-hole/3',
                  '/story/view/after-hours', '/story/read/after-hours/1',
                  '/series/view/down-the-rabbit-hole'] as $p) {
            $this->assertNotNull($cache->serve(new Request('GET', $p, [], [], [])), "{$p} built");
        }
        $this->assertSame(11, $count); // 3 fixed + 1 category + rabbit(view+3 reads) + after(view+1 read) + 1 series; the adult read builds as its anonymous variant (the gate page, 200)
    }

    public function test_prune_empties_the_layer(): void
    {
        Builder::build($this->config(), $this->cacheDir);
        Builder::prune($this->cacheDir, dirname(__DIR__) . '/app');
        $cache = new Cache($this->cacheDir);
        $this->assertNull($cache->serve(new Request('GET', '/', [], [], [])));
    }
}
