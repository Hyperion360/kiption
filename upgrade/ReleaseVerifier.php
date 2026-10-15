<?php // upgrade/ReleaseVerifier.php
declare(strict_types=1);

namespace Upgrade;

/**
 * Verifies a privately staged release WITHOUT executing any of it. The
 * owner extracts the release-root upgrade ZIP into
 * site/releases/<digest>/ through the hosting file manager and enters the
 * RELEASE.json SHA-256 published on the official release page; this class
 * proves the staged tree is exactly what those two secrets describe before
 * anything switches.
 *
 * Read-only contract: bytes and hashes only. No staged file is ever
 * required, included, or evaluated; the live databases are never opened for
 * writing (the optional browser rehearsal writes VACUUM INTO throwaway
 * copies only); the pointer, the journal, and the maintenance marker are
 * never touched. Disposable probe files under site/upgrade-probe areas and
 * site/shared/upgrade/ are created, checked, and removed by the same call.
 *
 * The digest is the anchor: a tampered file plus a coherently rebuilt
 * manifest still fails, because the expected digest is entered separately
 * from the staged bytes. Only as strong as the owner's official HTTPS
 * source and a correct paste; not a cryptographic signature.
 *
 * Options are TEST SEAMS ONLY (free_of, fault, time_budget, now); a
 * production coordinator never sets them.
 */
final class ReleaseVerifier
{
    /** Documented head-room demanded on top of every measured peak. */
    private const DISK_MARGIN_BYTES = 67108864; // 64 MiB
    private const STALE_SECONDS = 3600;

    /** @param array<string,string> $dbs label => live sqlite DSN */
    public function __construct(
        private string $siteRoot,
        private string $activeManifest,
        private array $dbs,
        private string $publicDir,
        private array $options = [],
    ) {}

    /**
     * @param string $stage the staging directory site/releases/<digest>/
     * @param string $expectedSha256 the owner-entered published RELEASE.json digest
     * @param bool $rehearse run the bounded backup-and-restore rehearsal (browser path)
     * @return array{ok:bool, failures:list<string>, id:?string, version:?string, rehearsal:array<string,float>}
     */
    public function verify(string $stage, string $expectedSha256, bool $rehearse): array
    {
        $failures = [];
        $rehearsal = [];
        $id = null;
        $meta = null;

        // The entered digest: the only thing trusted before the stage itself.
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1) {
            $failures[] = 'enter the 64-character release digest from the official release page';
            return $this->result(false, $failures, null, null, $rehearsal);
        }

        // The staging directory: real, named for the digest it claims.
        $stageReal = realpath($stage);
        if ($stageReal === false || !is_dir($stageReal) || is_link($stageReal)) {
            $failures[] = 'the staging directory is missing';
            return $this->result(false, $failures, null, null, $rehearsal);
        }
        if (basename($stageReal) !== $expectedSha256) {
            $failures[] = 'the staging directory must be named exactly for the release digest';
        }
        // An install ZIP extracted into a release directory leaves a nested
        // site/ tree; that is never a valid stage.
        if (is_dir($stageReal . '/site') || is_file($stageReal . '/site')) {
            $failures[] = 'this looks like an install ZIP; stage the release-root upgrade ZIP so RELEASE.json sits directly inside the staging directory';
        }

        // The manifest and the independently entered digest must agree.
        $manifestFile = $stageReal . '/RELEASE.json';
        $digest = is_file($manifestFile) && !is_link($manifestFile) ? hash_file('sha256', $manifestFile) : false;
        if ($digest === false) {
            $failures[] = 'the staged release carries no RELEASE.json';
        } elseif (!hash_equals($expectedSha256, (string) $digest)) {
            $failures[] = 'the staged RELEASE.json does not match the entered digest; the download is altered or the digest is not this release';
        } else {
            $id = (string) $digest;
        }

        // Manifest schema, decoded but never executed.
        if ($id !== null) {
            $meta = json_decode((string) file_get_contents($manifestFile), true);
            if (!is_array($meta)) {
                $failures[] = 'RELEASE.json is not a readable manifest';
                $meta = null;
            } else {
                if (!empty($meta['development_bundle'])) {
                    $failures[] = 'this is a development bundle; it can never be an upgrade target';
                }
                $semver = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/';
                if (!is_string($meta['version'] ?? null) || preg_match($semver, $meta['version']) !== 1) {
                    $failures[] = 'the release version is missing or not SemVer';
                }
                if (($meta['upgrade_protocol'] ?? 0) !== 1) {
                    $failures[] = 'the release uses an upgrade protocol this engine does not support';
                }
                if (!is_array($meta['files'] ?? null) || $meta['files'] === []) {
                    $failures[] = 'the manifest lists no files';
                } elseif (count($meta['files']) > 100000) {
                    $failures[] = 'the manifest lists implausibly many files';
                    $meta['files'] = null;
                }
                if (!isset($meta['files']['vendor/autoload.php'])) {
                    $failures[] = 'the manifest has no framework autoloader';
                }
            }
        }

        // Against the active release: protocol identity and a strictly newer version.
        $active = json_decode($this->activeManifest, true);
        if (!is_array($active)) {
            $failures[] = 'the active release manifest is unreadable; recovery comes first';
        } else {
            if (($active['upgrade_protocol'] ?? 0) !== ($meta['upgrade_protocol'] ?? null)) {
                $failures[] = 'the staged and active releases disagree on the upgrade protocol';
            }
            $semver = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/';
            $activeVersion = (string) ($active['version'] ?? '');
            $targetVersion = is_array($meta) ? (string) ($meta['version'] ?? '') : '';
            if (preg_match($semver, $targetVersion) === 1 && preg_match($semver, $activeVersion) === 1) {
                if (version_compare($targetVersion, $activeVersion) <= 0) {
                    // Equal versions with different bytes are the tamper case;
                    // lower versions are downgrades. Neither is an upgrade.
                    $failures[] = 'the staged release is not newer than the active release';
                }
            }
        }

        // The staged tree: every listed file present, hash-matching, link-free;
        // nothing unlisted on disk at all.
        if (is_array($meta) && isset($meta['files']) && is_array($meta['files'])) {
            $failures = [...$failures, ...$this->verifyTree($stageReal, $meta['files'])];
        }

        // The release must run on this PHP.
        if (is_array($meta)) {
            $failures = [...$failures, ...$this->verifyPhpRequirement($stageReal)];
        }

        // Disk, locking, rename, and cross-device semantics on the real
        // filesystems the upgrade will use.
        $failures = [...$failures, ...$this->verifyDisks($stageReal)];
        $failures = [...$failures, ...$this->verifyFilesystemSemantics()];

        // The browser path must be able to finish inside one request.
        if ($rehearse) {
            [$failures, $rehearsal] = $this->rehearse($failures);
        }

        return $this->result($failures === [], $failures, $id, is_array($meta) ? (string) ($meta['version'] ?? '') : null, $rehearsal);
    }

    /** @return array{ok:bool, failures:list<string>, id:?string, version:?string, rehearsal:array<string,float>} */
    private function result(bool $ok, array $failures, ?string $id, ?string $version, array $rehearsal): array
    {
        return ['ok' => $ok, 'failures' => $failures, 'id' => $id, 'version' => $version, 'rehearsal' => $rehearsal];
    }

    /** @return list<string> */
    private function verifyTree(string $stage, array $files): array
    {
        $failures = [];
        foreach ($files as $rel => $hash) {
            $rel = (string) $rel;
            if (str_starts_with($rel, '/') || str_contains($rel, '\\') || str_contains($rel, "\0")
                || preg_match('#(^|/)\.\.(/|$)#', $rel) === 1) {
                $failures[] = 'the manifest carries an unsafe path';
                continue;
            }
            if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                $failures[] = "the manifest hash for {$rel} is malformed";
                continue;
            }
            // No component of the staged path may be a symlink: the realpath
            // of a link would silently move the check outside the tree.
            $prefix = $stage;
            foreach (explode('/', $rel) as $part) {
                $prefix .= '/' . $part;
                if (is_link($prefix)) {
                    $failures[] = "the staged tree carries a symlink at {$rel}";
                    continue 2;
                }
            }
            if (!is_file($prefix)) {
                $failures[] = "the manifest lists a missing staged file: {$rel}";
                continue;
            }
            if (!hash_equals($hash, (string) hash_file('sha256', $prefix))) {
                $failures[] = "a staged file does not match the manifest: {$rel}";
            }
        }
        // Nothing unlisted: an extra file means the archive does not match
        // what was published, whatever the hashes say.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
        );
        foreach ($it as $path) {
            $rel = ltrim(substr((string) $path, strlen($stage)), '/');
            if ($rel === 'RELEASE.json') {
                continue;
            }
            if (is_link($path)) {
                $failures[] = "the staged tree carries a symlink at {$rel}";
                continue;
            }
            if (!isset($files[$rel])) {
                $failures[] = "the staged tree carries an unlisted file: {$rel}";
            }
        }
        return $failures;
    }

    /** The staged composer.json PHP requirement must admit the running PHP. @return list<string> */
    private function verifyPhpRequirement(string $stage): array
    {
        $raw = @file_get_contents($stage . '/composer.json');
        $composer = is_string($raw) ? json_decode($raw, true) : null;
        $constraint = is_array($composer) ? (string) ($composer['require']['php'] ?? '') : '';
        if ($constraint === '') {
            return ['the staged release states no PHP requirement'];
        }
        foreach (preg_split('/\s*\|\|?\s*|\s*,\s*/', trim($constraint)) ?: [] as $clause) {
            if ($clause === '') {
                continue;
            }
            if ($this->phpClauseMatches($clause)) {
                return [];
            }
        }
        return ['this host runs PHP ' . PHP_VERSION . '; the staged release requires ' . $constraint];
    }

    /** One composer version clause (^8.3, >=8.3, ~8.3, 8.3.*, 8.3.1). */
    private function phpClauseMatches(string $clause): bool
    {
        $clause = str_replace(' ', '', $clause);
        if (str_ends_with($clause, '.*')) {
            $prefix = substr($clause, 0, -2);
            return str_starts_with(PHP_VERSION . '.', $prefix . '.');
        }
        $op = preg_match('/^(>=|<=|>|<|\^|~)/', $clause, $m) === 1 ? $m[1] : '=';
        $version = substr($clause, strlen($op));
        if (!preg_match('/^\d+(\.\d+)*$/', $version)) {
            return false; // unparseable: fail closed
        }
        $bound = fn (string $v): string => $v . (substr_count($v, '.') === 1 ? '.0' : '');
        $cmp = version_compare(PHP_VERSION, $bound($version));
        return match ($op) {
            '>=' => $cmp >= 0,
            '<=' => $cmp <= 0,
            '>' => $cmp > 0,
            '<' => $cmp < 0,
            // ^8.3: >= 8.3, < 9.0. ~8.3.1: >= 8.3.1, < 8.4.
            '^' => $cmp >= 0 && version_compare(PHP_VERSION, $this->nextUp($version, 0)) < 0,
            '~' => $cmp >= 0 && version_compare(PHP_VERSION, $this->nextUp($version, 1)) < 0,
            default => $cmp === 0 || PHP_VERSION === $bound($version),
        };
    }

    /** The ceiling for ^ / ~ clauses: bump the given position, zero the rest. */
    private function nextUp(string $version, int $position): string
    {
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0';
        }
        $bumped = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
        $bumped[$position]++;
        for ($i = $position + 1; $i < 3; $i++) {
            $bumped[$i] = 0;
        }
        return implode('.', $bumped);
    }

    /**
     * Peak-space check per filesystem, documented as: one full VACUUM INTO
     * set plus one worst-case uncompressed archive set plus one restore-temp
     * set of all live databases, the rollback copy of the current public
     * assets, the staged target assets, and the 64 MiB safety margin.
     * @return list<string>
     */
    private function verifyDisks(string $stage): array
    {
        $failures = [];
        $freeOf = $this->options['free_of'] ?? null;
        $now = $this->options['now'] ?? time();

        $dbBytes = 0.0;
        foreach ($this->dbs as $label => $dsn) {
            $file = $this->sqliteFile((string) $dsn);
            if ($file === null || !is_file($file)) {
                // The cache database is disposable by contract (the
                // cache:clear arm unlinks it): a missing one is an empty
                // one, contributing no snapshot bytes and nothing to
                // rehearse. The content and log databases are not
                // disposable.
                if ($label === 'cache') {
                    continue;
                }
                $failures[] = "the live {$label} database is missing; recovery comes first";
                continue;
            }
            $clear = @stat($file);
            $dbBytes += (float) ($clear['size'] ?? 0);
        }
        $currentAssets = $this->treeBytes($this->publicDir . '/assets');
        $targetAssets = $this->treeBytes($stage . '/public/assets');

        // Needs per anchor path; grouped onto real filesystems below.
        $needs = [
            $this->siteRoot . '/shared' => 3 * $dbBytes + $currentAssets + $targetAssets + (float) self::DISK_MARGIN_BYTES,
            $this->publicDir => $currentAssets + (float) self::DISK_MARGIN_BYTES,
            $this->siteRoot . '/releases' => (float) self::DISK_MARGIN_BYTES,
        ];
        $groups = [];
        foreach ($needs as $anchor => $bytes) {
            $stat = @stat($anchor);
            if ($stat === false) {
                $failures[] = 'an upgrade directory is missing';
                continue;
            }
            $dev = (string) $stat['dev'];
            $groups[$dev] ??= ['path' => $anchor, 'bytes' => 0.0];
            $groups[$dev]['bytes'] += $bytes;
        }
        foreach ($groups as ['path' => $path, 'bytes' => $bytes]) {
            $free = $freeOf !== null ? ($freeOf($path) ?: 0.0) : (disk_free_space($path) ?: 0.0);
            if ($free < $bytes) {
                $failures[] = 'not enough free disk space for the upgrade peak (databases, backups, and both asset trees) on one of the upgrade filesystems; '
                    . 'free space needed is roughly ' . $this->bytes((int) $bytes);
            }
        }
        // Stale rehearsal throwaways from an earlier timed-out run are the
        // owner's retry business; sweep anything older than an hour.
        foreach (glob($this->siteRoot . '/shared/upgrade/rehearsal-*') ?: [] as $stale) {
            $mtime = @filemtime($stale);
            if ($mtime !== false && $now - $mtime > self::STALE_SECONDS) {
                self::rrmdir($stale);
            }
        }
        return $failures;
    }

    /** flock and atomic-rename probes on each real directory; cross-device moves refused. @return list<string> */
    private function verifyFilesystemSemantics(): array
    {
        $faults = (array) ($this->options['fault'] ?? []);
        $failures = [];
        $work = $this->siteRoot . '/shared/upgrade/probe-' . bin2hex(random_bytes(4));
        if (!@mkdir($work, 0775, true) && !is_dir($work)) {
            return ['cannot create the private upgrade work directory under site/shared'];
        }
        try {
            foreach ([$this->siteRoot . '/shared', $this->siteRoot . '/releases', $this->publicDir] as $dir) {
                $failure = $this->probeDir($dir, in_array('flock', $faults, true), in_array('rename', $faults, true));
                if ($failure !== null) {
                    $failures[] = $failure;
                }
            }
            // The asset swap renames between the private work area and the
            // public tree: a device boundary there is a refused host.
            $from = $work . '/move-probe';
            if (@file_put_contents($from, 'probe') === false) {
                $failures[] = 'cannot write the rename probe';
            } else {
                $to = $this->publicDir . '/.kiption-upgrade-probe-' . bin2hex(random_bytes(4));
                $moved = in_array('cross_device', $faults, true) ? false : @rename($from, $to);
                if ($moved) {
                    @unlink($to);
                } else {
                    @unlink($from);
                    $failures[] = 'the public tree and the private data directory are on different filesystems; the atomic asset swap cannot work on this host';
                }
            }
        } finally {
            self::rrmdir($work);
        }
        return $failures;
    }

    /** One directory: flock exclusive+shared on a disposable file, then rename it. Returns null or a failure string. */
    private function probeDir(string $dir, bool $flockFault, bool $renameFault): ?string
    {
        $file = $dir . '/.kiption-probe-' . bin2hex(random_bytes(4));
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            @unlink($file);
            return 'cannot create a disposable probe file on the ' . basename($dir) . ' filesystem';
        }
        $locked = $flockFault ? false : flock($handle, LOCK_EX | LOCK_NB);
        if ($locked) {
            $locked = $flockFault ? false : flock($handle, LOCK_SH | LOCK_NB);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        if (!$locked) {
            @unlink($file);
            return 'the ' . basename($dir) . ' filesystem does not honor flock; upgrades cannot be leased safely there';
        }
        $renamed = $renameFault ? false : @rename($file, $file . '.moved');
        if ($renamed) {
            @unlink($file . '.moved');
            return null;
        }
        @unlink($file);
        return 'the ' . basename($dir) . ' filesystem does not support atomic rename; releases cannot switch safely there';
    }

    /**
     * The bounded backup-and-restore rehearsal: VACUUM INTO throwaway
     * copies, integrity-check them, copy one set again (the restore-temp
     * shape), time everything, fail closed to the CLI path when a single
     * request cannot afford it. Live databases are only ever read.
     * @param list<string> $failures
     * @return array{0:list<string>,1:array<string,float>}
     */
    private function rehearse(array $failures): array
    {
        $budget = $this->options['time_budget'] ?? null;
        if ($budget === null) {
            $limit = (int) ini_get('max_execution_time');
            $budget = $limit > 0 ? max(5.0, (float) $limit - 5.0) : 120.0;
        }
        $budget = (float) $budget;
        $rehearsal = [];
        $dir = $this->siteRoot . '/shared/upgrade/rehearsal-' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [['cannot create the rehearsal work area'], $rehearsal];
        }
        try {
            $total = 0.0;
            foreach ($this->dbs as $label => $dsn) {
                $file = $this->sqliteFile((string) $dsn);
                if ($file === null || !is_file($file)) {
                    continue; // already reported by the disk check
                }
                $copy = $dir . '/' . $label . '.sqlite';
                $started = microtime(true);
                try {
                    $stmt = (new \PDO('sqlite:' . $file))->prepare('VACUUM INTO ?');
                    $stmt->execute([$copy]);
                    $check = (new \PDO('sqlite:' . $copy))->query('PRAGMA integrity_check')->fetchColumn();
                    if ((string) $check !== 'ok') {
                        $failures[] = "the {$label} database failed its rehearsal integrity check; run a manual backup first";
                        continue;
                    }
                    // The restore path: one more full copy on the way back.
                    if (!copy($copy, $dir . '/' . $label . '.restore.sqlite')) {
                        $failures[] = "the {$label} restore rehearsal could not copy the snapshot";
                        continue;
                    }
                } catch (\Throwable $e) {
                    $failures[] = "the {$label} database rehearsal failed; use the PHP CLI upgrade path";
                    continue;
                }
                $rehearsal[$label] = microtime(true) - $started;
                $total += $rehearsal[$label];
            }
            if ($total > $budget) {
                $failures[] = 'the backup-and-restore rehearsal took longer than one request can safely finish on this host; use the PHP CLI upgrade path';
            }
        } finally {
            self::rrmdir($dir);
        }
        return [$failures, $rehearsal];
    }

    /** The file behind a sqlite: DSN, or null for anything else. */
    private function sqliteFile(string $dsn): ?string
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return null;
        }
        $file = explode('?', substr($dsn, strlen('sqlite:')), 2)[0];
        return $file === '' || $file === ':memory:' ? null : $file;
    }

    private function treeBytes(string $dir): float
    {
        if (!is_dir($dir)) {
            return 0.0;
        }
        $bytes = 0.0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
        );
        foreach ($it as $path) {
            if (!is_link($path) && is_file($path)) {
                $bytes += (float) (filesize($path) ?: 0);
            }
        }
        return $bytes;
    }

    private function bytes(int $n): string
    {
        return $n >= 1073741824 ? sprintf('%.1f GiB', $n / 1073741824)
            : sprintf('%.0f MiB', $n / 1048576);
    }

    /** Removes a directory tree, links and all; probes and throwaways only. */
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
