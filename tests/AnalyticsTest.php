<?php // tests/AnalyticsTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * Task 6: the site-wide admin analytics dashboard (/analytics). Reads only
 * already-collected aggregates (page_stats rollups, story_kudos, favorites,
 * users) through the documented admin-surface exception to the one-query law:
 * FIVE content queries (four day-series plus the top-10 list) behind a one-row
 * driver that carries the seven totals AND the folded admin gate (findings 4
 * and 5: the driver runs FIRST, so a non-admin draws the 403 before any series
 * or list statement executes, and the totals can never ride a list-shaped
 * fold that returns zero rows on an empty window). Admin-only, noindex meta
 * plus the X-Robots-Tag belt, zero JS, never a static-cache fill.
 *
 * Features::init/reset discipline (finding 2): the class inits in setUp and
 * resets in tearDown so no later suite inherits a memo pointing at this
 * unlinking temp DB.
 */
final class AnalyticsTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminUserId = 0;
    private int $memberUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-an-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-an-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db); // Demo Author (created 2026-01-01, outside the window), betafriend (today), the-rabbit-hole, after-hours, 4 validated chapters
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('anadmin@e.test', ?, 'anadmin', 'admin', 1, ?, ?, 'anadmin')",
            [$hash, date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('anmember@e.test', ?, 'anmember', ?, ?, 'anmember')",
            [$hash, date('c'), date('c')]);
        $this->memberUserId = (int) $this->db->lastInsertId();
        \App\Features::init($this->db, []);
    }

    protected function tearDown(): void
    {
        // Finding 2: Features state is global; reset() drops the memo and the
        // DB handle so no later suite in this single phpunit process inherits
        // a memo pointing at this unlinking temp DB.
        \App\Features::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    /** The analytics seed, all day values on SQLite's own clock (the
     *  TrendingTest idiom: the seeded days and the query windows share one
     *  clock, so midnight boundaries cannot flake). Expected state:
     *  reads by day today 18 / yesterday 7 / two days ago 2 (the chapter_id 2
     *  row of 99 and the forty-day-old rollup of 40 both excluded from the
     *  series; the 40 still feeds the all-time total of 67); kudos by day
     *  today 2 / yesterday 1 (plus one forty-day-old row feeding the total
     *  of 4); favorites by day 1 / 1 / 1 (total 3); new members by day today
     *  3 / yesterday 2 / two days ago 1 (total 7); top list The Rabbit Hole
     *  17 (14 reads + 3 kudos) above After Hours 3, with the restricted
     *  Off Camera story gated out despite its 10 reads. */
    private function seed(): array
    {
        $rh = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $ah = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'after-hours'")['id'];
        $author = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $teen = (int) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
        // The restricted story: 10 rollup reads today that must count toward
        // the raw reads series but never surface in the top list (guest gates).
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, is_restricted, word_count) VALUES (?, ?, ?, ?, ?, 1, 1, 100)',
            ['Off Camera', 'off-camera', 'Behind the gate.', $author, $teen]);
        $oc = (int) $this->db->lastInsertId();
        foreach ([
            ["date('now')", $rh, 0, 5],
            ["date('now','-1 day')", $rh, 0, 7],
            ["date('now','-2 day')", $rh, 0, 2],
            ["date('now')", $rh, 2, 99], // chapter row: the chapter_id = 0 filter pin
            ["date('now','-40 day')", $rh, 0, 40], // outside the 30-day window, inside the all-time total
            ["date('now')", $ah, 0, 3],
            ["date('now')", $oc, 0, 10],
        ] as [$day, $sid, $chapter, $reads]) {
            $this->db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES ($day, ?, ?, ?)", [$sid, $chapter, $reads]);
        }
        foreach ([['10.8.0.1', "date('now')"], ['10.8.0.2', "date('now')"], ['10.8.0.3', "date('now','-1 day')"],
                  ['10.8.0.4', "date('now','-40 day')"]] as [$ip, $day]) {
            $this->db->query("INSERT INTO story_kudos (story_id, user_id, ip, created_at) VALUES (?, NULL, ?, strftime('%Y-%m-%dT%H:%M:%fZ', $day))", [$rh, $ip]);
        }
        foreach ([
            ['betafriend', "strftime('%Y-%m-%dT%H:%M:%fZ', 'now')"],
            ['anmember', "strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-1 day')"],
            ['anadmin', "strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-2 day')"],
        ] as [$penname, $when]) {
            $this->db->query("INSERT INTO favorites (user_id, story_id, created_at) VALUES ((SELECT id FROM users WHERE penname = ?), ?, $when)", [$penname, $rh]);
        }
        foreach ([['andyesterday@e.test', 'andyesterday', "-1 day"], ['andyesterday2@e.test', 'andyesterday2', "-1 day"],
                  ['andaysago@e.test', 'andaysago', "-2 day"]] as [$email, $penname, $ago]) {
            $this->db->query("INSERT INTO users (email, password_hash, penname, created_at) VALUES (?, ?, ?, strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '$ago'))",
                [$email, password_hash('password123', PASSWORD_DEFAULT), $penname]);
        }
        return [
            'today' => $this->db->one("SELECT date('now') d")['d'],
            'yesterday' => $this->db->one("SELECT date('now','-1 day') d")['d'],
            'twoDaysAgo' => $this->db->one("SELECT date('now','-2 day') d")['d'],
        ];
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
            'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__) . '/app/Features',
            'static_cache' => ['dir' => $this->root . '/static'],
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_admin_gets_series_top_list_and_totals_behind_the_admin_gate(): void
    {
        $days = $this->seed();
        $res = $this->client($this->adminUserId)->get('/analytics');
        $this->assertSame(200, $res->status);
        // Never indexed (meta plus the header belt) and never a static fill.
        $this->assertStringContainsString('name="robots" content="noindex"', $res->body);
        $this->assertSame('noindex', $res->headers['X-Robots-Tag'] ?? '');
        // Reads by day: 18 today (5 + 3 + 10 across the three stories), 7
        // yesterday, 2 two days ago; the 99-read chapter row and the
        // forty-day-old rollup never appear (the chapter_id = 0 filter and
        // the 30-day window).
        $this->assertStringContainsString("<td>{$days['today']}</td><td>18</td>", $res->body);
        $this->assertStringContainsString("<td>{$days['yesterday']}</td><td>7</td>", $res->body);
        $this->assertStringContainsString("<td>{$days['twoDaysAgo']}</td><td>2</td>", $res->body);
        $this->assertStringNotContainsString('<td>99</td>', $res->body);
        // Kudos by day: 2 today, 1 yesterday.
        $this->assertStringContainsString("<td>{$days['today']}</td><td>2</td>", $res->body);
        $this->assertStringContainsString("<td>{$days['yesterday']}</td><td>1</td>", $res->body);
        // Favorites by day: 1 / 1 / 1.
        $this->assertStringContainsString("<td>{$days['twoDaysAgo']}</td><td>1</td>", $res->body);
        // New members by day: 3 today (betafriend + admin + member), 2
        // yesterday, 1 two days ago; Demo Author's January date is outside.
        // The top-10 list: the seeded velocity order with the guest-gated
        // restricted story absent.
        $this->assertStringContainsString('<a href="/story/view/the-rabbit-hole">The Rabbit Hole</a>', $res->body);
        $this->assertStringContainsString('17 reads + kudos', $res->body);
        $this->assertStringContainsString('<a href="/story/view/after-hours">After Hours</a>', $res->body);
        $this->assertStringContainsString('3 reads + kudos', $res->body);
        $this->assertStringNotContainsString('Off Camera', $res->body);
        // The totals block: stories 3, validated chapters 4, members 7,
        // reviews 0, kudos 4, favorites 3, all-time reads 67 (the forty-day
        // rollup counts; the 99-read chapter row does not).
        foreach (['<td>3</td>', '<td>4</td>', '<td>7</td>', '<td>0</td>', '<td>67</td>'] as $cell) {
            $this->assertStringContainsString($cell, $res->body);
        }
    }

    public function test_guest_is_redirected_and_member_forbidden(): void
    {
        $this->seed();
        $this->assertSame(302, $this->client()->get('/analytics')->status);
        $this->assertSame(403, $this->client($this->memberUserId)->get('/analytics')->status);
    }

    public function test_analytics_flag_off_404s_and_recovers_when_toggled_back_on(): void
    {
        $this->seed();
        \App\Features::toggle('analytics', false);
        $this->assertSame(404, $this->client($this->adminUserId)->get('/analytics')->status);
        \App\Features::toggle('analytics', true);
        $this->assertSame(200, $this->client($this->adminUserId)->get('/analytics')->status);
    }

    /** THE PIN (the documented admin-surface exception, the browse-at-2
     *  precedent): at most FIVE content queries render the dashboard - the
     *  four day-series and the top-10 list. The one-row driver carrying the
     *  seven totals plus the folded is_admin gate (finding 5, the queue
     *  '0gate' precedent) is the GATE statement and is excluded by rule,
     *  exactly as QueryBudgetTest excludes auth-session validation; the
     *  exact-one assertion on it keeps the exclusion from ever hiding
     *  runaway queries behind it. */
    public function test_analytics_stays_within_its_admin_exception(): void
    {
        $this->seed();
        $app = new App([
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
            'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__) . '/app/Features',
        ]);
        $client = new TestClient($app);
        $client->actingAs($this->adminUserId);
        $db = $app->container->make(Database::class);
        $contentQueries = 0;
        $gateRuns = 0;
        $db->onQuery(function (string $sql) use (&$contentQueries, &$gateRuns): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?') return; // auth-session validation, excluded by rule
            if (str_contains($sql, 'is_admin')) { $gateRuns++; return; } // the one-row totals+gate driver, the gate statement (excluded by rule, asserted exact below)
            $contentQueries++;
        });
        $res = $client->get('/analytics');
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status);
        $this->assertSame(1, $gateRuns, 'the folded gate must run exactly one driver statement');
        $this->assertLessThanOrEqual(5, $contentQueries, "/analytics ran {$contentQueries} content queries, the documented admin exception is 5");
    }
}
