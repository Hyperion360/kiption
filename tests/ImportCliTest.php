<?php // tests/ImportCliTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

final class ImportCliTest extends TestCase
{
    private string $root = '';
    private string $dbPath = '';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/tests/Support/EfictionInstall.php';
        $this->root = sys_get_temp_dir() . '/kiption-imp-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->dbPath = tempnam(sys_get_temp_dir(), 'kiption-imp-db-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->dbPath); @unlink($this->dbPath . '-wal'); @unlink($this->dbPath . '-shm');
        @unlink(substr($this->dbPath, 0, -7)); // the bare tempnam stub behind the .sqlite suffix
    }

    /** Builds a 9a bundle from a fresh fixture, returning its path.
     *  Variants: '' modern 3.5.5; 'legacy3x' 3.0-era column set; 'future' 3.5.8-style. */
    private function bundle(string $variant = ''): string
    {
        $src = $this->root . '/src';
        mkdir($src, 0775, true); // the fixture opens its sqlite file inside this dir
        $fx = new \EfictionInstall($src, legacy3x: $variant === 'legacy3x');
        if ($variant === 'future') {
            $fx->pdo->exec('ALTER TABLE fx_fanfiction_stories ADD future_col TEXT');
            $fx->pdo->exec("UPDATE fxs_fanfiction_settings SET version = '3.5.8'");
        }
        $fx->installShims();
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php';
        $token = str_repeat('a', 64);
        file_put_contents($src . '/export-token.php', "<?php return '" . hash('sha256', $token) . "';");
        \EfictionExporter::runner($src, 'fxs_', $token, true, true, false, true);
        foreach (glob($src . '/out-*/kiption-export.tar.gz') as $p) return $p;
        $this->fail('bundle not built');
    }

    private function kip(string $args): array
    {
        // KIP_NAV_FILE keeps the rider's nav-artifact rebuild inside this
        // test's throwaway root (a bare subprocess would otherwise rebuild
        // the repo's app/nav.json)
        exec(sprintf('KIP_DB_DSN=sqlite:%s KIP_STATIC_CACHE_DIR=%s KIP_NAV_FILE=%s PATH="/opt/homebrew/bin:$PATH" php %s %s 2>&1',
            escapeshellarg($this->dbPath), escapeshellarg($this->root . '/cache'), escapeshellarg($this->root . '/nav.json'),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'), $args), $out, $code);
        return [$code, implode("\n", $out)];
    }

    private function db(): \Kip\Database
    {
        return new \Kip\Database('sqlite:' . $this->dbPath);
    }

    public function test_full_commit_import_maps_every_table(): void
    {
        [$code, $out] = $this->kip('migrate');
        $this->assertSame(0, $code, $out);
        [$code, $out] = $this->kip('import:efiction ' . escapeshellarg($this->bundle()) . ' --commit');
        $this->assertSame(0, $code, $out);
        $db = $this->db();
        $this->assertSame(3, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame('Legacy Author', $db->one('SELECT penname FROM users WHERE id = 1')['penname']);
        $this->assertSame(md5('oldpassword'), $db->one('SELECT legacy_md5 FROM users WHERE id = 1')['legacy_md5']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM stories')['c']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM chapters')['c']);
        // chapter text came from the file store through the pipeline: markdown, not HTML
        $this->assertSame('The stored **chapter** text.', trim((string) $db->one('SELECT content FROM chapters')['content']));
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM reviews')['c']);
        $this->assertSame('Thanks!', $db->one('SELECT response FROM reviews WHERE response IS NOT NULL')['response']);
        // the fixture yields exactly 11 map rows (3 users + 3 prefs + 1 story + 1 chapter + 2 reviews + 1 log)
        $this->assertSame(11, (int) $db->one('SELECT COUNT(*) c FROM import_map')['c']);
        $this->assertSame('Unrated', $db->one('SELECT label FROM ratings')['label'], 'empty taxonomy fell back to Unrated');
        // 301 map written (story shape; reviews.php carries its own item+type shape per Task 5)
        $this->assertNotNull($db->one("SELECT * FROM legacy_urls WHERE legacy_path = 'viewstory.php' AND params = 'sid=7'"));
        $this->assertNotNull($db->one("SELECT * FROM legacy_urls WHERE legacy_path = 'reviews.php' AND params = 'item=7&type=ST'"), 'reviews.php rides the canonical ksort form');
        // the index.php-equivalent consult drive over the imported DB (the HTTP smoke is Task 7's)
        $slug = (string) $db->one('SELECT slug FROM stories')['slug'];
        $this->assertSame('/story/view/' . $slug,
            (new \App\Import\LegacyRedirects())->lookup(new \Kip\Http\Request('GET', '/viewstory.php', ['sid' => '7'], [], []), $db));
        $this->assertStringContainsString('verification', $out);
        $this->assertStringContainsString('password file', $out);
        // pages:build ran against the TEST cache dir, never the repo's (finding 6)
        $this->assertDirectoryExists($this->root . '/cache');
    }

    public function test_dry_run_writes_nothing_and_reports(): void
    {
        $this->kip('migrate');
        [$code, $out] = $this->kip('import:efiction ' . escapeshellarg($this->bundle()) . ' --dry-run');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('import report', $out);
        $this->assertStringContainsString('legacy password hashes: 2', $out);
        $db = $this->db();
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM import_map')['c']);
    }

    public function test_recommit_is_idempotent_and_mixed_options_refused(): void
    {
        $this->kip('migrate');
        $b = $this->bundle();
        $this->kip('import:efiction ' . escapeshellarg($b) . ' --commit');
        [$code, $out] = $this->kip('import:efiction ' . escapeshellarg($b) . ' --commit');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('already mapped', $out);
        $this->assertSame(3, (int) $this->db()->one('SELECT COUNT(*) c FROM users')['c'], 'no duplicates');
        [$code2, $out2] = $this->kip('import:efiction ' . escapeshellarg($b) . ' --commit --encoding=latin1');
        $this->assertNotSame(0, $code2, 'different options hash must refuse');
    }

    public function test_import_requires_migrations_and_needs_one_mode(): void
    {
        $b = $this->bundle(); // one fixture per test root: a second build fatals on existing tables
        [$code, ] = $this->kip('import:efiction ' . escapeshellarg($b) . ' --commit');
        $this->assertNotSame(0, $code, 'unmigrated DB refuses');
        $this->kip('migrate');
        [$code2, ] = $this->kip('import:efiction ' . escapeshellarg($b));
        $this->assertNotSame(0, $code2, 'neither --dry-run nor --commit refuses');
    }

    public function test_migration_037_adds_legacy_bcrypt_column(): void
    {
        $this->kip('migrate');
        $cols = $this->db()->all("PRAGMA table_info(users)");
        $names = array_column($cols, 'name');
        $this->assertContains('legacy_md5', $names);   // pre-existing seam intact
        $this->assertContains('legacy_bcrypt', $names); // new
    }

    public function test_bcrypt_member_imported_with_carried_hash(): void
    {
        $this->kip('migrate');
        [$code, $out] = $this->kip('import:efiction ' . escapeshellarg($this->bundle()) . ' --commit');
        $this->assertSame(0, $code, $out);
        $db = $this->db();
        $row = $db->one("SELECT id, legacy_md5, legacy_bcrypt FROM users WHERE email = 'bcrypt@example.test'");
        $this->assertNotNull($row, 'the bcrypt fixture member imported');
        $this->assertNull($row['legacy_md5']);
        $this->assertNotNull($row['legacy_bcrypt']);
        $this->assertTrue(password_verify('bcryptpassword', $row['legacy_bcrypt']));
    }

    public function test_legacy_3x_schema_gap_bundle_imports_full_counts(): void
    {
        $this->kip('migrate');
        [$code, $out] = $this->kip('import:efiction ' . escapeshellarg($this->bundle('legacy3x')) . ' --commit');
        $this->assertSame(0, $code, $out);
        $db = $this->db();
        // same member/story counts as the modern fixture: the gaps are
        // optional columns the mapper never needed
        $this->assertSame(3, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM stories')['c']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM chapters')['c']);
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM reviews')['c']);
    }

    public function test_future_release_bundle_imports_full_counts(): void
    {
        $this->kip('migrate');
        [$code, $out] = $this->kip('import:efiction ' . escapeshellarg($this->bundle('future')) . ' --commit');
        $this->assertSame(0, $code, $out);
        $db = $this->db();
        $this->assertSame(3, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM stories')['c']);
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM reviews')['c']);
    }

    /** The SourceForge "latest version" download (eFiction 3.5.2,
     *  eFiction352.zip, the last artifact the project ever published there)
     *  round-trips. Its schema differs from the 3.5.5 fixture in exactly
     *  two tables and the importer reads neither difference: authorprefs
     *  predates tinyMCE, and authorfields carries field_code_in /
     *  field_code_out / field_on instead of field_order / field_profile
     *  (the 3.5.5 custom-fields redesign). ALTER the fixture to the 3.5.2
     *  shape, then run the real exporter and importer end to end. */
    public function test_sourceforge_352_schema_round_trips(): void
    {
        $src = $this->root . '/src';
        mkdir($src, 0775, true);
        $fx = new \EfictionInstall($src);
        $fx->installShims();
        $fx->pdo->exec('ALTER TABLE fx_fanfiction_authorprefs DROP COLUMN "tinyMCE"');
        $fx->pdo->exec('CREATE TABLE fx_fanfiction_authorfields_352 (field_id INTEGER PRIMARY KEY AUTOINCREMENT, field_type TEXT, field_name TEXT, field_title TEXT, field_options TEXT, field_code_in TEXT, field_code_out TEXT, field_on TEXT)');
        $fx->pdo->exec('INSERT INTO fx_fanfiction_authorfields_352 (field_id, field_type, field_name, field_title, field_options) SELECT field_id, field_type, field_name, field_title, field_options FROM fx_fanfiction_authorfields');
        $fx->pdo->exec('DROP TABLE fx_fanfiction_authorfields');
        $fx->pdo->exec('ALTER TABLE fx_fanfiction_authorfields_352 RENAME TO fx_fanfiction_authorfields');
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php';
        $token = str_repeat('a', 64);
        file_put_contents($src . '/export-token.php', "<?php return '" . hash('sha256', $token) . "';");
        \EfictionExporter::runner($src, 'fxs_', $token, true, true, false, true);
        $bundle = '';
        foreach (glob($src . '/out-*/kiption-export.tar.gz') as $p) $bundle = $p;
        $this->assertNotSame('', $bundle, 'bundle not built');
        [$code, $out] = $this->kip('migrate');
        $this->assertSame(0, $code, $out);
        [$code, $out] = $this->kip('import:efiction ' . escapeshellarg($bundle) . ' --commit');
        $this->assertSame(0, $code, $out);
        $db = $this->db();
        // the shared fixture now seeds three members (uid 3 is the bcrypt
        // member), so one more user map row than the 3.5.5 baseline
        $this->assertSame(3, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM stories')['c']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM chapters')['c']);
        $this->assertSame(10, (int) $db->one('SELECT COUNT(*) c FROM import_map')['c'], 'the 3.5.5 map rows plus the third member');
    }
}
