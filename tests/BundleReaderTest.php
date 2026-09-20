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
        require_once dirname(__DIR__) . '/tests/support/EfictionInstall.php';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function bundlePath(): string
    {
        mkdir($this->root . '/src', 0775, true); // fixture requires its root to exist
        $fx = new \EfictionInstall($this->root . '/src');
        $fx->installShims();
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php'; // finding 6: one level, not two
        $token = str_repeat('a', 64);
        file_put_contents($this->root . '/src/export-token.php', "<?php return '" . hash('sha256', $token) . "';");
        [$html] = \EfictionExporter::runner($this->root . '/src', 'fxs_', $token, true, true, false, true);
        foreach (glob($this->root . '/src/out-*/kiption-export.tar.gz') as $p) return $p;
        $this->fail('bundle not built: ' . $html);
    }

    public function test_reader_round_trips_rows_and_manifest(): void
    {
        $reader = new BundleReader($this->bundlePath());
        $this->assertSame('files', $reader->manifest()['settings']['store']);
        $this->assertSame(2, $reader->manifest()['counts']['fanfiction_authors']);
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
}
