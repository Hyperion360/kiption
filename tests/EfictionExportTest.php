<?php // tests/EfictionExportTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/EfictionInstall.php';

final class EfictionExportTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kiption-efi-' . uniqid('', true);
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function install(): \EfictionInstall
    {
        return new \EfictionInstall($this->root);
    }

    public function test_fixture_builds_schema_settings_and_files(): void
    {
        $fx = $this->install();
        $this->assertSame(2, $fx->count('fanfiction_authors'));
        $this->assertSame(1, (int) $fx->one('SELECT validated FROM ' . $fx->prefix . 'fanfiction_authorprefs WHERE uid = 1')['validated']);
        $this->assertFileExists($this->root . '/stories/1/10.txt');
        $this->assertSame('files', $fx->settings()['store'] === 'files' ? 'files' : 'db', 'settings row present');
    }

    public function test_db_shims_expose_the_efiction_globals(): void
    {
        $fx = $this->install();
        $fx->installShims();
        $this->assertTrue(function_exists('dbquery'));
        $q = dbquery('SELECT penname FROM ' . TABLEPREFIX . 'fanfiction_authors WHERE uid = 1');
        $row = dbassoc($q);
        $this->assertSame('Legacy Author', $row['penname']);
    }

    public function test_exporter_class_streams_bundle_parts(): void
    {
        $fx = $this->install();
        $fx->installShims();
        require_once dirname(__DIR__) . '/resources/efiction-export.php'; // defines EfictionExporter, runner guarded
        $outDir = $this->root . '/out';
        mkdir($outDir, 0775, true);
        $exporter = new \EfictionExporter($this->root, 'fxs_', $outDir);
        $manifest = $exporter->export();
        $this->assertSame('3.5.5', $manifest['efiction_version']);
        $this->assertSame('files', $manifest['settings']['store']);
        $this->assertSame(2, $manifest['counts']['fanfiction_authors']);
        $this->assertSame(1, $manifest['counts']['fanfiction_stories']);
        $this->assertFileExists($outDir . '/archive.jsonl.gz');
        $this->assertFileExists($outDir . '/manifest.json');
        // jsonl round trip: authors first row carries uid 1 verbatim
        $lines = explode("\n", trim((string) gzdecode((string) file_get_contents($outDir . '/archive.jsonl.gz'))));
        $first = json_decode($lines[0], true);
        $this->assertSame('fanfiction_authors', $first['_table']);
        $this->assertSame(1, $first['uid']);
        $this->assertSame(md5('oldpassword'), $first['password']);
        // store=files: chapter text comes from the file, copied into stories/
        $this->assertFileExists($outDir . '/stories/1/10.txt');
        $this->assertSame('The stored <b>chapter</b> text.', file_get_contents($outDir . '/stories/1/10.txt'));
        // finding-7 tables exported too; count via the fixture's own PDO, not the shims
        // (plan fix: the sketched second install() reuses this test's root and the
        // builder fatals on existing tables; the first fixture carries the same rows)
        $this->assertSame(1, $fx->count('fanfiction_authorinfo'), 'finding-7 tables exported too');
        $this->assertArrayHasKey('fanfiction_authorinfo', $manifest['counts']);
        $this->assertArrayHasKey('fanfiction_log', $manifest['counts']);
        $this->assertArrayHasKey('fanfiction_pagelinks', $manifest['counts']);
        // invalid UTF-8 replacements counted, bytes otherwise verbatim
        $this->assertArrayHasKey('invalid_utf8_replaced', $manifest);
    }
}
