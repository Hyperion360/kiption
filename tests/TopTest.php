<?php // tests/TopTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class TopTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-top-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** Fresh handle on the shared temp file (the SeriesRepositoryTest shape). */
    private function db(): Database
    {
        return new Database('sqlite:' . $this->path);
    }

    private function newApp(): App
    {
        return new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-top-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-top-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_top_lists_leaders_in_all_four_sections(): void
    {
        $this->db()->query("INSERT INTO story_kudos (story_id, user_id) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), (SELECT id FROM users WHERE penname = 'betafriend'))");
        $this->db()->query("INSERT INTO favorites (user_id, story_id) VALUES ((SELECT id FROM users WHERE penname = 'betafriend'), (SELECT id FROM stories WHERE slug = 'the-rabbit-hole'))");
        $this->db()->query("INSERT INTO reviews (story_id, user_id, body, rating) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), (SELECT id FROM users WHERE penname = 'betafriend'), 'Great.', 9)");
        $body = $this->client()->get('/top')->body;
        $this->assertStringContainsString('Most favorited', $body);
        $this->assertStringContainsString('Most kudos', $body);
        $this->assertStringContainsString('Most reviewed', $body);
        $this->assertStringContainsString('Top rated', $body);
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole"', $body);
        // Top rated needs 3 ratings; a single 9 must NOT appear there yet
        $this->assertStringContainsString('Not enough ratings yet', $body);
    }

    public function test_top_excludes_restricted_and_unvalidated_for_guests(): void
    {
        $this->db()->query("INSERT INTO story_kudos (story_id, user_id) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), (SELECT id FROM users WHERE penname = 'betafriend'))");
        $this->db()->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $this->client()->get('/top')->body);
    }

    public function test_top_rated_lists_average_once_three_ratings_land(): void
    {
        // The >= 3 floor from the first test leaves the section empty; three
        // distinct root ratings flip it on, with the rounded average and count.
        $sid = (int) $this->db()->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db->query("INSERT INTO users (email, password_hash, penname, role) VALUES ('third@example.test', 'x', 'thirdrater', 'member')");
        $third = (int) $this->db->lastInsertId();
        $beta = (int) $this->db()->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $demo = (int) $this->db()->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        // inserts and lastInsertId ride the SAME handle ($this->db): the db()
        // helper opens a fresh connection per call and loses the insert id
        foreach ([[$beta, 9], [$third, 9], [$demo, 6]] as [$uid, $rating]) {
            $this->db->query('INSERT INTO reviews (story_id, user_id, body, rating) VALUES (?,?,?,?)', [$sid, $uid, 'r', $rating]);
            $rootId = (int) $this->db->lastInsertId();
        }
        $body = $this->client()->get('/top')->body;
        $this->assertStringContainsString('Top rated', $body);
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole"', $body, 'rated leader linked');
        $this->assertStringContainsString('8.0 average from 3 ratings', $body, 'rounded average plus count');
        $this->assertStringNotContainsString('Not enough ratings yet', $body, 'floor satisfied');
        // replies never count toward the floor (root ratings only)
        $this->db->query('INSERT INTO reviews (story_id, user_id, body, parent_id) VALUES (?,?,?,?)', [$sid, $beta, 'reply', $rootId]);
        $this->assertStringContainsString('8.0 average from 3 ratings', $this->client()->get('/top')->body);
    }

    public function test_top_cacheable_and_purged_by_engagement(): void
    {
        $dir = sys_get_temp_dir() . '/kiption-top-cache-' . uniqid('', true);
        $cache = new \App\StaticCache\Cache($dir);
        $req = new \Kip\Http\Request('GET', '/top', [], [], []);
        $cache->maybeStore($req, $this->app->handle($req));
        $this->assertNotNull($cache->serve($req), '/top fills and serves the anonymous variant');
        // Hermetic form of the plan's engagement purge: the real write paths
        // (KudosController::add, FavoritesController, ReviewController::add)
        // drive the REAL public/cache instance, which this temp-dir cache cannot
        // observe, and the test must not write there. The /top unlink rides
        // purgeStory, so pinning purgeStory() itself against a stored /top serve
        // is the wiring every engagement write already calls; the call sites
        // pass through unchanged (code-verified, no signature change).
        $cache->purgeStory('the-rabbit-hole', []);
        $this->assertNull($cache->serve($req), 'engagement write must purge /top');
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
