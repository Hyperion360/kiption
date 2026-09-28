<?php // tests/ImporterTest.php
namespace App\Tests;
use App\Import\Importer;
use Kip\Database;
use PHPUnit\Framework\TestCase;

/** Drives the REAL Importer class in-process (the CLI tests shell out to
 *  bin/kip, which pcov cannot see): coverage for the orchestration plus
 *  regression pins for the QA-found resume and rating-fallback bugs. */
final class ImporterTest extends TestCase
{
    private string $dbPath = '';
    private string $cacheDir = '';
    /** @var string[] every temp root created (resume tests build two bundles) */
    private array $roots = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/tests/Support/EfictionInstall.php';
        $this->dbPath = tempnam(sys_get_temp_dir(), 'kiption-impcov-db-') . '.sqlite';
        $this->cacheDir = $this->newRoot() . '/cache';
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $r) { exec('rm -rf ' . escapeshellarg($r)); }
        @unlink($this->dbPath); @unlink($this->dbPath . '-wal'); @unlink($this->dbPath . '-shm');
        @unlink(substr($this->dbPath, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    private function newRoot(): string
    {
        $r = sys_get_temp_dir() . '/kiption-impcov-' . uniqid('', true);
        mkdir($r . '/src', 0775, true);
        return $this->roots[] = $r;
    }

    /** Builds a bundle from a fresh fixture, after optionally extending its
     *  rows (the callback receives the fixture before the shims install). */
    private function bundle(?callable $extend = null): string
    {
        $root = $this->newRoot();
        $fx = new \EfictionInstall($root . '/src');
        if ($extend !== null) $extend($fx, $root);
        $fx->installShims();
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php';
        $token = str_repeat('a', 64);
        file_put_contents($root . '/src/export-token.php', "<?php return '" . hash('sha256', $token) . "';");
        \EfictionExporter::runner($root . '/src', 'fxs_', $token, true, true, false, true);
        foreach (glob($root . '/src/out-*/kiption-export.tar.gz') as $p) return $p;
        $this->fail('bundle not built');
    }

    private function config(): array
    {
        return [
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'app_dir' => dirname(__DIR__) . '/app',
            'db' => ['dsn' => 'sqlite:' . $this->dbPath],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->roots[0] . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->roots[0] . '/uploads'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'static_cache' => ['enabled' => true, 'dir' => $this->cacheDir],
            'nav_file' => $this->navFile(),
        ];
    }

    /** The nav artifact path the rider rebuilds into (finding 7: in-process
     *  import() with nav_file in config; a subprocess would write the repo's
     *  app/nav.json). */
    private function navFile(): string
    {
        return $this->roots[0] . '/nav.json';
    }

    private function migrate(): void
    {
        (new \Kip\Migrations\Migrator($this->db(), \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    private function db(): Database
    {
        return new Database('sqlite:' . $this->dbPath);
    }

    private function import(string $bundle, string $mode, string $encoding = 'auto', bool $allowMissing = false): string
    {
        return (new Importer($this->db(), new \App\Import\BundleReader($bundle), $mode, $encoding, $allowMissing, $this->config()))->run();
    }

    public function test_unresolvable_rating_falls_back_to_unrated(): void
    {
        $this->migrate();
        $b = $this->bundle(function (\EfictionInstall $fx): void {
            $p = $fx->prefix;
            // a real rating exists, but story 9's rid resolves to nothing
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_ratings (rid, rating, ratingwarning, warningtext) VALUES (5, 'Teen', '0', '')");
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_stories (sid, title, summary, catid, classes, rid, date, updated, uid, validated, completed, wordcount, count) VALUES (9, 'Unrated Story', 'x', '0', '0', '0', '2011-01-01 00:00:00', '2012-01-01 00:00:00', 1, '1', '0', 1, 0)");
        });
        $out = $this->import($b, 'commit');
        $db = $this->db();
        $row = $db->one('SELECT r.label FROM stories s JOIN ratings r ON r.id = s.rating_id WHERE s.title = ?', ['Unrated Story']);
        $this->assertSame('Unrated', $row['label'], 'a story whose rid resolves to nothing must fall back to Unrated, not crash');
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM ratings')['c'], 'Teen plus the on-demand Unrated row');
        $this->assertStringContainsString('dropped rating fallback: Unrated: 1', $out);
        $this->assertStringContainsString('fanfiction_ratings         manifest 1, imported 2, rejected 0, skipped 0 (+1 Unrated fallback)', $out, 'verification stays honest, no MISMATCH');
    }

    public function test_resume_adds_chapter_to_previously_imported_story(): void
    {
        $this->migrate();
        $this->import($this->bundle(), 'commit');
        // second bundle from the same legacy ids plus one new chapter (file present)
        $second = $this->bundle(function (\EfictionInstall $fx, string $root): void {
            $p = $fx->prefix;
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_chapters (chapid, title, inorder, storytext, validated, wordcount, sid, uid) VALUES (14, 'Late Chapter', 2, 'Added <i>later</i>.', '1', 3, 7, 1)");
            file_put_contents($root . '/src/stories/1/14.txt', 'Added <i>later</i>.');
        });
        $out = $this->import($second, 'commit');
        $db = $this->db();
        $row = $db->one("SELECT c.created_at, s.created_at s_created FROM chapters c JOIN stories s ON s.id = c.story_id WHERE c.title = 'Late Chapter'");
        $this->assertNotNull($row, 'the new chapter must import');
        $this->assertSame($row['s_created'], $row['created_at'], 'the chapter inherits the existing story dates');
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM chapters')['c'], 'no duplicates');
        $this->assertStringContainsString('already mapped', $out);
    }

    public function test_commit_recommit_and_dry_run_in_process(): void
    {
        $this->migrate();
        $b = $this->bundle(function (\EfictionInstall $fx): void {
            $p = $fx->prefix;
            // store=db: the chapter text comes from the storytext column
            $fx->pdo->exec("UPDATE fxs_fanfiction_settings SET store = 'db'");
            $fx->pdo->exec("UPDATE {$p}fanfiction_chapters SET storytext = 'The stored <b>chapter</b> text.' WHERE chapid = 10");
        });
        $out = $this->import($b, 'commit');
        $db = $this->db();
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame('The stored **chapter** text.', trim((string) $db->one('SELECT content FROM chapters')['content']));
        $this->assertStringContainsString('verification', $out);
        $this->assertStringContainsString('password file', $out);
        $this->assertFileExists($this->cacheDir . '/index.html', 'pages:build ran against the config cache dir');
        $this->assertNotEmpty(glob(dirname($b) . '/pre-import-*.sqlite'), 'snapshot written next to the bundle');
        $this->assertNotNull($db->one("SELECT * FROM legacy_urls WHERE legacy_path = 'viewstory.php' AND params = 'sid=7'"), '301 map written');
        // re-commit: SELECT-first skips, no duplicates
        $again = $this->import($b, 'commit');
        $this->assertStringContainsString('already mapped', $again);
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        // dry-run after commit (same options): legal, writes nothing new
        $dry = $this->import($b, 'dry-run');
        $this->assertStringContainsString('dry-run: nothing written', $dry);
        $this->assertSame(9, (int) $db->one('SELECT COUNT(*) c FROM import_map')['c']);
    }

    public function test_allow_missing_text_stubs_missing_chapters(): void
    {
        $this->migrate();
        $b = $this->bundle(function (\EfictionInstall $fx, string $root): void {
            unlink($root . '/src/stories/1/10.txt');
        });
        $out = $this->import($b, 'commit', 'auto', true);
        $this->assertStringContainsString('missing story files: 1', $out);
        $this->assertStringContainsString('was missing from the export bundle', (string) $this->db()->one('SELECT content FROM chapters')['content'], 'a visible stub chapter imports');
        // without the flag the same shape rejects the chapter instead
        $this->migrateFresh();
        $out2 = $this->import($this->bundle(function (\EfictionInstall $fx, string $root): void {
            unlink($root . '/src/stories/1/10.txt');
        }), 'commit', 'auto', false);
        $this->assertStringContainsString('REJECT chapter text missing: 1', $out2);
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM chapters')['c']);
    }

    public function test_unmigrated_database_refuses(): void
    {
        $b = $this->bundle();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run `kip migrate` first');
        $this->import($b, 'commit');
    }

    public function test_latin1_override_converts_story_files(): void
    {
        $this->migrate();
        // the jsonl is always valid UTF-8 (the exporter substitutes invalid
        // bytes with a count), so the latin1 override's real cargo is the
        // byte-faithful story FILES: caf\xE9 must arrive as café, not caf?
        $b = $this->bundle(function (\EfictionInstall $fx, string $root): void {
            file_put_contents($root . '/src/stories/1/10.txt', "DB caf\xE9 <b>text</b>.");
        });
        $out = $this->import($b, 'commit', 'latin1');
        $this->assertSame('DB café **text**.', trim((string) $this->db()->one('SELECT content FROM chapters')['content']), 'latin1 override converts instead of substituting');
        $this->assertStringContainsString('substituted chars: 0', $out);
    }

    public function test_charset_sampling_short_circuits_and_batches_flush(): void
    {
        $this->migrate();
        $b = $this->bundle(function (\EfictionInstall $fx, string $root): void {
            // one invalid byte in the chapter FILE: the only import-time
            // substitution the bundle can carry (jsonl columns are pre-munged
            // valid UTF-8 by the exporter)
            file_put_contents($root . '/src/stories/1/10.txt', "swampy \xB1 <b>tail</b>");
            $p = $fx->prefix;
            $ins = $fx->pdo->prepare("INSERT INTO {$p}fanfiction_news (nid, author, title, story, time) VALUES (?, 'a', 't', ?, '2010-01-01 00:00:00')");
            for ($i = 0; $i < 600; $i++) { $ins->execute([$i + 10, "filler $i"]); }
        });
        $out = $this->import($b, 'commit');
        $this->assertStringContainsString('substituted chars: 1', $out, 'only the file substituted');
        $this->assertStringContainsString('samples (spot-check these):', $out, 'the substituted original is listed for spot-checking');
        $this->assertSame(600, (int) $this->db()->one('SELECT COUNT(*) c FROM news')['c'], '600 filler rows');
        $row = $this->db()->one("SELECT checkpoints FROM import_runs WHERE status = 'committed'");
        $this->assertNotSame('{}', $row['checkpoints'], 'the 500-row batch flush wrote a checkpoint');
    }

    public function test_failed_resume_rolls_back_and_rethrows(): void
    {
        $this->migrate();
        $this->import($this->bundle(), 'commit');
        $before = (int) $this->db()->one('SELECT COUNT(*) c FROM import_map')['c'];
        // the new chapter collides on (story_id, position) with chapter 10:
        // the insert throws mid-pass, the whole run must roll back atomically
        $second = $this->bundle(function (\EfictionInstall $fx, string $root): void {
            $p = $fx->prefix;
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_chapters (chapid, title, inorder, storytext, validated, wordcount, sid, uid) VALUES (14, 'Clash', 1, 'x', '1', 1, 7, 1)");
            file_put_contents($root . '/src/stories/1/14.txt', 'x');
        });
        try {
            $this->import($second, 'commit');
            $this->fail('the position clash must throw');
        } catch (\PDOException) {
        }
        $db = $this->db();
        $this->assertSame($before, (int) $db->one('SELECT COUNT(*) c FROM import_map')['c'], 'zero residue from the failed run');
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM chapters')['c'], 'the clashing chapter never landed');
    }

    public function test_adversarial_bundle_maps_rejects_and_recommits(): void
    {
        $this->migrate();
        $b = $this->bundle(function (\EfictionInstall $fx, string $root): void {
            $p = $fx->prefix;
            $pdo = $fx->pdo;
            // taxonomy with one dangling class type and one dangling character category
            $pdo->exec("INSERT INTO {$p}fanfiction_categories (catid, parentcatid, category, description, locked, leveldown, displayorder) VALUES (5, -1, 'Cat Five', 'A <b>bold</b> cat', '1', 2, 3)");
            $pdo->exec("INSERT INTO {$p}fanfiction_classtypes (classtype_id, classtype_name) VALUES (9, 'genre')");
            $pdo->exec("INSERT INTO {$p}fanfiction_classes (class_id, class_type, class_name) VALUES (20, 9, 'romance'), (21, 777, 'orphan class')");
            $pdo->exec("INSERT INTO {$p}fanfiction_characters (charid, catid, charname, bio) VALUES (30, 5, 'Hero', 'Brave <script>alert(1)</script>bio'), (31, 999, 'Orphan', 'no category')");
            $pdo->exec("INSERT INTO {$p}fanfiction_ratings (rid, rating, ratingwarning, warningtext) VALUES (5, 'Teen', '0', ''), (6, 'Adult', '1', 'explicit')");
            // admins CSV user, a penname case collision, EAV bio plus a dropped custom field
            $pdo->exec("INSERT INTO {$p}fanfiction_authors (uid, penname, email, password, date, admincreated) VALUES (3, 'The Admin', 'admin@e.test', '" . md5('adminpass') . "', '2010-01-01 00:00:00', '0')");
            $pdo->exec("INSERT INTO {$p}fanfiction_authors (uid, penname, email, password, date, admincreated) VALUES (4, 'LEGACY AUTHOR', 'caps@e.test', '0', '2020-02-02 00:00:00', '0')");
            $pdo->exec("INSERT INTO {$p}fanfiction_authorprefs (uid, validated, newreviews, newrespond, sortby, storyindex, alertson, ageconsent) VALUES (4, 0, 0, 0, 2, 2, 1, '2009-01-01 00:00:00')");
            $pdo->exec("INSERT INTO {$p}fanfiction_authorfields (field_id, field_name) VALUES (2, 'website')");
            $pdo->exec("INSERT INTO {$p}fanfiction_authorinfo (uid, field_id, info) VALUES (3, 1, 'EAV bio wins'), (3, 2, 'http://x.test')");
            // hostile prose story with dangling CSV tokens and a multi-rating CSV
            $pdo->exec("INSERT INTO {$p}fanfiction_stories (sid, title, summary, storynotes, catid, classes, charid, rid, date, updated, uid, validated, completed, featured, wordcount, count) VALUES "
                . "(8, 'Hostile <em>Tale</em>', '<script>alert(1)</script>Sum <a href=\"javascript:alert(1)\">link</a>', 'notes &amp; more', '5,77', '20,88', '30', '5,6', '2011-02-02 00:00:00', '2012-02-02 00:00:00', 1, '1', '0', '1', 10, 7), "
                . "(9, 'Zero Date', 'zd', NULL, '5', '0', '0', '5', '0000-00-00 00:00:00', '2013-03-03 00:00:00', 1, '1', '0', '0', 5, 0), "
                . "(10, 'No Author', 'na', NULL, '0', '0', '0', '5', '2011-01-01 00:00:00', '2011-01-01 00:00:00', 777, '1', '0', '0', 1, 0)");
            // hostile chapter FILE (store=files) plus an orphan chapter
            $pdo->exec("INSERT INTO {$p}fanfiction_chapters (chapid, title, inorder, storytext, endnotes, validated, wordcount, sid, uid) VALUES (11, 'Ch <b>Eleven</b>', 1, NULL, 'end <img src=\"javascript:alert(1)\" alt=\"x\">', '1', 12, 8, 1), (12, 'Orphan chapter', 1, 'text', NULL, '1', 1, 999, 1)");
            file_put_contents($root . '/src/stories/1/11.txt', "<p>Hello <script>alert('file')</script>world</p><a href=\"javascript:alert(1)\">bad</a> <a href=\"https://ok.test/x\">good</a>");
            // series: open (owner 3), no-owner reject, closed subseries; items incl dangling
            $pdo->exec("INSERT INTO {$p}fanfiction_series (seriesid, title, summary, uid, isopen, challenges) VALUES (1, 'Series One', 's1 <b>bold</b>', 3, 2, '4'), (2, 'No Owner', 's2', 777, 1, '0'), (3, 'Sub Series', 's3', 3, 0, '0')");
            $pdo->exec("INSERT INTO {$p}fanfiction_inseries (seriesid, sid, subseriesid, confirmed, inorder) VALUES (1, 8, 0, 1, 1), (1, 0, 3, 1, 2), (2, 8, 0, 1, 1), (1, 999, 0, 1, 3)");
            $pdo->exec("INSERT INTO {$p}fanfiction_coauthors (sid, uid) VALUES (8, 3), (8, 777), (999, 1)");
            // reviews: SE target, dangling target, clamped rating, missing uid, dangling chapter anchor
            $pdo->exec("INSERT INTO {$p}fanfiction_reviews (reviewid, item, chapid, reviewer, uid, review, date, rating, respond, type) VALUES "
                . "(101, 1, 0, '0', 3, 'Series <i>review</i>', '2012-04-01 00:00:00', 3, '0', 'SE'), "
                . "(102, 999, 0, '0', 1, 'dangling', '2012-04-02 00:00:00', NULL, '0', 'ST'), "
                . "(103, 8, 11, '0', 4, 'clamp <br><br><i>Author''s Response: rsp &amp; more</i>', '2012-04-03 00:00:00', 99, '1', 'ST'), "
                . "(104, 8, 0, '0', 777, 'uid gone', '2012-04-04 00:00:00', 2, '0', 'ST'), "
                . "(105, 8, 777, '0', 1, 'chapid gone', '2012-04-05 00:00:00', 2, '0', 'ST'), "
                . "(106, 9, 0, '0', 1, 'on the zero-date story', '2012-06-01 00:00:00', 2, '0', 'ST')");
            $pdo->exec("INSERT INTO {$p}fanfiction_favorites (uid, item, type, comments) VALUES (3, 8, 'ST', 'c'), (3, 999, 'ST', ''), (3, 1, 'SE', ''), (3, 3, 'AU', ''), (3, 8, 'XX', '')");
            $pdo->exec("INSERT INTO {$p}fanfiction_news (nid, author, title, story, time) VALUES (1, 'Old Admin', 'News <b>One</b>', 'Body ok', '2010-06-01 00:00:00')");
            $pdo->exec("INSERT INTO {$p}fanfiction_comments (cid, nid, uid, comment, time) VALUES (1, 1, 3, 'first!', '2010-06-02 00:00:00'), (2, 999, 1, 'dangling', '2010-06-03 00:00:00'), (3, 1, 777, 'uid gone', '2010-06-04 00:00:00')");
            $pdo->exec("INSERT INTO {$p}fanfiction_log (log_id, log_action, log_uid, log_ip, log_timestamp, log_type) VALUES (2, 'SR:8', NULL, NULL, '2012-05-01 00:00:00', 'SR')");
            $pdo->exec("ALTER TABLE fxs_fanfiction_settings ADD COLUMN admins TEXT");
            $pdo->exec("UPDATE fxs_fanfiction_settings SET admins = '3, 0, abc, 777' WHERE sitekey = 'FXKEY'");
        });
        $out = $this->import($b, 'commit');
        $db = $this->db();
        // hostile prose sanitized, good URLs kept
        $content = (string) $db->one("SELECT content FROM chapters WHERE title = 'Ch <b>Eleven</b>'")['content'];
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringNotContainsString('javascript:', $content);
        $this->assertStringContainsString('[good](https://ok.test/x)', $content);
        $this->assertSame('end', (string) $db->one("SELECT notes_after FROM chapters WHERE title = 'Ch <b>Eleven</b>'")['notes_after'], 'the javascript: img vanished');
        $this->assertSame('Sum link', $db->one("SELECT summary FROM stories WHERE title LIKE 'Hostile%'")['summary']);
        // rejects and drops tallied
        $this->assertStringContainsString('REJECT class type missing: 1', $out);
        $this->assertStringContainsString('REJECT character category missing: 1', $out);
        $this->assertStringContainsString('REJECT story author missing: 1', $out);
        $this->assertStringContainsString('REJECT chapter story missing: 1', $out);
        $this->assertStringContainsString('REJECT series owner missing: 1', $out);
        $this->assertStringContainsString('REJECT series item unresolvable: 2', $out);
        $this->assertStringContainsString('REJECT coauthor unresolvable: 2', $out);
        $this->assertStringContainsString('REJECT review target unresolvable: 1', $out);
        $this->assertStringContainsString('REJECT favorite unresolvable: 2', $out);
        $this->assertStringContainsString('REJECT news comment target missing: 1', $out);
        $this->assertStringContainsString('dropped category leveldown: 1', $out);
        $this->assertStringContainsString('dropped authorinfo field website: 1', $out);
        $this->assertStringContainsString('dropped series challenges CSV: 1', $out);
        $this->assertStringContainsString('dropped favorite comments: 1', $out);
        // Task 9 rider: the fixture's custpage pair maps (the old drop stubs
        // are gone; the rider test carries the full behavioral assertions)
        $this->assertNotNull($db->one("SELECT * FROM pages WHERE slug = 'about'"), 'the custpage rider maps the messages body to a page');
        $this->assertNotNull($db->one("SELECT * FROM nav_links WHERE label = 'About' AND url = '/page/view/about'"), 'the pagelink rider maps the nav link');
        // roles, EAV, junctions, clamps, anchors
        $this->assertSame('admin', $db->one("SELECT role FROM users WHERE penname = 'The Admin'")['role'], 'manifest admins CSV upgraded (junk tokens ignored)');
        $this->assertSame('EAV bio wins', $db->one("SELECT bio FROM users WHERE penname = 'The Admin'")['bio']);
        $this->assertSame('LEGACY AUTHOR-3', $db->one('SELECT penname FROM users ORDER BY id DESC LIMIT 1')['penname'], 'case collision suffixing chains');
        $this->assertSame(10, (int) $db->one('SELECT rating FROM reviews WHERE body = ?', ['clamp'])['rating'], '99 clamps to 10');
        $this->assertSame('rsp & more', $db->one('SELECT response FROM reviews WHERE body = ?', ['clamp'])['response']);
        $this->assertNull($db->one("SELECT user_id FROM reviews WHERE body = 'uid gone'")['user_id']);
        $this->assertNull($db->one("SELECT chapter_id FROM reviews WHERE body = 'chapid gone'")['chapter_id']);
        $this->assertSame(3, (int) $db->one('SELECT COUNT(*) c FROM favorites')['c']);
        $this->assertSame('2010-06-04T00:00:00+00:00', $db->one("SELECT created_at FROM news_comments WHERE body = 'uid gone'")['created_at'], 'date-only legacy times parse');
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM legacy_log')['c']);
        // zero-date story re-dated to its earliest review
        $this->assertSame('2012-06-01T00:00:00+00:00', $db->one("SELECT created_at FROM stories WHERE title = 'Zero Date'")['created_at']);
        $this->assertStringContainsString('stories re-dated via earliest chapter/review date: 1', $out);
        // re-commit: every table skips through import_map, no duplicates
        $again = $this->import($b, 'commit');
        $this->assertStringContainsString('already mapped', $again);
        $this->assertSame(4, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame(3, (int) $db->one('SELECT COUNT(*) c FROM stories')['c'], 'story 10 rejected (author missing), no duplicates');
    }

    public function test_rider_maps_custpages_and_pagelinks(): void
    {
        $this->migrate();
        // the re-keyed fixture (real 3.5.5 columns) seeds the linked pair:
        // messages 'about' + pagelink 'About' -> viewpage.php?page=about (the
        // exact shape admin/custpages.php mints); the extras exercise the edges
        $b = $this->bundle(function (\EfictionInstall $fx): void {
            $p = $fx->prefix;
            // an external link_url: the internal-only url guard drops it with a count
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_pagelinks (link_id, link_name, link_text, link_url, link_target, link_access) VALUES (2, 'recs_link', 'Recs', 'https://example.com/recs', '1', 0)");
            // a viewpage link whose message row does not exist: placeholder body
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_pagelinks (link_id, link_name, link_text, link_url, link_target, link_access) VALUES (3, 'ghost_link', 'Ghost', 'viewpage.php?page=ghost', '0', 0)");
            // a message no pagelink references (eFiction's welcome mail text)
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_messages (message_id, message_name, message_title, message_text) VALUES (2, 'welcome', '', 'Hello member.')");
        });
        $out = $this->import($b, 'commit');
        $db = $this->db();
        // the custpage lands as a page carrying the messages row's BODY (and
        // the body renders, markdown-at-rest), not just the slug existing
        $page = $db->one("SELECT title, body FROM pages WHERE slug = 'about'");
        $this->assertNotNull($page, 'the custpage rider creates the page');
        $this->assertSame('Welcome to the archive.', $page['body']);
        $this->assertSame('About', $page['title']);
        $this->assertStringContainsString('Welcome to the archive.', \App\Markdown::render($page['body']));
        // a viewpage link with no message row still imports, placeholder counted
        $this->assertSame('Ghost (imported page)', $db->one("SELECT body FROM pages WHERE slug = 'ghost'")['body']);
        $this->assertStringContainsString('dropped page body missing (placeholder imported): 1', $out);
        // the pagelink lands as a nav_link; external urls drop with a count
        $this->assertNotNull($db->one("SELECT * FROM nav_links WHERE label = 'About' AND url = '/page/view/about'"));
        $this->assertNull($db->one("SELECT * FROM nav_links WHERE label = 'Recs'"), 'the internal-only guard drops external urls');
        $this->assertStringContainsString('REJECT pagelink url external/custom: 1', $out);
        $this->assertStringContainsString('REJECT message without pagelink: 1', $out);
        // the nav artifact rebuilt during the commit, ordered by position
        $this->assertFileExists($this->navFile());
        $this->assertSame(
            [['label' => 'About', 'url' => '/page/view/about'], ['label' => 'Ghost', 'url' => '/page/view/ghost']],
            \App\NavLinks::all($this->navFile())
        );
        // verification mentions pages/nav_links and reconciles
        $this->assertStringContainsString('fanfiction_messages        manifest 2, imported 1, rejected 1, skipped 0', $out);
        $this->assertStringContainsString('fanfiction_pagelinks       manifest 3, imported 2, rejected 1, skipped 0', $out);
        // NO import_map rows: pages are slug-PK'd and new_id is INTEGER
        $this->assertSame(0, (int) $db->one("SELECT COUNT(*) c FROM import_map WHERE legacy_table IN ('fanfiction_messages', 'fanfiction_pagelinks')")['c']);
        // the old drop stubs are gone
        $this->assertStringNotContainsString('dropped pagelinks', $out);
        $this->assertStringNotContainsString('dropped messages', $out);
        // idempotent re-run: no duplicates, first body wins, outcomes skip
        $again = $this->import($b, 'commit');
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) c FROM nav_links WHERE label = 'About'")['c']);
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) c FROM pages WHERE slug = 'about'")['c']);
        $this->assertSame('Welcome to the archive.', $db->one("SELECT body FROM pages WHERE slug = 'about'")['body'], 'first body wins');
        $this->assertStringContainsString('fanfiction_pagelinks       manifest 3, imported 0, rejected 1, skipped 2', $again);
        $this->assertStringContainsString('fanfiction_messages        manifest 2, imported 0, rejected 1, skipped 1', $again);
    }

    public function test_rider_collision_keeps_verifydiff_honest(): void
    {
        // QA 10a: two pagelinks share one messages row, and the first label's
        // page slug already exists in the target (an operator page or a
        // seeded demo archive). The message tallies SKIPPED and never bumps
        // riderImported, so verifyDiff used to fall back to the unrelated
        // pages-INSERT tally for its imported count and printed a false
        // MISMATCH on a perfectly legal import (the watchdog is reserved for
        // corrupt manifests; the 9b contract).
        $this->migrate();
        $b = $this->bundle(function (\EfictionInstall $fx): void {
            $p = $fx->prefix;
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_pagelinks (link_id, link_name, link_text, link_url, link_target, link_access) VALUES (4, 'about2_link', 'About Us', 'viewpage.php?page=about', '0', 0)");
        });
        $this->db()->query("INSERT INTO pages (slug, title, body) VALUES ('about', 'Operator About', 'Pre-existing.')");
        $out = $this->import($b, 'commit');
        $db = $this->db();
        // both labels still land: the collision keeps the operator page, the
        // second label gets its own slug; the message row stays the body source
        $this->assertSame('Pre-existing.', $db->one("SELECT body FROM pages WHERE slug = 'about'")['body']);
        $this->assertSame('Welcome to the archive.', $db->one("SELECT body FROM pages WHERE slug = 'about-us'")['body']);
        $this->assertSame(2, (int) $db->one("SELECT COUNT(*) c FROM nav_links")['c']);
        // the honest per-legacy-row outcome: skipped, no phantom imported
        // count borrowed from the pages insert tally, no MISMATCH
        $this->assertStringContainsString('fanfiction_messages        manifest 1, imported 0, rejected 0, skipped 1', $out);
        $this->assertStringNotContainsString('MISMATCH', $out);
    }

    public function test_mixed_options_refuse_in_process(): void
    {
        $this->migrate();
        $b = $this->bundle();
        $this->import($b, 'commit');
        // mode is not in the hash, but encoding is: a different family refuses
        try {
            $this->import($b, 'commit', 'latin1');
            $this->fail('a different options family must refuse');
        } catch (\RuntimeException $e) {
            $this->assertSame('this database already has imports under different options; refusing to mix', $e->getMessage());
        }
    }

    private function migrateFresh(): void
    {
        // rebuild the schema from scratch on the same throwaway path
        @unlink($this->dbPath); @unlink($this->dbPath . '-wal'); @unlink($this->dbPath . '-shm');
        touch($this->dbPath);
        $this->migrate();
    }
}
