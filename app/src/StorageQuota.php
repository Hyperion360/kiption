<?php // app/src/StorageQuota.php
namespace App;

use Kip\Database;
use Kip\Storage;

/** The upload-bytes quota (config 'storage_cap_bytes', 0 = off, the default:
 *  a hosting operator may cap total upload bytes). What counts is the bytes
 *  under the uploads directory and nothing else: text growth (stories,
 *  chapters, messages) is never counted and never blocked.
 *
 *  One counter row in data.sqlite (NOT cache.sqlite: `kip cache:clear` wipes
 *  the cache database on every deploy, and a counter that vanishes is not a
 *  counter), seeded lazily from a walk of the uploads directory, kept exact
 *  by one atomic reserve statement and by releases on every delete, and
 *  drift-corrected at the moment a refusal would otherwise lie. Nothing in
 *  this class deletes a file on its own: the cap blocks NEW uploads only. */
final class StorageQuota
{
    /** The share of the cap usable by member uploads (avatars, covers); the
     *  last slice is the owner's, so the owner can always upload and delete. */
    public const MEMBER_SHARE_PERCENT = 95;

    /** The counter stops trusting its walk after this long; a refused
     *  reservation past the horizon reconciles once and retries. */
    private const RECONCILE_AFTER_SECONDS = 600;

    public function __construct(private Database $db, private string $uploadsDir, private int $cap) {}

    /** The quota for a booted app: the container's database, the configured
     *  uploads dir, the configured cap (0 = off). Controllers call this; the
     *  CLI builds its own (no container there). */
    public static function forApp(\Kip\App $app): self
    {
        $dir = rtrim((string) ($app->config('uploads')['dir'] ?? dirname(__DIR__, 2) . '/public/uploads'), '/');
        return new self($app->container->make(Database::class), $dir, max(0, (int) $app->config('storage_cap_bytes', 0)));
    }

    /** Test and operator seams: the counter's database and the measured dir. */
    public function db(): Database { return $this->db; }
    public function dir(): string { return $this->uploadsDir; }
    public function cap(): int { return $this->cap; }

    /** Bytes under the uploads directory as the counter sees it, seeding the
     *  row from a real walk on first use. A missing or unreadable uploads
     *  directory counts as 0. */
    public function used(): int
    {
        $this->seed();
        $row = $this->db->one('SELECT used FROM storage_usage WHERE id = 1');
        return (int) ($row['used'] ?? 0);
    }

    /** Claim $bytes before a put. One atomic UPDATE decides: the CASTs pin
     *  the numeric compare (PDO binds every parameter as TEXT, and a bare
     *  `used + ? <= ?` would order by SQLite's type rules, any INTEGER sorts
     *  below any TEXT, the comparison always true, the cap a total bypass).
     *  $admin uploads may use the whole cap; member uploads stop
     *  MEMBER_SHARE_PERCENT short so the owner keeps room. Cap 0 skips the
     *  table entirely and always admits. */
    public function reserve(int $bytes, bool $admin = false): bool
    {
        if ($this->cap < 1) return true;
        $bytes = max(0, $bytes); // a negative claim must never deflate the counter (the release() idiom)
        $limit = $admin ? $this->cap : intdiv($this->cap * self::MEMBER_SHARE_PERCENT, 100);
        $this->seed();
        if ($this->attempt($bytes, $limit)) return true;
        // Refused. The counter may have drifted (orphans, files removed by
        // hand, a restored database): past the horizon, correct it once from
        // a real walk and retry. The releases at every delete are the primary
        // path; this is the backstop, never a scheduled job.
        $row = $this->db->one('SELECT reconciled_at FROM storage_usage WHERE id = 1');
        if ($row !== null && (int) $row['reconciled_at'] <= ($this->now() - self::RECONCILE_AFTER_SECONDS)) {
            $this->reconcile();
            return $this->attempt($bytes, $limit);
        }
        return false;
    }

    /** Read-only preview of the same arithmetic (no walk, no write). */
    public function allows(int $bytes, bool $admin = false): bool
    {
        if ($this->cap < 1) return true;
        $limit = $admin ? $this->cap : intdiv($this->cap * self::MEMBER_SHARE_PERCENT, 100);
        return $this->used() + $bytes <= $limit;
    }

    /** Give $bytes back: a failed put after admission, an admin library
     *  delete, a replaced avatar or cover, an account delete's avatar. Never
     *  below zero, and a no-op while the cap is off. */
    public function release(int $bytes): void
    {
        if ($this->cap < 1 || $bytes <= 0) return;
        $this->seed();
        $this->db->query('UPDATE storage_usage SET used = MAX(0, used - ?) WHERE id = 1', [$bytes]);
    }

    /** Walk the uploads directory once and set the counter to what is really
     *  there. Returns the total. `kip storage:reconcile` runs this on demand;
     *  reserve() runs it on a stale refusal. */
    public function reconcile(): int
    {
        $this->seed();
        $total = self::walkBytes($this->uploadsDir);
        $this->db->query('UPDATE storage_usage SET used = ?, reconciled_at = ? WHERE id = 1', [$total, $this->now()]);
        return $total;
    }

    /** The one flow every upload site runs: reserve the REAL file size (the
     *  tmp file on disk, never the client-supplied size field), put, and
     *  release on failure. Returns the stored path, or null when the cap
     *  refused (the one-per-hour activity row is written here, so the owner
     *  sees refusals without reading the server log; a bot cannot flood it).
     *  A UploadException rethrows after its reservation is returned; the
     *  caller renders its own rejection. */
    public function putUnderCap(array $file, bool $admin, Storage $storage): ?string
    {
        $bytes = (int) (filesize((string) ($file['tmp_name'] ?? '')) ?: 0);
        if (!$this->reserve($bytes, $admin)) {
            ActivityLog::recordOnce($this->db, null, 'storage.refused',
                "upload of {$bytes} bytes refused, storage limit reached", 60);
            return null;
        }
        try {
            return $storage->put($file);
        } catch (\Kip\UploadException $e) {
            $this->release($bytes);
            throw $e;
        }
    }

    /** Retire a replaced or deleted upload: release its real size and unlink
     *  it, but only when the name is a bare filename and the reference check
     *  (avatar, story cover, series cover) says nothing else still uses it.
     *  Call sites pass basename($storedPath); anything carrying a separator
     *  is refused. Returns whether the file was removed. */
    public function removeFile(string $name): bool
    {
        if ($name === '' || strpbrk($name, "/\\") !== false || $name !== basename($name)) return false;
        if (isset($this->inUseNames()[$name])) return false;
        $target = $this->uploadsDir . '/' . $name;
        if (!is_file($target)) return false;
        $bytes = (int) @filesize($target);
        $this->release($bytes); // release before the unlink: the counter never counts a file the app is retiring
        return @unlink($target);
    }

    /** The in-use set, one statement (the image library's rule). */
    public function inUseNames(): array
    {
        return UploadRefs::names($this->db);
    }

    /** Create the table and row if absent (a request landing between the
     *  code rsync and a deploy's migrate step must not error), then walk the
     *  directory once. Two requests seeding at once is harmless: both walk,
     *  the later INSERT OR IGNORE is ignored. */
    private function seed(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS storage_usage (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            used INTEGER NOT NULL,
            reconciled_at INTEGER NOT NULL
        )');
        $this->db->query('INSERT OR IGNORE INTO storage_usage (id, used, reconciled_at) VALUES (1, ?, ?)',
            [self::walkBytes($this->uploadsDir), $this->now()]);
    }

    private function attempt(int $bytes, int $limit): bool
    {
        return $this->db->query(
            'UPDATE storage_usage SET used = used + ? WHERE id = 1 AND used + CAST(? AS INTEGER) <= CAST(? AS INTEGER)',
            [$bytes, $bytes, $limit]
        )->rowCount() === 1;
    }

    /** The uploads directory is flat (Storage's hex names, the image library
     *  globs `/*`), so the walk is one scandir. */
    private static function walkBytes(string $dir): int
    {
        $total = 0;
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $total += (int) @filesize($dir . '/' . $name);
        }
        return $total;
    }

    /** The time seam: unix seconds, read in one place. */
    private function now(): int
    {
        return time();
    }

    /** Human bytes the way the storage line shows them: GB for gibibytes and
     *  up, MB below that (the cap's own units: a "2 GB" cap is 2 GiB). */
    public static function human(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            $v = round($bytes / 1024 ** 3, 1);
            return rtrim(rtrim((string) $v, '0'), '.') . ' GB';
        }
        if ($bytes >= 1024 ** 2) {
            $v = round($bytes / 1024 ** 2, 1);
            return rtrim(rtrim((string) $v, '0'), '.') . ' MB';
        }
        return max(0, (int) round($bytes / 1024, 1)) . ' KB';
    }
}
