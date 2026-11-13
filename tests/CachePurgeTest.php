<?php // tests/CachePurgeTest.php
namespace App\Tests;
use App\CachePurge;
use Kip\App;
use Kip\Database;
use PHPUnit\Framework\TestCase;

final class CachePurgeTest extends TestCase
{
    private string $root = '';
    private Database $db;
    private int $calls = 0;
    private string $dbFile = '';

    protected function setUp(): void
    {
        CachePurge::reset();
        $this->root = sys_get_temp_dir() . '/kiption-cp-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        mkdir($this->root . '/public', 0775, true);
        $this->dbFile = tempnam(sys_get_temp_dir(), 'kiption-cp-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->dbFile);
        (new \Kip\Migrations\Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        CachePurge::$cdnTransport = function (): int { $this->calls++; return 200; };
    }

    protected function tearDown(): void
    {
        CachePurge::$cdnTransport = null;
        CachePurge::reset();
        unset($this->db);
        @unlink($this->dbFile); @unlink($this->dbFile . '-wal'); @unlink($this->dbFile . '-shm'); @unlink(substr($this->dbFile, 0, -7));
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function app(array $extra = []): App
    {
        return new App($extra + [
            'app_dir' => $this->root . '/app', 'static_cache' => ['dir' => $this->root . '/static'],
            'public_dir' => $this->root . '/public', 'base_url' => 'https://archive.example',
        ]);
    }

    private function cdn(): array
    {
        return ['cdn' => ['enabled' => true, 'zone_id' => 'z', 'api_token' => 't', 'purge_host' => 'x.example/']];
    }

    public function test_save_reports_edge_purge_requested(): void
    {
        $this->assertFalse(CachePurge::run($this->app(['cdn' => ['enabled' => false]]), $this->db));
        CachePurge::reset();
        $app = $this->app($this->cdn());
        $this->assertTrue(CachePurge::run($app, $this->db)); // requested, not confirmed
        $app->runDeferred();
        $this->assertSame(1, $this->calls);
    }

    public function test_enabled_but_incomplete_cdn_config_queues_nothing(): void
    {
        $app = $this->app(['cdn' => ['enabled' => true]]);
        $this->assertFalse(CachePurge::run($app, $this->db));
        $app->runDeferred();
        $this->assertSame(0, $this->calls);
    }

    public function test_two_runs_in_one_request_queue_one_edge_purge(): void
    {
        $app = $this->app($this->cdn());
        $this->assertTrue(CachePurge::run($app, $this->db));
        $this->assertTrue(CachePurge::run($app, $this->db)); // same result as the first
        $app->runDeferred();
        $this->assertSame(1, $this->calls);
    }

    public function test_second_run_in_same_request_does_nothing(): void
    {
        $app = $this->app(['cdn' => ['enabled' => false]]);
        CachePurge::run($app, $this->db);
        $marker = $this->root . '/static/marker.html';
        @mkdir($this->root . '/static', 0775, true);
        file_put_contents($marker, 'cached');
        CachePurge::run($app, $this->db);
        $this->assertFileExists($marker, 'no second local prune');
        CachePurge::reset();
        CachePurge::run($app, $this->db);
        $this->assertFileDoesNotExist($marker, 'after reset() the next save prunes again');
    }

    public function test_no_cdn_save_says_saved(): void
    {
        $app = $this->app(['cdn' => ['enabled' => false]]); // trusted_proxy unset: nothing in front
        $this->assertFalse(CachePurge::liveSoon($app));
        $this->assertSame('added', CachePurge::savedCode($app, 'added'));
        $this->assertSame('1', CachePurge::savedCode($app));
    }

    public function test_proxy_without_purge_says_live_soon(): void
    {
        $app = $this->app(['trusted_proxy' => true, 'cdn' => ['enabled' => false]]);
        $this->assertTrue(CachePurge::liveSoon($app));
        $this->assertSame('live_soon', CachePurge::savedCode($app, 'removed'));
        $this->assertTrue(CachePurge::liveSoon($this->app(['trusted_proxy' => true])));
        $this->assertFalse(CachePurge::liveSoon($this->app($this->cdn() + ['trusted_proxy' => true])), 'purging is configured');
    }
}
