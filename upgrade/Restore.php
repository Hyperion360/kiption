<?php // upgrade/Restore.php
declare(strict_types=1);

namespace Upgrade;

/**
 * The narrow snapshot-restore helper. Kip Backup snapshots; it does not
 * restore, and Migrator::rollback() cannot undo a partially applied
 * multi-file upgrade. Recovery therefore restores the verified pre-upgrade
 * snapshots of all three databases, the saved public asset tree, and the
 * previous pointer. This class is the only code recovery needs, so it
 * deliberately loads NO release classes: built-in PHP and PDO against the
 * snapshot files alone, safe to run when either release cannot boot and
 * when the users table is half-migrated. It runs only under the exclusive
 * lease with maintenance active, so no app connection holds the live
 * databases while their files are swapped.
 *
 * Test seam: the $fault callable fires between database restores so tests
 * can crash recovery mid-run and prove it is resumable. Production passes
 * none.
 */
final class Restore
{
    /**
     * Restores every verified snapshot over the live databases: hash check,
     * copy to a temporary file, stale WAL/SHM sidecars removed, atomic
     * rename. Idempotent: an already-restored set simply restores again.
     *
     * @param array<string,mixed> $backup the journal's backup record
     *        (dir, sha256 map)
     * @param callable|null $fault test seam, invoked after each restore
     */
    public static function databases(array $backup, string $sharedDir, ?callable $fault = null): void
    {
        $dir = (string) ($backup['dir'] ?? '');
        $hashes = $backup['sha256'] ?? null;
        if ($dir === '' || !is_dir($dir) || !is_array($hashes) || $hashes === []) {
            throw new \RuntimeException('the verified snapshot set is missing; restore the backup archive manually');
        }
        foreach ($hashes as $label => $hash) {
            $label = (string) $label;
            $snapshot = $dir . '/' . $label . '.sqlite';
            if (!is_file($snapshot) || !hash_equals((string) $hash, (string) hash_file('sha256', $snapshot))) {
                throw new \RuntimeException('a database snapshot failed its hash check; restore the backup archive manually');
            }
            $live = $sharedDir . '/' . $label . '.sqlite';
            $tmp = $live . '.restore-' . bin2hex(random_bytes(4));
            if (!@copy($snapshot, $tmp)) {
                @unlink($tmp);
                throw new \RuntimeException('cannot stage a database snapshot for restore');
            }
            // Stale sidecars would make the next connection replay a WAL
            // that no longer matches the restored file.
            foreach ([$live . '-wal', $live . '-shm'] as $sidecar) {
                if (is_file($sidecar)) {
                    @unlink($sidecar);
                }
            }
            if (!rename($tmp, $live)) {
                @unlink($tmp);
                throw new \RuntimeException('cannot restore a database snapshot');
            }
            if ($fault !== null) {
                $fault($label);
            }
        }
    }

    /**
     * Puts the saved public asset tree back. If a previous recovery already
     * put it there (the saved tree was renamed away), that is the done
     * state, not an error. A crash between moving the current tree aside
     * and moving the saved one in is tolerated: the current tree is
     * missing, and the saved one lands.
     */
    public static function assets(string $savedDir, string $publicDir): void
    {
        $current = $publicDir . '/assets';
        if (!is_dir($savedDir)) {
            if (is_dir($current)) {
                return; // already restored by an earlier recovery attempt
            }
            throw new \RuntimeException('the saved asset tree is missing');
        }
        $aside = null;
        if (is_dir($current)) {
            $aside = $publicDir . '/assets.old-' . bin2hex(random_bytes(4));
            if (!rename($current, $aside)) {
                throw new \RuntimeException('cannot move the replaced asset tree aside');
            }
        }
        if (!@rename($savedDir, $current)) {
            if ($aside !== null) {
                rename($aside, $current); // compensate: never leave the site without assets
            }
            throw new \RuntimeException('cannot restore the saved asset tree');
        }
        if ($aside !== null) {
            self::rrmdir($aside);
        }
    }

    /**
     * The restored schema ledger must name only migrations the restored
     * release actually carries. Verified by reading the ledger with PDO:
     * neither release is ever booted here.
     * @param list<string> $dirs the restored release's migration directories
     */
    public static function ledgerMatches(string $dataFile, array $dirs): bool
    {
        try {
            $pdo = new \PDO('sqlite:' . $dataFile);
            // Kip Migrator's ledger table, named literally: recovery loads
            // no release classes, so the constant cannot be referenced.
            $applied = $pdo->query('SELECT name FROM _migrations')->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return false;
        }
        $carried = [];
        foreach ($dirs as $dir) {
            foreach (scandir($dir) ?: [] as $entry) {
                if (in_array(pathinfo($entry, PATHINFO_EXTENSION), ['php', 'sql'], true)) {
                    $carried[] = pathinfo($entry, PATHINFO_FILENAME);
                }
            }
        }
        foreach ($applied as $name) {
            if (!in_array((string) $name, $carried, true)) {
                return false;
            }
        }
        return true;
    }

    /** Removes a directory tree, links and all; work areas only. */
    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
