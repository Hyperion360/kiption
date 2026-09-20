<?php // tests/AuthorStatsTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class AuthorStatsTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-stats-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
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
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-stats-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-stats-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    /** Fresh handle on the shared temp file (the TrendingTest shape). */
    private function db(): Database
    {
        return new Database('sqlite:' . $this->path);
    }

    private function sid(string $slug): int
    {
        return (int) $this->db()->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'];
    }

    private function authorId(): int
    {
        return (int) $this->db()->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    private function memberId(): int
    {
        return (int) $this->db()->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    public function test_stats_page_renders_reads_kudos_and_favorites_per_story(): void
    {
        $rh = $this->sid('the-rabbit-hole');
        $db = $this->db();
        // Rollup rows (chapter_id = 0) only: total 41, 30-day window 29 (the
        // 40-day-old 12 counts toward the total but never the window).
        $db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now'), ?, 0, 9)", [$rh]);
        $db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now','-25 days'), ?, 0, 20)", [$rh]);
        $db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now','-40 days'), ?, 0, 12)", [$rh]);
        // Finding 2's pin: chapter rows must NOT move the sums (summing both
        // sides would double-count every beacon hit and show 61/36 here).
        $db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now'), ?, 3, 7)", [$rh]);
        $db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now','-40 days'), ?, 3, 5)", [$rh]);
        // Two kudos (one member, one guest) and one story favorite.
        $db->query("INSERT INTO story_kudos (story_id, user_id) VALUES (?, (SELECT id FROM users WHERE penname = 'betafriend'))", [$rh]);
        $db->query('INSERT INTO story_kudos (story_id, user_id, ip) VALUES (?, NULL, ?)', [$rh, '10.0.0.9']);
        $db->query('INSERT INTO favorites (user_id, story_id) VALUES ((SELECT id FROM users WHERE penname = ?), ?)', ['betafriend', $rh]);
        $res = $this->client($this->authorId())->get('/stats');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('Reads are approximate', $res->body, 'the R4 approximate-reads label');
        $this->assertStringContainsString('name="robots" content="noindex"', $res->body, 'a member dashboard is never indexed');
        $this->assertStringContainsString('>41<', $res->body, 'total reads sum the rollup rows only');
        $this->assertStringContainsString('>29<', $res->body, 'the 30-day window sum');
        $this->assertStringNotContainsString('>61<', $res->body, 'chapter rows did not leak into the total');
        $this->assertStringContainsString('>2<', $res->body, 'kudos count');
        $this->assertStringContainsString('>1<', $res->body, 'favorites count');
    }

    public function test_own_restricted_and_pending_works_appear_but_deleted_ones_do_not(): void
    {
        // Finding 8's pin: the gate is the OWN-SURFACE gate (author-or-coauthor
        // + not-deleted) and nothing else. The storiesTab guest gates
        // (validated = 1, is_restricted = 0) would strip the author's own
        // restricted and pending works from their dashboard.
        $db = $this->db();
        $db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $db->query("UPDATE stories SET validated = 0 WHERE slug = 'after-hours'");
        $db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated, completed, word_count, deleted_at)
                    VALUES ('Gone Tale', 'gone-tale', (SELECT id FROM users WHERE penname = 'Demo Author'), 2, 1, 1, 10, '2026-09-01T00:00:00Z')");
        $res = $this->client($this->authorId())->get('/stats');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('The Rabbit Hole', $res->body, 'the own restricted work appears');
        $this->assertStringContainsString('After Hours', $res->body, 'the own pending work appears');
        $this->assertStringContainsString('awaiting validation', $res->body, 'the status column explains the pending row');
        $this->assertStringNotContainsString('Gone Tale', $res->body, 'deleted works never appear');
    }

    public function test_coauthor_sees_the_shared_story_and_only_that_one(): void
    {
        $this->db()->query('INSERT INTO coauthors (story_id, user_id) VALUES (?, (SELECT id FROM users WHERE penname = ?))',
            [$this->sid('the-rabbit-hole'), 'betafriend']);
        $res = $this->client($this->memberId())->get('/stats');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('The Rabbit Hole', $res->body, 'the coauthored work appears');
        $this->assertStringNotContainsString('After Hours', $res->body, 'another author\'s solo work does not');
    }

    public function test_member_with_no_stories_sees_the_honest_empty_state(): void
    {
        $res = $this->client($this->memberId())->get('/stats');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('You have no stories yet.', $res->body);
        $this->assertStringNotContainsString('The Rabbit Hole', $res->body);
    }

    public function test_guest_redirects_to_login(): void
    {
        $res = $this->client()->get('/stats');
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location']);
    }

    public function test_account_page_links_the_stats_dashboard(): void
    {
        $res = $this->client($this->authorId())->get('/account');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="/stats"', $res->body);
    }
}
