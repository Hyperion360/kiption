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

    public function test_expired_window_stops_counting_and_prunes(): void
    {
        // A row from a dead window carries no hits forward; the next hit
        // starts a fresh window row, and that upsert retires the stale one
        // (the prune runs exactly then; idx_rate_limits_window serves it).
        $this->db->query('INSERT INTO rate_limits (prefix, ip, window_start, hits) VALUES (?,?,?,?)',
            ['T', '127.0.0.1', time() - 61, 2]);
        $client = new TestClient(new App($this->config()));
        $this->assertNotSame(429, $client->post('/t/x')->status,
            'an expired window does not carry its hits forward');
        $this->assertSame(0, (int) $this->db->one(
            'SELECT COUNT(*) AS n FROM rate_limits WHERE window_start < ?', [time() - 60])['n'],
            'the new-window upsert retired the stale row');
    }

    public function test_shipped_config_limits_a_real_route(): void
    {
        // Testing specialist finding: the mechanism is pinned with a synthetic
        // prefix, but nothing tied the SHIPPED config.php map to a real route;
        // deleting or misspelling a production segment would ship green. This
        // loads the real config (paths overridden) and drives a real POST
        // route past its shipped bucket.
        //
        // The route is /warning/accept, not /auth/attempt: the login route
        // carries a SECOND throttle (Auth::throttled, 5 failed attempts per
        // email OR ip per 15 minutes), so when the limiter's fixed window
        // rolled mid-sequence the 11th request reached the controller and
        // returned the CONTROLLER's header-less 429 - the pin passed on the
        // wrong 429 and failed on the missing Retry-After (observed in a
        // full-suite run, 2026-10-03). The warning accept has no second
        // throttle, so a 429 here can only be the limiter's.
        //
        // The sequence starts two seconds after a window boundary (fixed
        // windows align on unix-time minutes), so all eleven requests share
        // one window deterministically. It costs up to a minute of waiting
        // once per suite run and removes the last timing flake.
        sleep(62 - (time() % 60));
        $config = require dirname(__DIR__) . '/config.php';
        $config['db'] = ['dsn' => 'sqlite:' . $this->path];
        $config['log_db'] = ['dsn' => 'sqlite::memory:'];
        $config['cache_db'] = ['dsn' => 'sqlite::memory:'];
        $client = new TestClient(new App($config));
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(302, $client->post('/warning/accept', ['return_to' => '/'])->status,
                'the first 10 pass the shipped warning bucket (max 10)');
        }
        $over = $client->post('/warning/accept', ['return_to' => '/']);
        $this->assertSame(429, $over->status, 'the 11th POST trips the shipped warning bucket (max 10)');
        $this->assertGreaterThanOrEqual(1, (int) ($over->headers['Retry-After'] ?? '0'));
    }
}
