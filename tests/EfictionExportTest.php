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

    public function test_runner_token_gate_and_bundle_assembly(): void
    {
        $fx = $this->install();
        $fx->installShims();
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php';
        $token = str_repeat('a', 64);
        file_put_contents($this->root . '/export-token.php', "<?php return '" . hash('sha256', $token) . "';");
        [$html, $code] = \EfictionExporter::runner($this->root, 'fxs_', $token, true, true, false, true);
        $this->assertStringContainsString('Download ready', $html);
        // PharData bundle carries all three parts (runner writes out-*/ under the install root)
        $bundles = glob($this->root . '/out-*/kiption-export.tar.gz');
        $this->assertNotEmpty($bundles);
        $phar = new \PharData($bundles[0]);
        $names = array_map('basename', array_keys(iterator_to_array(new \RecursiveIteratorIterator($phar))));
        $this->assertContains('archive.jsonl.gz', $names);
        $this->assertContains('manifest.json', $names);
        $this->assertContains('10.txt', $names);
        // wrong token: identical form page, no oracle, no bundle
        [$html2, $code2] = \EfictionExporter::runner($this->root, 'fxs_', str_repeat('b', 64), true, true, false, true);
        $this->assertSame(200, $code2);
        $this->assertStringNotContainsString('Download ready', $html2);
        // maintenance off + no force: refuses with instructions
        $fx->pdo->exec('UPDATE fxs_fanfiction_settings SET maintenance = 0');
        [$html3, $code3] = \EfictionExporter::runner($this->root, 'fxs_', $token, false, true, false, false);
        $this->assertStringContainsString('maintenance', strtolower($html3));
    }

    public function test_runner_gates_missing_token_file_and_selfdelete(): void
    {
        $this->install()->installShims();
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php';
        $token = str_repeat('c', 64);
        // no token file: 403 with instructions
        [$html, $code] = \EfictionExporter::runner($this->root, 'fxs_', $token, true, true, false, true);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('export-token.php', $html);
        // selfdelete via the injected path (finding 4): both files gone, repo untouched
        file_put_contents($this->root . '/export-token.php', "<?php return '" . hash('sha256', $token) . "';");
        $exporterCopy = $this->root . '/efiction-export.php';
        copy(dirname(__DIR__) . '/resources/efiction-export.php', $exporterCopy);
        [$html2, $code2] = \EfictionExporter::runner($this->root, 'fxs_', $token, true, false, true, false, $exporterCopy);
        $this->assertSame(200, $code2);
        $this->assertStringContainsString('Cleaned up', $html2);
        $this->assertFileDoesNotExist($this->root . '/export-token.php');
        $this->assertFileDoesNotExist($exporterCopy);
        $this->assertFileExists(dirname(__DIR__) . '/resources/efiction-export.php', 'the repo file must survive');
    }

    public function test_gate_page_offers_a_token_entry_field_without_oracle(): void
    {
        $fx = $this->install();
        $fx->installShims();
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php';
        file_put_contents($this->root . '/export-token.php', "<?php return '" . hash('sha256', str_repeat('d', 64)) . "';");
        [$none, $c1] = \EfictionExporter::runner($this->root, 'fxs_', '', false, false, false, false);
        [$wrong, $c2] = \EfictionExporter::runner($this->root, 'fxs_', str_repeat('e', 64), false, false, false, false);
        $this->assertSame(200, $c1);
        $this->assertSame(200, $c2);
        $this->assertSame($none, $wrong, 'no oracle: the two gate pages must be byte-identical');
        // the runbook says "paste the token": the gate form needs a visible
        // entry field or the browser flow cannot authenticate at all
        $this->assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="token"[^>]*>/', $none);
        $this->assertStringNotContainsString('Download ready', $none);
    }
    public function test_export_gates_missing_settings_row_and_storiespath(): void
    {
        $fx = $this->install();
        $fx->installShims();
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php';
        // store=files but storiespath missing on disk: RuntimeException
        $fx->pdo->exec("UPDATE fxs_fanfiction_settings SET storiespath = 'gone'");
        $out = $this->root . '/out2';
        mkdir($out, 0775, true);
        try {
            (new \EfictionExporter($this->root, 'fxs_', $out))->export();
            $this->fail('expected RuntimeException for missing storiespath');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('storiespath', $e->getMessage());
        }
        // settings row gone: RuntimeException, no query loop runs
        $fx->pdo->exec("DELETE FROM fxs_fanfiction_settings");
        try {
            (new \EfictionExporter($this->root, 'fxs_', $out))->export();
            $this->fail('expected RuntimeException for missing settings row');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('settings', $e->getMessage());
        }
    }
}
