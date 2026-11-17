<?php // tests/StorageQuotaTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\StorageQuota;
use Kip\Database;
use Kip\Storage;
use PHPUnit\Framework\TestCase;

/** The upload-bytes quota (config 'storage_cap_bytes', 0 = off). One counter
 *  row in data.sqlite, seeded lazily from a walk of the uploads directory,
 *  reserved atomically before every put, released on failure and delete,
 *  drift-corrected on refusal. Nothing here ever deletes a file: the cap
 *  blocks NEW uploads only (the unlink inside removeFile retires a file the
 *  call site already decided to retire). */
#[Medium]
final class StorageQuotaTest extends TestCase
{
    private string $path = '';
    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-sq-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) exec('rm -rf ' . escapeshellarg($root));
        $this->roots = [];
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** A temp uploads directory with the given name => byte-count files, and
     *  a migrated test database; returns the quota over the pair. */
    private function quota(int $cap, array $files = [], bool $migrated = true, ?string $dbPath = null, bool $mkdirUploads = true): StorageQuota
    {
        $root = sys_get_temp_dir() . '/kiption-sq-' . uniqid('', true);
        if ($mkdirUploads) mkdir($root . '/uploads', 0775, true);
        $this->roots[] = $root;
        foreach ($files as $name => $bytes) file_put_contents($root . '/uploads/' . $name, str_repeat('x', (int) $bytes));
        $db = new Database('sqlite:' . ($dbPath ?? $this->path));
        if ($migrated) (new \Kip\Migrations\Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        return new StorageQuota($db, $root . '/uploads', $cap);
    }

    /** Sets the counter row directly (the drift tests' seam). */
    private function setCounter(StorageQuota $q, int $used, int $reconciledAt): void
    {
        $q->reconcile(); // ensures the table and row exist
        $q->db()->query('UPDATE storage_usage SET used = ?, reconciled_at = ? WHERE id = 1', [$used, $reconciledAt]);
    }

    public function test_the_first_reserve_seeds_the_counter_from_the_directory(): void
    {
        $q = $this->quota(cap: 1000, files: ['a.png' => 600]);
        $this->assertSame(600, $q->used());
        $this->assertTrue($q->reserve(400, admin: true));
        $this->assertFalse($q->reserve(1, admin: true)); // exactly full, one byte over refused
    }

    public function test_a_seeded_counter_reads_in_one_query_without_reseeding(): void
    {
        $q = $this->quota(cap: 1000, files: ['a.png' => 100]);
        $this->assertSame(100, $q->used());
        $queries = [];
        $q->db()->onQuery(static function (string $sql) use (&$queries): void { $queries[] = $sql; });
        try {
            $this->assertSame(100, $q->used());
            $this->assertCount(1, $queries, 'the capped image page needs one counter read');
            $this->assertTrue($q->reserve(50, admin: true));
            $q->release(50);
        } finally {
            $q->db()->onQuery(static function (): void {});
        }
        $this->assertSame([], array_values(array_filter($queries,
            static fn (string $sql): bool => str_starts_with($sql, 'CREATE TABLE') || str_starts_with($sql, 'INSERT OR IGNORE'))),
            'steady-state quota calls must not recreate or reseed the counter');
    }

    public function test_human_quota_labels_keep_significant_zeroes(): void
    {
        $this->assertSame('10 MB', StorageQuota::human(10 * 1024 ** 2));
        $this->assertSame('20 MB', StorageQuota::human(20 * 1024 ** 2));
        $this->assertSame('10 GB', StorageQuota::human(10 * 1024 ** 3));
    }

    public function test_the_reserve_statement_casts_its_text_bound_values(): void
    {
        // Kip's Database binds every statement parameter as TEXT, and SQLite
        // sorts any INTEGER below any TEXT, so the uncast statement
        // (`used + ? <= ?`) is always true and the cap is a total bypass.
        $q = $this->quota(cap: 1000, files: ['a.png' => 900]);
        $this->assertFalse($q->reserve(500, admin: true)); // 900 + 500 over the cap: refused
        $this->assertTrue($q->reserve(100, admin: true));  // exactly the cap: admitted
    }

    public function test_a_member_upload_stops_five_percent_short(): void
    {
        $q = $this->quota(cap: 1000, files: ['a.png' => 950]);
        $this->assertFalse($q->reserve(1, admin: false), '951 is over the member limit of 950');
        $this->assertTrue($q->reserve(50, admin: true), 'the admin reserve admits up to the full cap');
        $this->assertSame(1000, $q->used());
    }

    public function test_a_negative_reserve_cannot_deflate_the_counter(): void
    {
        // The mirror of release()'s bytes <= 0 guard: a negative claim is a
        // no-op admission, never a quiet way to grow the room (no current
        // caller can produce one; the class refuses it on principle).
        $q = $this->quota(cap: 1000, files: ['a.png' => 500]);
        $this->assertTrue($q->reserve(-400, admin: false), 'nothing to claim, nothing refused');
        $this->assertSame(500, $q->used(), 'the counter did not move');
        $this->assertFalse($q->reserve(501, admin: false), 'the room did not grow either');
    }

    public function test_a_missing_table_is_created_on_first_use(): void
    {
        // A request that lands between the code rsync and the migrate step of
        // a deploy finds no storage_usage table yet: create it, seed it, and
        // admit, instead of erroring that request.
        $q = $this->quota(cap: 1000, files: ['a.png' => 100], migrated: false);
        $this->assertTrue($q->reserve(50, admin: true));
        $this->assertSame(150, $q->used());
    }

    public function test_a_wiped_cache_database_does_not_reset_the_counter(): void
    {
        $q = $this->quota(cap: 1000, files: ['a.png' => 600]);
        $q->reserve(100, admin: true);
        // What kip cache:clear does to the CACHE database (a different file
        // entirely); the counter lives in data.sqlite and must not notice.
        $cache = $this->path . '-cache.sqlite';
        file_put_contents($cache, 'junk');
        @unlink($cache);
        $this->assertSame(700, $this->quota(cap: 1000)->used());
    }

    public function test_a_cap_of_zero_is_off_and_never_blocks(): void
    {
        $q = $this->quota(cap: 0, files: ['a.png' => 10]);
        $this->assertTrue($q->reserve(PHP_INT_MAX, admin: false));
        $this->assertSame(0, (int) $q->db()->one('SELECT COUNT(*) c FROM storage_usage')['c'], 'no table row written');
    }

    public function test_a_missing_uploads_dir_counts_zero(): void
    {
        $q = $this->quota(cap: 1000, mkdirUploads: false);
        $this->assertSame(0, $q->used());
        $this->assertTrue($q->reserve(100, admin: true));
    }

    public function test_a_failed_put_releases_its_reservation(): void
    {
        $q = $this->quota(cap: 10000, files: []);
        $this->assertTrue($q->reserve(300, admin: true));
        $q->release(300);
        $this->assertSame(0, $q->used());
        $q->release(999999);
        $this->assertSame(0, $q->used(), 'release never goes below zero');
    }

    public function test_a_delete_subtracts(): void
    {
        $q = $this->quota(cap: 1000, files: ['a.png' => 600]);
        $q->release(600);
        $this->assertSame(0, $q->used());
    }

    public function test_the_read_only_preview_answers_without_walking_or_writing(): void
    {
        // allows() is the no-write mirror of reserve(): same member/admin
        // arithmetic, no counter mutation, no reconciliation pass.
        $q = $this->quota(cap: 1000, files: ['a.png' => 900]);
        $before = $q->used();
        $this->assertTrue($q->allows(100, admin: true), 'exactly the cap fits');
        $this->assertFalse($q->allows(101, admin: true), 'one over is refused');
        $this->assertTrue($q->allows(50, admin: false), 'the member limit is 950');
        $this->assertFalse($q->allows(51, admin: false), 'over the member limit is refused');
        $this->assertSame($before, $q->used(), 'the preview never writes the counter');
    }

    public function test_a_stale_counter_is_corrected_on_refusal(): void
    {
        $q = $this->quota(cap: 1000, files: ['a.png' => 100]);
        $this->setCounter($q, 900, time() - 3600); // drifted high: orphans were removed by hand
        $this->assertTrue($q->reserve(500, admin: true), 'refused first, reconciled to 100, retried and admitted');
        $this->assertSame(600, $q->used());
    }

    public function test_a_fresh_counter_is_not_walked_again_on_every_refusal(): void
    {
        $q = $this->quota(cap: 1000, files: ['a.png' => 100]);
        $this->setCounter($q, 900, time()); // reconciled right now
        $this->assertFalse($q->reserve(500, admin: true), 'the refusal stays a refusal');
        $this->assertSame(900, $q->used(), 'no directory walk happened');
    }

    public function test_reserve_uses_the_real_file_size_not_the_client_size(): void
    {
        $q = $this->quota(cap: 10000, files: []);
        $storage = new Storage($q->dir(), 2097152, ['txt'], mover: fn (string $t, string $d): bool => copy($t, $d) && unlink($t));
        $tmp = tempnam(sys_get_temp_dir(), 'sq') . '.txt';
        file_put_contents($tmp, str_repeat('x', 500));
        $path = $q->putUnderCap(['name' => 'x.txt', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 1], true, $storage);
        $this->assertNotSame('', $path);
        $this->assertSame(500, $q->used(), 'the tmp file on disk is the truth, the client size is metadata');
    }

    public function test_a_put_that_throws_releases_the_reservation(): void
    {
        $q = $this->quota(cap: 10000, files: []);
        $storage = new Storage($q->dir(), 10, ['txt'], mover: fn (string $t, string $d): bool => copy($t, $d) && unlink($t));
        $tmp = tempnam(sys_get_temp_dir(), 'sq') . '.txt';
        file_put_contents($tmp, str_repeat('x', 500));
        try {
            $q->putUnderCap(['name' => 'x.txt', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 500], true, $storage);
            $this->fail('expected the UploadException to rethrow');
        } catch (\Kip\UploadException) {
            $this->assertSame(0, $q->used(), 'the failed put gave its reservation back');
        }
    }

    public function test_a_refusal_writes_at_most_one_activity_row_per_hour(): void
    {
        $q = $this->quota(cap: 100, files: ['a.png' => 100]);
        $tmp = tempnam(sys_get_temp_dir(), 'sq') . '.txt';
        file_put_contents($tmp, 'hello');
        $this->assertNull($q->putUnderCap(['name' => 'x.txt', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 5], true,
            new Storage($q->dir(), 2097152, ['txt'], mover: fn (): bool => true)));
        $this->assertNull($q->putUnderCap(['name' => 'y.txt', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 5], true,
            new Storage($q->dir(), 2097152, ['txt'], mover: fn (): bool => true)));
        $rows = $q->db()->all("SELECT actor_id, detail FROM activity_log WHERE action = 'storage.refused'");
        $this->assertCount(1, $rows, 'recordOnce, one per hour: a bot cannot flood the log');
        $this->assertNull($rows[0]['actor_id']);
        $this->assertStringContainsString('storage limit', $rows[0]['detail']);
    }

    public function test_remove_file_releases_and_unlinks_when_nothing_uses_it(): void
    {
        $q = $this->quota(cap: 1000, files: ['a.png' => 600]);
        $this->assertTrue($q->removeFile('a.png'));
        $this->assertSame(0, $q->used());
        $this->assertFileDoesNotExist($q->dir() . '/a.png');
    }

    public function test_remove_file_keeps_a_file_still_referenced(): void
    {
        $q = $this->quota(cap: 1000, files: ['a.png' => 600]);
        $q->db()->query("INSERT INTO users (email, password_hash, penname, avatar_path) VALUES ('ref@e.test', 'x', 'ref', '/uploads/a.png')");
        $this->assertFalse($q->removeFile('a.png'), 'an avatar still points at it');
        $this->assertSame(600, $q->used(), 'the bytes are not released');
        $this->assertFileExists($q->dir() . '/a.png');
    }

    public function test_interleaved_reservations_never_admit_more_than_the_cap(): void
    {
        // Two StorageQuota objects on one database file, alternating. This
        // proves the single statement is exact; it is not a race test.
        $root = sys_get_temp_dir() . '/kiption-sq-' . uniqid('', true);
        mkdir($root, 0775, true);
        $this->roots[] = $root;
        $dbPath = $root . '/shared.sqlite';
        $a = $this->quota(cap: 1000, files: [], dbPath: $dbPath);
        $b = $this->quota(cap: 1000, files: [], dbPath: $dbPath);
        $admitted = 0;
        foreach ([$a, $b, $a, $b] as $q) {
            if ($q->reserve(300, admin: true)) $admitted++;
        }
        $this->assertSame(3, $admitted, '900 in, the fourth 300 would pass the cap');
    }

    /** Task C3's guard: the quota class never deletes on its own. removeFile
     *  exists for call sites that already decided to retire a file; the class
     *  must hold no other unlink. */
    public function test_the_quota_class_has_no_hidden_delete(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/app/src/StorageQuota.php');
        $this->assertStringNotContainsString('rmdir(', $src);
        $this->assertSame(1, substr_count($src, 'unlink('), 'only removeFile unlinks, and only on request');
    }
}
