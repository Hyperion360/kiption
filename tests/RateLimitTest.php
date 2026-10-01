<?php // tests/RateLimitTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The Kip fixed-window limiter adopted in Phase B (config rate_limit, table
 *  from migration 025): one bucket per first URL segment per IP, non-GET/HEAD
 *  only, enforced BEFORE routing. The harness opts IN here with a tight local
 *  config against a prefix no route claims; every other test's app config
 *  carries no rate_limit key, so the limiter is inert there and page renders
 *  keep the one-query budget (QueryBudgetTest's pin). */
final class RateLimitTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rl-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'rate_limit' => ['t' => ['max' => 2, 'window' => 60]],
        ];
    }

    public function test_third_post_in_the_window_is_429_with_retry_after(): void
    {
        $client = new TestClient(new App($this->config()));
        // The path needs no route: the limiter enforces BEFORE routing, so an
        // unmatched path passes through as 404 while allowed, and the verdict
        // is readable off the status line alone.
        $this->assertSame(404, $client->post('/t/x')->status, 'the first hit passes (then 404s: no route)');
        $this->assertSame(404, $client->post('/t/x')->status, 'the second hit passes at max=2');
        $over = $client->post('/t/x');
        $this->assertSame(429, $over->status, 'the third hit inside one 60s window is over max=2');
        // Retry-After = window seconds left = 60 - elapsed-in-window. Under
        // the kernel the limiter reads the real clock, so the deterministic
        // pin is the remaining-window range 1..60, not one literal second.
        $retry = (int) ($over->headers['Retry-After'] ?? '0');
        $this->assertGreaterThanOrEqual(1, $retry, 'Retry-After is at least one second out');
        $this->assertLessThanOrEqual(60, $retry, 'Retry-After never exceeds the window');
        // The stored prefix is the router's canonical spelling (studly), so
        // 't' lands as 'T' in the table.
        $this->assertSame(3, (int) $this->db->one("SELECT hits FROM rate_limits WHERE prefix = 'T'")['hits'],
            'the blocked hit still counts (the upsert records before the verdict)');
    }

    public function test_gets_are_never_counted(): void
    {
        $client = new TestClient(new App($this->config()));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(404, $client->get('/t/x')->status, 'GETs are never limited');
        }
        $this->assertNull($this->db->one("SELECT * FROM rate_limits WHERE prefix = 't'"),
            'five GETs recorded no hit');
        // The GETs spent none of the POST budget: two POSTs still pass and
        // only the third trips.
        $this->assertSame(404, $client->post('/t/x')->status);
        $this->assertSame(404, $client->post('/t/x')->status);
        $this->assertSame(429, $client->post('/t/x')->status);
    }

    public function test_unconfigured_prefixes_stay_inert(): void
    {
        $client = new TestClient(new App($this->config()));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(404, $client->post('/elsewhere')->status, 'an unconfigured prefix never counts');
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM rate_limits')['n'],
            'nothing was recorded: page-serving routes stay untouched by the limiter');
    }
}
