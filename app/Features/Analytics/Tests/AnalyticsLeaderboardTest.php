<?php // app/Features/Analytics/Tests/AnalyticsLeaderboardTest.php
namespace App\Features\Analytics\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * The authors-by-30-day-reads leaderboard (the Stage 2 evidence-gate
 * number): rollup rows only (chapter_id = 0, the dashboard's documented
 * double-count rule), validated and not-deleted stories, per author,
 * top 10. Restricted stories COUNT (the number measures reader demand;
 * the top-stories list gates titles for guest visibility, the author
 * table names no titles). Admin-only behind the same driver gate.
 */
final class AnalyticsLeaderboardTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminUserId = 0;
    private int $memberUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-al-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-al-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('aladmin@e.test', ?, 'aladmin', 'admin', 1, ?, ?, 'aladmin')",
            [$hash, date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('almember@e.test', ?, 'almember', ?, ?, 'almember')",
            [$hash, date('c'), date('c')]);
        $this->memberUserId = (int) $this->db->lastInsertId();
        \App\Features::init($this->db, []);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    public function test_authors_rank_by_30_day_rollup_reads_with_exclusions(): void
    {
        // On the seeded world, Demo Author's in-window rollup total is 27
        // (rabbit-hole 14 + after-hours 3 + the restricted Off Camera 10);
        // the 99-read chapter row and the 40-read forty-day-old rollup must
        // not move it. A fresh author with 30 rollup reads today ranks above.
        $demo = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $teen = (int) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
        $rh = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $ah = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'after-hours'")['id'];
        // the restricted story (AnalyticsTest's seed shape): its reads count
        // for the author total, exactly like the raw reads series
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, is_restricted, word_count) VALUES (?, ?, ?, ?, ?, 1, 1, 100)',
            ['Off Camera', 'off-camera', 'Behind the gate.', $demo, $teen]);
        $oc = (int) $this->db->lastInsertId();
        foreach ([["date('now')", $rh, 0, 5], ["date('now','-1 day')", $rh, 0, 7], ["date('now','-2 day')", $rh, 0, 2],
                  ["date('now')", $rh, 2, 99], ["date('now','-40 day')", $rh, 0, 40],
                  ["date('now')", $ah, 0, 3], ["date('now')", $oc, 0, 10]] as [$day, $sid, $chapter, $reads]) {
            $this->db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES ($day, ?, ?, ?)", [$sid, $chapter, $reads]);
        }
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('leader@e.test', ?, 'LeadAuthor', ?, ?, 'leadauthor')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $leader = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, word_count) VALUES (?, ?, ?, ?, ?, 1, 100)',
            ['Lead Story', 'lead-story', 'Leads.', $leader, $teen]);
        $ls = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now'), ?, 0, 30)", [$ls]);
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, word_count) VALUES (?, ?, ?, ?, ?, 0, 100)',
            ['Unvalidated', 'unvalidated-story', 'No.', $leader, $teen]);
        $un = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now'), ?, 0, 500)", [$un]); // validated = 0: excluded

        $body = $this->client($this->adminUserId)->get('/analytics')->body;
        $leadPos = strpos($body, 'LeadAuthor');
        $demoPos = strpos($body, 'Demo Author');
        $this->assertNotFalse($leadPos, 'the leaderboard renders the top author');
        $this->assertNotFalse($demoPos, 'the leaderboard renders the seeded author');
        $this->assertLessThan($demoPos, $leadPos, '30 in-window reads rank above 27');
        $this->assertStringContainsString('30', $body);
        $this->assertStringContainsString('27', $body, 'Demo Author total is rollups only: no 99 chapter row, no 40 out-of-window, no 500 unvalidated');
    }

    public function test_non_admin_still_gets_403(): void
    {
        $this->assertSame(403, $this->client($this->memberUserId)->get('/analytics')->status);
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
            'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__, 4) . '/app/Features',
            'static_cache' => ['dir' => $this->root . '/static'],
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }
}
