<?php // tests/EpubImportTest.php
namespace App\Tests;
use App\Import\Epub;
use Kip\Database;
use PHPUnit\Framework\TestCase;

/** Harness for the EPUB bulk importer: a fully migrated and seeded temp
 *  database plus throwaway uploads and fixtures directories, removed
 *  recursively on teardown. The later pipeline tasks build their tests on
 *  this scaffold. */
final class EpubImportTest extends TestCase
{
    private string $path = '';
    private string $uploads = '';
    private string $fixtures = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-epub-db-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->uploads = $this->tempDir('kiption-epub-upl-');
        $this->fixtures = $this->tempDir('kiption-epub-fx-');
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->uploads);
        $this->rmDir($this->fixtures);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -strlen('.sqlite'))); // the zero-byte tempnam stub itself
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . uniqid('', true);
        mkdir($dir, 0775, true);
        return $dir;
    }

    /** Pure-PHP recursive removal, the DoctorTest idiom. */
    private function rmDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) return;
        $ri = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $f) { $f->isDir() ? @rmdir((string) $f) : @unlink((string) $f); }
        @rmdir($dir);
    }

    public function test_epub_imports_ledger_exists_with_exactly_the_ledger_columns(): void
    {
        $cols = array_map(static fn(array $c): string => $c['name'], $this->db->all('PRAGMA table_info(epub_imports)'));
        $this->assertSame(['sha256', 'story_id', 'imported_at'], $cols, 'the epub_imports ledger carries exactly the three ledger columns');
    }

    // ------------------------------------------------------------------
    // Task 2: bounded reading. The fixtures build real zips on the fly and
    // always hand back the ABSOLUTE path; every inspect() call passes that
    // return value, never a bare filename.
    // ------------------------------------------------------------------

    /** Builds a real zip at fixtures/<name> via ZipArchive and returns its
     *  absolute path. $entries maps archive entry names to string contents;
     *  $opts['files'] (entry name => disk path) adds entries from files on
     *  disk, which is how the sparse-file bomb fixtures reach the zip. */
    private function makeEpub(string $name, array $entries, array $opts = []): string
    {
        $path = $this->fixtures . '/' . $name;
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail("fixture zip {$name} failed to open for writing");
        }
        foreach ($entries as $entryName => $content) {
            $zip->addFromString((string) $entryName, (string) $content);
        }
        foreach ($opts['files'] ?? [] as $entryName => $diskPath) {
            $zip->addFile((string) $diskPath, (string) $entryName);
        }
        if (!$zip->close()) {
            $this->fail("fixture zip {$name} failed to close");
        }
        return $path;
    }

    /** A sparse file of exactly $bytes: ftruncate past the end leaves a hole
     *  that allocates no blocks, so a 16 MiB declared bomb costs kilobytes of
     *  real disk and compresses to almost nothing inside the fixture. */
    private function bigFileOnDisk(int $bytes): string
    {
        $path = tempnam($this->fixtures, 'big-');
        $f = fopen($path, 'rb+');
        if ($f === false || ftruncate($f, $bytes) === false) {
            $this->fail("sparse fixture failed to build at {$bytes} bytes");
        }
        fclose($f);
        return $path;
    }

    public function test_a_legit_zip_passes_the_bounds_and_stops_at_the_missing_container(): void
    {
        $epub = $this->makeEpub('clean.epub', [
            'mimetype' => 'application/epub+zip',
            'OEBPS/content.opf' => '<?xml version="1.0"?><package/>',
        ]);
        // no META-INF/container.xml: the bounds pass, the container gate
        // refuses; the structure pass itself is Task 3's work
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not an EPUB/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_an_entry_declaring_over_16_mib_is_refused_before_any_payload_read(): void
    {
        $big = $this->bigFileOnDisk(16 * 1024 * 1024 + 1);
        $epub = $this->makeEpub('declared-file-bomb.epub', ['mimetype' => 'application/epub+zip'],
            ['files' => ['OEBPS/big.bin' => $big]]);
        $before = scandir($this->fixtures);
        try {
            (new Epub($this->db, $this->uploads))->inspect($epub);
            $this->fail('an entry declaring over 16 MiB must be refused');
        } catch (\RuntimeException $ex) {
            $this->assertMatchesRegularExpression('/16 MiB/', $ex->getMessage());
        }
        // the probe: the refusal came from the central directory alone, no
        // payload read ever ran, so the fixtures dir holds exactly what it
        // held before (no extracted files next to the fixture)
        $this->assertSame($before, scandir($this->fixtures), 'no payload read: the fixtures dir gained no extracted files');
    }

    public function test_a_zip_with_5000_entries_is_refused_at_the_4096_member_cap(): void
    {
        $entries = [];
        for ($i = 0; $i < 5000; $i++) { $entries['x' . $i . '.txt'] = 'x'; }
        $epub = $this->makeEpub('member-bomb.epub', $entries);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/4096/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_declared_sizes_summing_over_64_mib_are_refused(): void
    {
        // five entries at 15 MiB each: every entry sits under the per-entry
        // cap while the declared sum reaches 75 MiB, over the archive cap
        $big = $this->bigFileOnDisk(15 * 1024 * 1024);
        $epub = $this->makeEpub('declared-total-bomb.epub', ['mimetype' => 'application/epub+zip'],
            ['files' => [
                'OEBPS/a.bin' => $big, 'OEBPS/b.bin' => $big, 'OEBPS/c.bin' => $big,
                'OEBPS/d.bin' => $big, 'OEBPS/e.bin' => $big,
            ]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/64 MiB/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }
}
