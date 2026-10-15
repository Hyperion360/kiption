<?php // upgrade/Coordinator.php
declare(strict_types=1);

namespace Upgrade;

require_once __DIR__ . '/Journal.php';
require_once __DIR__ . '/ReleasePointer.php';
require_once __DIR__ . '/Restore.php';

/**
 * The stable upgrade state machine: prepared, backed_up, switched,
 * migrating, rollback_pending, complete, rolled_back. Each state is
 * journaled BEFORE the side effect that justifies it, so a crash at any
 * point leaves a record that says exactly what did and did not happen:
 *
 *   prepared      -> backed_up        (Kip Backup snapshots + saved assets)
 *   backed_up     -> switched         (asset swap, two journaled renames, pointer)
 *   backed_up     -> rollback_pending (any activate failure past the backup)
 *   switched      -> migrating        (the fresh continue request)
 *   migrating     -> complete         (migrations, caches, pages)
 *   migrating     -> rollback_pending (any continue failure)
 *   rollback_pending -> rolled_back   (recovery; then the journal clears)
 *
 * activate runs with the SOURCE release's classes only (Kip Backup); the
 * continue transition is a fresh request or process that loads the TARGET
 * release's classes only; recovery loads NEITHER (built-in PHP and PDO via
 * Restore). Maintenance is written while the exclusive run.lock lease is
 * held, tagged with "upgrade", so normal web and CLI entry points that
 * lease before checking it can trust what they read, and a failed import's
 * marker is never mistaken for ours.
 *
 * Transitions never leave the site serving while databases are switched:
 * the whole activate and each recovery run hold the exclusive lease from
 * before the first side effect until after maintenance is in place (or
 * cleared, at the end of recovery). The lock between HTTP transitions is
 * safe to release because maintenance then refuses every new writer.
 *
 * Test seam: env['faults'] is a list of one-shot fault names (backup,
 * assets_mid, assets, pointer, migrate1, migrate2, complete, restore,
 * journal) consumed in order; production env never carries it.
 */
final class Coordinator
{
    /** Journal transitions accepted by the coordinator; all others fail closed. */
    private const NEXT = [
        Journal::PREPARED => [Journal::BACKED_UP],
        Journal::BACKED_UP => [Journal::SWITCHED, Journal::ROLLBACK_PENDING],
        Journal::SWITCHED => [Journal::MIGRATING, Journal::ROLLBACK_PENDING],
        Journal::MIGRATING => [Journal::COMPLETE, Journal::ROLLBACK_PENDING],
        Journal::ROLLBACK_PENDING => [Journal::ROLLED_BACK],
    ];

    private const RESTORABLE = [
        Journal::BACKED_UP, Journal::SWITCHED, Journal::MIGRATING, Journal::ROLLBACK_PENDING,
    ];

    /**
     * The web entry: one authorized transition, one response. A successful
     * activate returns a 303 to the read-only status page, whose Continue
     * form starts the fresh request that migrates under the target.
     *
     * @param array<string,mixed> $session passed for capability cleanup
     * @param array<string,mixed> $env
     * @return array{status:int, headers:list<string>, body:string}
     */
    public static function web(string $command, array &$session, array $env): array
    {
        $journal = new Journal((string) ($env['shared_dir'] ?? ''), ['now' => isset($env['now']) ? (int) $env['now'] : null]);
        $result = self::dispatch($command, $env, $journal);
        if ($result['ok']) {
            if (in_array($command, ['continue', 'recover'], true)) {
                unset($session['upgrade_capability']); // a finished or restored upgrade has no live capability
            }
            if ($command === 'activate') {
                return UpgradeController::redirect('/upgrade.php');
            }
            return UpgradeController::page(UpgradeController::statusBody($journal, $session));
        }
        return UpgradeController::plain($result['status'], $result['message']);
    }

    /** The private CLI entry: same machine, print-and-exit. */
    public static function cli(string $command, array $env): int
    {
        $journal = new Journal((string) ($env['shared_dir'] ?? ''), ['now' => isset($env['now']) ? (int) $env['now'] : null]);
        $result = self::dispatch($command, $env, $journal);
        if ($result['ok']) {
            echo $result['message'] . "\n";
            return 0;
        }
        fwrite(STDERR, $result['message'] . "\n");
        return 1;
    }

    // ------------------------------------------------------------- dispatch

    /** @return array{ok:bool, status:int, message:string} */
    private static function dispatch(string $command, array $env, Journal $journal): array
    {
        return match ($command) {
            'activate' => self::activate($env, $journal),
            'continue' => self::continueUpgrade($env, $journal),
            'recover' => self::recover($env, $journal),
            default => self::fail(400, 'Unknown upgrade command'),
        };
    }

    // ------------------------------------------------------------- activate

    /**
     * Snapshot and switch, under the source release's classes only. Every
     * step is journaled before the next side effect, and each journaled
     * step is skipped when already done, so a crash anywhere is resumed by
     * running activate again.
     *
     * @return array{ok:bool, status:int, message:string}
     */
    private static function activate(array $env, Journal $journal): array
    {
        $shared = (string) ($env['shared_dir'] ?? '');
        $siteRoot = (string) ($env['site_root'] ?? '');
        $lease = self::lease($env);
        if ($lease === null) {
            return self::fail(409, 'Another Kiption command holds the run lock; try again when it finishes');
        }
        try {
            $record = $journal->read();
            if ($record === null) {
                return self::fail(409, 'No upgrade is recorded; run prepare first');
            }
            $state = (string) $record['state'];
            if ($state === Journal::SWITCHED) {
                return ['ok' => true, 'status' => 200, 'message' => 'The release is already switched; continue migrates it.'];
            }
            if ($state !== Journal::PREPARED && $state !== Journal::BACKED_UP) {
                return self::fail(409, 'The upgrade is not in a state that can activate');
            }
            if (self::foreignMaintenance($shared)) {
                return self::fail(409, 'Kiption is in maintenance for another reason; resolve that first');
            }
            $targetRoot = $siteRoot . '/releases/' . (string) $record['target_id'];
            if (!is_dir($targetRoot)) {
                return self::fail(503, 'The staged release is gone; recover and stage it again');
            }

            // Maintenance lands while the exclusive lease is held, so every
            // entry point that leases before checking it is already shut.
            UpgradeController::writeMaintenance($shared, 'activate ' . (string) ($record['target_version'] ?? ''));

            if ($state === Journal::PREPARED) {
                // Backup and asset save: a failure here advances nothing.
                try {
                    $faults = self::faultSink($env);
                    self::fault($faults, 'backup');
                    $record['backup'] = self::snapshot($env, $record);
                    $record['assets_saved_dir'] = self::saveAssets($env, $record, $shared);
                    $record['assets'] = 'saved';
                    $record['state'] = Journal::BACKED_UP;
                    $journal->write($record);
                } catch (\Throwable $e) {
                    return self::fail(503, 'The upgrade stopped during the backup; nothing switched and the site is in maintenance: fix the cause and activate again, or recover. (' . $e->getMessage() . ')');
                }
            }

            // From backed_up: stage, swap, and switch, journaled per step.
            $faults = self::faultSink($env);
            try {
                if (($record['assets'] ?? 'saved') === 'saved') {
                    $record['assets_stage_dir'] = self::stageAssets($targetRoot, $record, $shared);
                    $record['assets'] = 'staged';
                    $journal->write($record);
                }
                $publicDir = UpgradeController::publicDir($env);
                if ($record['assets'] === 'staged') {
                    self::moveAssetsAside($publicDir, (string) $record['assets_saved_dir']);
                    $record['assets'] = 'old_moved';
                    $journal->write($record);
                    self::fault($faults, 'assets_mid');
                }
                if ($record['assets'] === 'old_moved') {
                    if (!@rename((string) $record['assets_stage_dir'], $publicDir . '/assets')) {
                        throw new \RuntimeException('cannot move the staged asset tree in');
                    }
                    $record['assets'] = 'swapped';
                    $journal->write($record);
                    self::fault($faults, 'assets');
                }
                ReleasePointer::switch($siteRoot, (string) $record['target_id']);
                $record['state'] = Journal::SWITCHED;
                $journal->write($record);
                self::fault($faults, 'pointer');
            } catch (\Throwable $e) {
                // Past backed_up, the safe failure state is rollback_pending;
                // a caught failure always rolls back (a real process death
                // leaves the journaled state, and activate resumes forward).
                $record['state'] = Journal::ROLLBACK_PENDING;
                try {
                    $journal->write($record);
                } catch (\Throwable) {
                    // the state on disk already tells the truth
                }
                return self::fail(503, 'The upgrade failed before completion and the site is in maintenance; recovery restores the matching snapshot');
            }
            return ['ok' => true, 'status' => 200, 'message' => 'The new release is switched on; continue migrates the database.'];
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    /**
     * The Kip Backup snapshots of all three databases, extracted and
     * hash-pinned. The source release's vendor is loaded here and only
     * here; Backup's ZIP is kept in the operator's backup directory for
     * the off-host download, and the verified loose set drives recovery.
     * @return array<string,mixed>
     */
    private static function snapshot(array $env, array $record): array
    {
        $shared = (string) ($env['shared_dir'] ?? '');
        $siteRoot = (string) ($env['site_root'] ?? '');
        $sourceRoot = $siteRoot . '/releases/' . (string) $record['source_id'];
        if (!class_exists(\Kip\Backup::class)) {
            require_once $sourceRoot . '/vendor/autoload.php';
        }
        $stamp = date('Ymd-Hisv');
        $produced = (new \Kip\Backup([
            'data' => 'sqlite:' . $shared . '/data.sqlite',
            'logs' => 'sqlite:' . $shared . '/logs.sqlite',
            'cache' => 'sqlite:' . $shared . '/cache.sqlite',
        ], $shared . '/backups', 14))->run($stamp);
        $snapshotDir = $shared . '/upgrade/snapshot-' . $stamp;
        $zipPath = '';
        if (is_dir($produced)) {
            // No zip extension on this host: Backup left the loose copies.
            $snapshotDir = self::adoptLooseSnapshots($produced, $snapshotDir);
        } else {
            $zipPath = (string) $produced;
            if (!is_dir($snapshotDir) && !@mkdir($snapshotDir, 0775, true) && !is_dir($snapshotDir)) {
                throw new \RuntimeException('cannot create the verified snapshot area');
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true || !$zip->extractTo($snapshotDir)) {
                $zip->close();
                throw new \RuntimeException('cannot verify the backup archive contents');
            }
            $zip->close();
        }
        $hashes = [];
        foreach (['data', 'logs', 'cache'] as $label) {
            $file = $snapshotDir . '/' . $label . '.sqlite';
            if (!is_file($file)) {
                throw new \RuntimeException('the backup is missing the ' . $label . ' snapshot');
            }
            $hashes[$label] = (string) hash_file('sha256', $file);
        }
        return ['zip' => $zipPath, 'dir' => $snapshotDir, 'sha256' => $hashes, 'stamp' => $stamp];
    }

    /** Renames Backup's loose no-zip copies into the snapshot area. */
    private static function adoptLooseSnapshots(string $producedDir, string $snapshotDir): string
    {
        if (!is_dir($snapshotDir) && !@mkdir($snapshotDir, 0775, true) && !is_dir($snapshotDir)) {
            throw new \RuntimeException('cannot create the verified snapshot area');
        }
        foreach (scandir($producedDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                if (!rename($producedDir . '/' . $entry, $snapshotDir . '/' . $entry)) {
                    throw new \RuntimeException('cannot adopt the loose backup copies');
                }
            }
        }
        return $snapshotDir;
    }

    /** The rollback copy of the current public asset tree. */
    private static function saveAssets(array $env, array $record, string $shared): string
    {
        $stamp = (string) ($record['backup']['stamp'] ?? date('Ymd-Hisv'));
        $saved = $shared . '/upgrade/assets-' . $stamp;
        $current = UpgradeController::publicDir($env) . '/assets';
        if (!is_dir($saved) && !@mkdir($saved, 0775, true) && !is_dir($saved)) {
            throw new \RuntimeException('cannot create the saved asset area');
        }
        if (is_dir($current)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($current, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
            );
            foreach ($it as $path) {
                $rel = ltrim(substr((string) $path, strlen($current)), '/');
                $to = $saved . '/' . $rel;
                $dir = dirname($to);
                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException('cannot save the current asset tree');
                }
                if (!@rename((string) $path, $to) && !@copy((string) $path, $to)) {
                    throw new \RuntimeException('cannot save the current asset tree');
                }
            }
        }
        return $saved;
    }

    /**
     * The target asset tree, copied to the same filesystem and verified
     * against the target manifest before anything in public moves.
     */
    private static function stageAssets(string $targetRoot, array $record, string $shared): string
    {
        $stamp = (string) ($record['backup']['stamp'] ?? date('Ymd-Hisv'));
        $stage = $shared . '/upgrade/stage-' . $stamp;
        $source = $targetRoot . '/public/assets';
        if (!is_dir($stage) && !@mkdir($stage, 0775, true) && !is_dir($stage)) {
            throw new \RuntimeException('cannot create the staged asset area');
        }
        if (is_dir($source)) {
            self::copyTree($source, $stage);
        }
        // Every staged file must be exactly what the verified manifest pins.
        $manifest = json_decode((string) @file_get_contents($targetRoot . '/RELEASE.json'), true);
        $pins = is_array($manifest) ? ($manifest['files'] ?? []) : [];
        if (!is_array($pins)) {
            throw new \RuntimeException('the target manifest is unreadable');
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
        );
        foreach ($it as $path) {
            $rel = ltrim(substr((string) $path, strlen($stage)), '/');
            $pin = (string) ($pins['public/assets/' . $rel] ?? '');
            if ($pin === '' || !hash_equals($pin, (string) hash_file('sha256', (string) $path))) {
                throw new \RuntimeException('a staged asset does not match the target manifest');
            }
        }
        return $stage;
    }

    /** Rename the old asset tree into the saved area's discard slot. */
    private static function moveAssetsAside(string $publicDir, string $savedDir): void
    {
        $current = $publicDir . '/assets';
        if (!is_dir($current)) {
            return; // already aside (a resumed activate)
        }
        $discard = dirname($savedDir) . '/discard-' . basename($savedDir);
        if (!@rename($current, $discard)) {
            throw new \RuntimeException('cannot move the current asset tree aside');
        }
    }

    // -------------------------------------------------------------- continue

    /**
     * The target-only transition: migrations, caches, generated pages. The
     * source release's classes never load here, and a failure lands the
     * journal at rollback_pending without touching the source.
     * @return array{ok:bool, status:int, message:string}
     */
    private static function continueUpgrade(array $env, Journal $journal): array
    {
        $shared = (string) ($env['shared_dir'] ?? '');
        $siteRoot = (string) ($env['site_root'] ?? '');
        $lease = self::lease($env);
        if ($lease === null) {
            return self::fail(409, 'Another Kiption command holds the run lock; try again when it finishes');
        }
        try {
            $record = $journal->read();
            if ($record === null) {
                return self::fail(409, 'No upgrade is recorded; run prepare first');
            }
            $state = (string) $record['state'];
            if ($state === Journal::ROLLBACK_PENDING) {
                return self::fail(503, 'The upgrade is pending recovery; run recover first');
            }
            if ($state !== Journal::SWITCHED && $state !== Journal::MIGRATING && $state !== Journal::COMPLETE) {
                return self::fail(409, 'The upgrade must be switched before it can continue');
            }
            if (self::foreignMaintenance($shared)) {
                return self::fail(409, 'Kiption is in maintenance for another reason; resolve that first');
            }
            $targetRoot = $siteRoot . '/releases/' . (string) $record['target_id'];
            if (!is_dir($targetRoot)) {
                return self::fail(503, 'The switched release is gone; recover');
            }
            UpgradeController::writeMaintenance($shared, 'continue ' . (string) ($record['target_version'] ?? ''));
            $faults = self::faultSink($env);
            try {
                if (!class_exists(\Kip\Database::class)) {
                    require_once $targetRoot . '/vendor/autoload.php';
                }
                self::fault($faults, 'migrate1');
                if ($state !== Journal::COMPLETE) {
                    $record['state'] = Journal::MIGRATING;
                    $journal->write($record);
                    \App\Setup\Install::migrate(self::targetConfig($env, $targetRoot));
                    self::fault($faults, 'migrate2');
                    self::clearCaches(self::targetConfig($env, $targetRoot));
                    self::rebuildPages(self::targetConfig($env, $targetRoot));
                    $record['state'] = Journal::COMPLETE;
                    $journal->write($record);
                }
                self::fault($faults, 'complete');
                UpgradeController::clearUpgradeMaintenance($shared);
                $journal->clear();
                return ['ok' => true, 'status' => 200, 'message' => 'The upgrade completed; the site is live on the new release.'];
            } catch (\Throwable $e) {
                if (($record['state'] ?? '') !== Journal::COMPLETE) {
                    $record['state'] = Journal::ROLLBACK_PENDING;
                    try {
                        $journal->write($record);
                    } catch (\Throwable) {
                        // the state on disk already tells the truth
                    }
                }
                return self::fail(503, 'The target release failed to migrate; the site is in maintenance and recovery restores the matching snapshot; run recover');
            }
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    // --------------------------------------------------------------- recover

    /**
     * Recovery: the matching snapshot restored by built-in PHP and PDO
     * alone, the saved assets back, the pointer on the source release, the
     * schema ledger verified against the restored release's own inventory,
     * and maintenance cleared as the final step. Neither release's classes
     * ever load here.
     * @return array{ok:bool, status:int, message:string}
     */
    private static function recover(array $env, Journal $journal): array
    {
        $shared = (string) ($env['shared_dir'] ?? '');
        $siteRoot = (string) ($env['site_root'] ?? '');
        $lease = self::lease($env);
        if ($lease === null) {
            return self::fail(409, 'Another Kiption command holds the run lock; try again when it finishes');
        }
        try {
            $record = $journal->read();
            if ($record === null) {
                return ['ok' => true, 'status' => 200, 'message' => 'No upgrade is recorded; nothing to recover.'];
            }
            $state = (string) $record['state'];
            if ($state === Journal::PREPARED) {
                $journal->clear();
                UpgradeController::clearUpgradeMaintenance($shared);
                return ['ok' => true, 'status' => 200, 'message' => 'The prepared upgrade was cleared; nothing was ever switched.'];
            }
            if (!in_array($state, self::RESTORABLE, true) && $state !== Journal::ROLLED_BACK) {
                return self::fail(409, 'The recorded upgrade state cannot be recovered');
            }
            $faults = self::faultSink($env);
            try {
                if (in_array($state, self::RESTORABLE, true)) {
                    Restore::databases(is_array($record['backup'] ?? null) ? $record['backup'] : [], $shared,
                        static function (string $label) use (&$faults): void {
                            self::fault($faults, 'restore');
                        });
                    Restore::assets((string) ($record['assets_saved_dir'] ?? ''), UpgradeController::publicDir($env));
                    ReleasePointer::switch($siteRoot, (string) $record['source_id']);
                    if (!Restore::ledgerMatches($shared . '/data.sqlite', self::sourceMigrationDirs($siteRoot . '/releases/' . (string) $record['source_id']))) {
                        return self::fail(503, 'The restored schema does not match the restored release; maintenance stays on; restore the backup archive manually');
                    }
                }
                $record['state'] = Journal::ROLLED_BACK;
                $journal->write($record);
                self::fault($faults, 'journal');
                UpgradeController::clearUpgradeMaintenance($shared);
                self::cleanup($shared, $record);
                $journal->clear();
                return ['ok' => true, 'status' => 200, 'message' => 'The previous release was restored; the site is live again.'];
            } catch (\Throwable $e) {
                return self::fail(503, 'Recovery did not finish; the site stays in maintenance and recovery can be run again (' . $e->getMessage() . ')');
            }
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    // -------------------------------------------------------------- helpers

    /** @return resource|null */
    private static function lease(array $env)
    {
        return UpgradeController::exclusiveLease($env);
    }

    /** @param list<string> $envFaults */
    private static function faultSink(array $env): array
    {
        $faults = $env['faults'] ?? [];
        return is_array($faults) ? array_values($faults) : [];
    }

    /** @param list<string> $faults */
    private static function fault(array &$faults, string $name): void
    {
        $at = array_search($name, $faults, true);
        if ($at !== false) {
            unset($faults[$at]);
            throw new \RuntimeException('injected upgrade fault: ' . $name);
        }
    }

    /** Maintenance another writer (a committed import) owns, not ours. */
    private static function foreignMaintenance(string $shared): bool
    {
        $raw = @file_get_contents($shared . '/maintenance.lock');
        return $raw !== false && !str_starts_with($raw, 'upgrade');
    }

    /** @return array<string,mixed> the target release's config, paths pinned to this site */
    private static function targetConfig(array $env, string $targetRoot): array
    {
        $config = require $targetRoot . '/config.php';
        $shared = (string) ($env['shared_dir'] ?? '');
        $public = UpgradeController::publicDir($env);
        $config['app_dir'] = $targetRoot . '/app';
        $config['data_dir'] = $shared;
        $config['db']['dsn'] = 'sqlite:' . $shared . '/data.sqlite';
        $config['log_db']['dsn'] = 'sqlite:' . $shared . '/logs.sqlite';
        $config['cache_db']['dsn'] = 'sqlite:' . $shared . '/cache.sqlite';
        $config['public_dir'] = $public;
        $config['static_cache']['dir'] = $public . '/cache';
        $config['uploads']['dir'] = $public . '/uploads';
        $config['nav_file'] = $shared . '/nav.json';
        $config['backups']['dir'] = $shared . '/backups';
        $config['views'] = $config['app_dir'] . '/views';
        $deploymentFile = $shared . '/deployment.php';
        $deployment = is_file($deploymentFile) ? (require $deploymentFile) : [];
        $config['base_url'] = (string) ($deployment['base_url'] ?? ($config['base_url'] ?? ''));
        $config['mail'] = $deployment['mail'] ?? ($config['mail'] ?? []);
        return $config;
    }

    private static function clearCaches(array $config): void
    {
        $dsn = (string) ($config['cache_db']['dsn'] ?? '');
        if (str_starts_with($dsn, 'sqlite:') && !str_contains($dsn, ':memory:')) {
            $file = explode('?', substr($dsn, strlen('sqlite:')), 2)[0];
            foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
        if (!empty($config['static_cache']['enabled']) && is_string($config['static_cache']['dir'] ?? null)) {
            (new \App\StaticCache\Cache((string) $config['static_cache']['dir']))->purgeAll();
        }
    }

    /** The pages:build arm's preamble against the target release. */
    private static function rebuildPages(array $config): void
    {
        \App\Lang::setCurrent($config['ui_lang'] ?? 'en');
        \App\Features::init(new \Kip\Database($config['db']['dsn']), $config['features'] ?? []);
        $config = \App\Settings::apply($config);
        \App\Attribution::init($config);
        $config['views_override'] = \App\Skins::overrideDir($config);
        if (!empty($config['static_cache']['enabled']) && is_string($config['static_cache']['dir'] ?? null)) {
            \App\StaticCache\Builder::build($config, (string) $config['static_cache']['dir']);
        }
    }

    /** @return list<string> the source release's migration directories */
    private static function sourceMigrationDirs(string $sourceRoot): array
    {
        $dirs = [$sourceRoot . '/app/migrations'];
        $featuresDir = $sourceRoot . '/app/Features';
        $entries = is_dir($featuresDir) ? @scandir($featuresDir) : false;
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry[0] !== '.' && is_dir($featuresDir . '/' . $entry . '/migrations')) {
                    $dirs[] = $featuresDir . '/' . $entry . '/migrations';
                }
            }
        }
        return $dirs;
    }

    /** The upgrade work areas a finished upgrade leaves behind. */
    private static function cleanup(string $shared, array $record): void
    {
        $paths = [
            (string) ($record['backup']['dir'] ?? ''),
            (string) ($record['assets_saved_dir'] ?? ''),
            (string) ($record['assets_stage_dir'] ?? ''),
        ];
        foreach ($paths as $path) {
            if ($path !== '' && is_dir(dirname($path)) && str_starts_with($path, $shared . '/upgrade/')) {
                Restore::rrmdir($path);
            }
        }
        foreach (glob($shared . '/upgrade/discard-*') ?: [] as $discard) {
            Restore::rrmdir((string) $discard);
        }
    }

    private static function copyTree(string $source, string $destination): void
    {
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . '/' . $entry;
            $to = $destination . '/' . $entry;
            if (is_dir($from)) {
                if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
                    throw new \RuntimeException('cannot stage the asset tree');
                }
                self::copyTree($from, $to);
            } elseif (!@copy($from, $to)) {
                throw new \RuntimeException('cannot stage the asset tree');
            }
        }
    }

    /** @return array{ok:false, status:int, message:string} */
    private static function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
