<?php // app/src/Import/Runs.php
namespace App\Import;
use Kip\Database;

/** The import-run bookkeeping both importers share: migration guard,
 *  options-family guard, run rows. Moved verbatim from the eFiction
 *  Importer when the Fictioneer importer became the second caller; the
 *  only change is $this->db becoming the parameter. */
final class Runs
{
    public static function assertMigrated(Database $db): void
    {
        try { $db->one('SELECT 1 FROM import_map LIMIT 1'); }
        catch (\PDOException) { throw new \RuntimeException('run `kip migrate` first'); }
    }

    public static function assertOptionsCompatible(Database $db, string $importer, string $hash): void
    {
        // scoped per importer (review finding): an eFiction import last year
        // must never refuse a Fictioneer import today
        $row = $db->one('SELECT options_hash FROM import_runs WHERE status = \'committed\' AND importer = ? ORDER BY id DESC LIMIT 1', [$importer]);
        if ($row !== null && $row['options_hash'] !== $hash
            && (int) $db->one('SELECT COUNT(*) c FROM import_map WHERE run_id IN (SELECT id FROM import_runs WHERE importer = ?)', [$importer])['c'] > 0) {
            throw new \RuntimeException('this database already has imports under different options; refusing to mix');
        }
    }

    public static function newRun(Database $db, string $importer, string $hash): int
    {
        $db->query('INSERT INTO import_runs (options_hash, importer) VALUES (?, ?)', [$hash, $importer]);
        return (int) $db->lastInsertId();
    }

    public static function finishRun(Database $db, int $runId): void
    {
        $db->query('UPDATE import_runs SET status = \'committed\', finished_at = ? WHERE id = ?',
            [gmdate('Y-m-d\TH:i:s\Z'), $runId]);
    }
}
