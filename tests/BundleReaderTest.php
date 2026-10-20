<?php // tests/BundleReaderTest.php
namespace App\Tests;
use App\Import\BundleReader;
use PHPUnit\Framework\TestCase;

final class BundleReaderTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kiption-bundle-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        require_once dirname(__DIR__) . '/tests/Support/EfictionInstall.php';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** Runs the exporter over a fixture variant. Variants:
     *  ''        the modern 3.5.5 fixture
     *  'legacy3x' a 3.0-era column set (no news.comments, series.numstories,
     *             pagelinks.link_key; settings.version '3.0')
     *  'future'   a 3.5.8-style source (version string + an extra unknown column)
     *  'pre30'    a 2.0.2-shaped source with NO fanfiction_coauthors table
     *  @return array{0: string, 1: int} html + HTTP status */
    private function runExport(string $variant = ''): array
    {
        mkdir($this->root . '/src', 0775, true); // fixture requires its root to exist
        $fx = new \EfictionInstall($this->root . '/src', legacy3x: $variant === 'legacy3x');
        if ($variant === 'future') {
            $fx->pdo->exec('ALTER TABLE fx_fanfiction_stories ADD future_col TEXT');
            $fx->pdo->exec("UPDATE fxs_fanfiction_settings SET version = '3.5.8'");
        }
        if ($variant === 'pre30') {
            $fx->pdo->exec('DROP TABLE fx_fanfiction_coauthors');
            $fx->pdo->exec("UPDATE fxs_fanfiction_settings SET version = '2.0.2'");
        }
        $fx->installShims();
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php'; // finding 6: one level, not two
        $token = str_repeat('a', 64);
        file_put_contents($this->root . '/src/export-token.php', "<?php return '" . hash('sha256', $token) . "';");
        return \EfictionExporter::runner($this->root . '/src', 'fxs_', $token, true, true, false, true);
    }

    private function bundlePath(string $variant = ''): string
    {
        $this->runExport($variant);
        foreach (glob($this->root . '/src/out-*/kiption-export.tar.gz') as $p) return $p;
        $this->fail('bundle not built');
    }

    public function test_reader_round_trips_rows_and_manifest(): void
    {
        $reader = new BundleReader($this->bundlePath());
        $this->assertSame('files', $reader->manifest()['settings']['store']);
        $this->assertSame(3, $reader->manifest()['counts']['fanfiction_authors']);
        // seed every manifest table at 0: the exporter records zero counts for
        // empty tables, so the accumulator must cover the full table set
        $tables = array_fill_keys(array_keys($reader->manifest()['counts']), 0);
        $authors = [];
        foreach ($reader->rows() as $entry) {
            $tables[$entry['table']] = ($tables[$entry['table']] ?? 0) + 1;
            if ($entry['table'] === 'fanfiction_authors') $authors[] = $entry['row'];
        }
        $this->assertSame($reader->manifest()['counts'], $tables);
        $this->assertSame('legacy author ', $authors[1]['penname'], 'bytes verbatim incl. trailing space');
    }

    public function test_manifest_records_source_version_and_hash_census(): void
    {
        $m = (new BundleReader($this->bundlePath()))->manifest();
        $this->assertArrayHasKey('source_version', $m);           // from the fixture's settings row
        $this->assertSame('3.5.5', $m['source_version']);
        $this->assertSame(2, $m['password_formats']['md5']);     // fixture uids 1+2
        $this->assertSame(1, $m['password_formats']['bcrypt']);  // fixture uid 7
        $this->assertSame(0, $m['password_formats']['other']);
    }

    public function test_legacy_3x_schema_gaps_are_recorded(): void
    {
        $m = (new BundleReader($this->bundlePath('legacy3x')))->manifest();
        $this->assertContains('comments', $m['schema_gaps']['fanfiction_news'] ?? []);
        $this->assertContains('numstories', $m['schema_gaps']['fanfiction_series'] ?? []);
        $this->assertContains('link_key', $m['schema_gaps']['fanfiction_pagelinks'] ?? []);
        $this->assertSame([], $m['schema_gaps']['fanfiction_authors'] ?? [], 'a 3.0-era authors table has no gaps');
        $this->assertSame('3.0', $m['source_version']);
    }

    public function test_pre_3x_database_is_refused_with_runbook(): void
    {
        // settings.version '2.0.2' and NO fanfiction_coauthors table
        [$html, $status] = $this->runExport('pre30');
        $this->assertStringContainsString('upgrade11.php', $html);   // names the runbook
        $this->assertStringContainsString('upgrade20.php', $html);
        $this->assertStringContainsString('cloud.kiption.com', $html);
        $this->assertSame([], glob($this->root . '/src/out-*/kiption-export.tar.gz'), 'no bundle written');
    }

    public function test_future_official_release_still_imports(): void
    {
        // "3.5.8-style" source: newer version string + an extra unknown column
        // on fanfiction_stories standing in for whatever a newer official
        // release adds. No upper version gate exists.
        $reader = new BundleReader($this->bundlePath('future'));
        $m = $reader->manifest();
        $this->assertSame('3.5.8', $m['source_version']);
        $this->assertSame([], $m['schema_gaps'], 'extra unknown columns are not gaps');
        // extra unknown columns ride through SELECT * into the JSON harmlessly
        $sawFutureCol = false;
        foreach ($reader->rows() as $e) {
            if ($e['table'] === 'fanfiction_stories' && array_key_exists('future_col', $e['row'])) $sawFutureCol = true;
        }
        $this->assertTrue($sawFutureCol, 'the unknown column rode through verbatim');
    }

    public function test_reader_exposes_story_files(): void
    {
        $reader = new BundleReader($this->bundlePath());
        $this->assertSame('The stored <b>chapter</b> text.', $reader->storyFile(1, 10));
        $this->assertNull($reader->storyFile(1, 999));
    }

    public function test_reader_rejects_non_bundle(): void
    {
        $junk = $this->root . '/junk.tar.gz';
        file_put_contents($junk, 'not a tar');
        $this->expectException(\RuntimeException::class);
        new BundleReader($junk);
    }

    public function test_reader_rejects_nonexistent_bundle_path(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bundle not found');
        new BundleReader($this->root . '/nope.tar.gz');
    }

    public function test_reader_rejects_missing_parts_without_leaking_the_temp_dir(): void
    {
        $stage = $this->root . '/stage';
        mkdir($stage, 0775, true);
        file_put_contents($stage . '/junk.txt', 'hi');
        $tarPath = $this->root . '/partial.tar';
        $tar = new \PharData($tarPath);
        $tar->buildFromDirectory($stage);
        unset($tar);
        $bundle = $this->root . '/partial.tar.gz';
        file_put_contents($bundle, gzencode((string) file_get_contents($tarPath), 9));
        unlink($tarPath);
        $before = glob(sys_get_temp_dir() . '/kiption-read-*');
        try {
            new BundleReader($bundle);
            $this->fail('expected RuntimeException for a bundle without manifest/jsonl');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('manifest.json', $e->getMessage());
        }
        // PHP never runs the destructor when the constructor throws, so the
        // missing-parts path itself must clean the extracted temp dir
        $this->assertSame($before, glob(sys_get_temp_dir() . '/kiption-read-*'));
    }

    public function test_rows_reject_corrupt_archive_lines_in_a_controlled_way(): void
    {
        $stage = $this->root . '/stage2';
        mkdir($stage, 0775, true);
        file_put_contents($stage . '/manifest.json', '{"counts":{}}');
        file_put_contents($stage . '/archive.jsonl.gz', gzencode("{\"_table\":\"ok\",\"a\":1}\nnot json\n"));
        $tarPath = $this->root . '/bad.tar';
        $tar = new \PharData($tarPath);
        $tar->buildFromDirectory($stage);
        unset($tar);
        $bundle = $this->root . '/bad.tar.gz';
        file_put_contents($bundle, gzencode((string) file_get_contents($tarPath), 9));
        unlink($tarPath);
        $reader = new BundleReader($bundle);
        $seen = [];
        try {
            foreach ($reader->rows() as $entry) $seen[] = $entry['table'];
            $this->fail('expected RuntimeException on the corrupt line');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('corrupt', $e->getMessage());
        }
        $this->assertSame(['ok'], $seen, 'rows before the corrupt line were yielded');
    }
}
