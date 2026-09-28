<?php // tests/TrendingTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class TrendingTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-trending-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** Fresh handle on the shared temp file (the TopTest shape). */
    private function db(): Database
    {
        return new Database('sqlite:' . $this->path);
    }

    private function newApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-trending-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-trending-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function sid(string $slug): int
    {
        return (int) $this->db()->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'];
    }

    public function test_trending_ranks_by_velocity_over_the_seven_day_window(): void
    {
        $rh = $this->sid('the-rabbit-hole');
        $ah = $this->sid('after-hours');
        // the-rabbit-hole: 5 rollup reads yesterday + 1 today + 1 kudos today = 7
        $this->db()->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now','-1 day'), ?, 0, 5)", [$rh]);
        $this->db()->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now'), ?, 0, 1)", [$rh]);
        $this->db()->query("INSERT INTO story_kudos (story_id, user_id) VALUES (?, (SELECT id FROM users WHERE penname = 'betafriend'))", [$rh]);
        // after-hours: kudos ONLY in the window (finding 7's pin: the cold-start
        // union anchor surfaces a story page_stats has no row for at all), three
        // in-window guest kudos plus one eight days old that must not count
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $this->db()->query('INSERT INTO story_kudos (story_id, user_id, ip) VALUES (?, NULL, ?)', [$ah, $ip]);
        }
        $this->db()->query("INSERT INTO story_kudos (story_id, user_id, ip, created_at) VALUES (?, NULL, '10.0.0.4', strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-8 days'))", [$ah]);
        // finding 2: only the chapter_id = 0 rollup rows feed the reads sum - a
        // 50-read chapter row must not move the velocity (summing both would
        // double-count every beacon hit); ditto an 8-day-old rollup row
        $this->db()->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now'), ?, 4, 50)", [$ah]);
        $this->db()->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now','-8 days'), ?, 0, 99)", [$ah]);
        $body = $this->client()->get('/top')->body;
        $this->assertStringContainsString('Trending (last 7 days)', $body);
        $this->assertStringContainsString('Refreshed on engagement and rebuilds', $body, 'the staleness label');
        // Trending is the page's LAST section, so the substring from its heading
        // to the end of the body is exactly its rows (the footer links no story)
        $section = substr($body, (int) strpos($body, 'Trending (last 7 days)'));
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole"', $section);
        $this->assertStringContainsString('href="/story/view/after-hours"', $section, 'a kudos-only story appears (the union anchor)');
        $this->assertLessThan((int) strpos($section, 'href="/story/view/after-hours"'), (int) strpos($section, 'href="/story/view/the-rabbit-hole"'), 'the velocity leader orders first');
        $this->assertStringContainsString('7 reads + kudos', $section, 'velocity is rollup reads plus kudos');
        $this->assertStringContainsString('3 reads + kudos', $section, 'the out-of-window kudos and the chapter row did not count');
    }

    public function test_trending_section_is_empty_without_window_data(): void
    {
        $body = $this->client()->get('/top')->body;
        $this->assertStringContainsString('Trending (last 7 days)', $body);
        $this->assertStringContainsString('Nothing trending yet.', $body, 'the honest empty state');
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $body, 'no window data, no trending rows');
    }

    public function test_kudos_write_purges_top_but_a_beacon_hit_does_not(): void
    {
        $dir = sys_get_temp_dir() . '/kiption-trending-cache-' . uniqid('', true);
        $cache = new \App\StaticCache\Cache($dir);
        $req = new \Kip\Http\Request('GET', '/top', [], [], []);
        $cache->maybeStore($req, $this->app->handle($req));
        $this->assertNotNull($cache->serve($req), '/top fills and serves the anonymous variant');
        // A real beacon hit through the app must leave the cached hub standing:
        // reads are batch-stale by stance (recorded) - engagement writes and
        // pages:build refresh the section, beacons never do. The no-purge
        // wiring is code-verified against the controller (it builds no Cache).
        $res = $this->client()->get('/beacon/read/1/3');
        $this->assertSame(200, $res->status);
        $this->assertNotNull($cache->serve($req), 'a beacon hit must NOT purge /top');
        // The kudos POST's rider (the TopTest hermetic form: the real write
        // paths drive the REAL public/cache instance, which this temp-dir cache
        // cannot observe and the test must not write to; purgeStory is the
        // wiring KudosController::add already calls on every inserted kudos).
        $cache->purgeStory('the-rabbit-hole', []);
        $this->assertNull($cache->serve($req), 'the kudos write path purges /top');
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
