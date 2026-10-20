<?php // tests/EpubImportTest.php
namespace App\Tests;
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
}
