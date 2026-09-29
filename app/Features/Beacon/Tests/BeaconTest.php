<?php // app/Features/Beacon/Tests/BeaconTest.php
namespace App\Features\Beacon\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class BeaconTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-beacon-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function newApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-beacon-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-beacon-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function db(): Database
    {
        return $this->db;
    }

    /** The CliTest idiom: bin/kip against this test's throwaway DB (PHP_BINARY,
     *  never the shebang: the PATH default php is 5.6). */
    private function kip(string $args): array
    {
        $cmd = sprintf('KIP_DB_DSN=%s %s %s %s 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 4) . '/bin/kip'),
            $args);
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_beacon_counts_reads_and_returns_the_pixel(): void
    {
        $res = $this->client()->get('/beacon/read/1/3'); // story 1, chapter id 3 (finding 1: id 10 does not exist on the seed)
        $this->assertSame(200, $res->status);
        $this->assertSame('image/gif', $res->headers['Content-Type'] ?? '');
        $this->assertSame('no-store', $res->headers['Cache-Control'] ?? '');
        $this->assertSame(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), $res->body, 'the 1x1 transparent GIF');
        $db = $this->db();
        $row = $db->one('SELECT reads FROM page_stats WHERE story_id = 1 AND chapter_id = 3 AND day = strftime(\'%Y-%m-%d\', \'now\')');
        $this->assertSame(1, (int) $row['reads']);
        // finding 10: the chapter_id=0 rollup row + the byte-identical no-oracle
        // pixel. Plan deviation (reported): the plan snippet pinned the rollup at
        // 1 AFTER a second hit, but its own spec (the rollup upserts the same way
        // in the same method) and finding 2 (chapter row + rollup row per read is
        // what double-counting means) put it at 2 there; the 1 is asserted at its
        // true point (one hit so far) and the increment pinned after the second.
        $this->assertSame(1, (int) $db->one("SELECT reads FROM page_stats WHERE story_id = 1 AND chapter_id = 0 AND day = strftime('%Y-%m-%d', 'now')")['reads'], 'the chapter_id=0 rollup row');
        $this->client()->get('/beacon/read/1/3');
        $this->assertSame(2, (int) $db->one('SELECT reads FROM page_stats WHERE story_id = 1 AND chapter_id = 3 AND day = strftime(\'%Y-%m-%d\', \'now\')')['reads']);
        $this->assertSame(2, (int) $db->one("SELECT reads FROM page_stats WHERE story_id = 1 AND chapter_id = 0 AND day = strftime('%Y-%m-%d', 'now')")['reads'], 'the rollup counts every read');
        $this->assertSame($res->body, $this->client()->get('/beacon/read/999/999')->body, 'identical pixel for forged pairs');
        // forged pairs write nothing and still return the identical pixel
        $this->assertSame(200, $this->client()->get('/beacon/read/999/999')->status);
        $this->assertNull($db->one('SELECT * FROM page_stats WHERE story_id = 999'));
        // invalid ids 404 (the router's whitelist admits non-digits and negatives;
        // the controller-side guard is load-bearing, finding 18)
        $this->assertSame(404, $this->client()->get('/beacon/read/x/y')->status);
        $this->assertSame(404, $this->client()->get('/beacon/read/1/-3')->status);
    }

    public function test_chapter_page_embeds_the_beacon_pixel(): void
    {
        // A fourth chapter for story 1 whose id (5) differs from its position (4):
        // the src must carry chapters.id, never the position (finding 5).
        $this->db()->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, 4, 'Out', 'Beyond the frame.', 1, 3)");
        $res = $this->client()->get('/story/read/the-rabbit-hole/4');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('src="/beacon/read/1/5"', $res->body);
    }

    public function test_logs_prune_prunes_page_stats_days_on_the_content_db(): void
    {
        $this->db()->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES ('2020-01-01', 1, 0, 7)");
        $this->db()->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now'), 1, 0, 3)");
        [$code, $out] = $this->kip('logs:prune --days=30');
        $this->assertSame(0, $code, $out);
        $this->assertNull($this->db()->one("SELECT * FROM page_stats WHERE day = '2020-01-01'"), 'the old day is pruned');
        $this->assertNotNull($this->db()->one("SELECT * FROM page_stats WHERE day = date('now')"), 'recent days stay');
    }
}
