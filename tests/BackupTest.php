<?php // tests/BackupTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** kip backup through CliTest's subprocess idiom: KIP_DB_DSN backs up a
 *  migrated+seeded temp database and KIP_BACKUP_DIR redirects the archive to
 *  a throwaway directory, so tests never write the repo's app/backups. */
final class BackupTest extends TestCase
{
    private string $path = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-backup-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-backup-' . uniqid('', true);
        mkdir($this->root . '/backups', 0775, true);
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($db);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    /** @return array{0: int, 1: string} exit code, stdout+stderr */
    private function kip(string $args): array
    {
        $cmd = sprintf('KIP_DB_DSN=%s KIP_BACKUP_DIR=%s %s %s %s 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg($this->root . '/backups'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'),
            $args);
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    private function db(): Database
    {
        return new Database('sqlite:' . $this->path);
    }

    public function test_backup_archives_the_data_db_and_leaves_the_source_working(): void
    {
        $users = (int) $this->db()->one('SELECT COUNT(*) c FROM users')['c'];
        [$code, $out] = $this->kip('backup');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Backup written:', $out);
        $zips = glob($this->root . '/backups/kip-backup-*.zip') ?: [];
        $this->assertCount(1, $zips, 'KIP_BACKUP_DIR redirected the archive into the temp dir');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zips[0]), 'the archive is a valid zip');
        $this->assertNotFalse($zip->locateName('data.sqlite'), 'the zip carries the data database entry');
        $zip->close();
        // the source DB still queries (and holds its rows) after the snapshot
        $this->assertSame($users, (int) $this->db()->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertGreaterThan(0, (int) $this->db()->one('SELECT COUNT(*) c FROM stories')['c']);

        // Finding 9: the stamp has 1-second resolution, so back-to-back runs
        // overwrite the SAME zip; only a run a full second later archives
        // separately. keepDays pruning is deliberately NOT asserted here:
        // keepDays=0 prunes by file mtime versus now, and a just-written
        // archive's mtime IS now, so the comparison races (probed) and can
        // delete the fresh archive; only the two-archive behavior is pinned.
        sleep(1);
        [$code2, $out2] = $this->kip('backup');
        $this->assertSame(0, $code2, $out2);
        $this->assertCount(2, glob($this->root . '/backups/kip-backup-*.zip') ?: [],
            'a second run a second apart creates a second archive');
    }
}
