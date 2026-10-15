<?php // scripts/check-release.php
declare(strict_types=1);

/*
 * The release checker: validates the built artifacts before anything ships.
 * Run by the release owner after every build (and again over a downloaded
 * archive before upload, with --expected-install-digest /
 * --expected-upgrade-digest pinning the published whole-ZIP SHA-256).
 *
 * Checked, per artifact: whole-ZIP digest against its sidecar and any
 * expected value; safe entry names (no absolute paths, traversal,
 * backslashes, or symlinks); no databases, secrets, or development clutter;
 * one canonical RELEASE.json whose SHA-256 IS the release ID, identical in
 * both ZIPs; manifest schema (SemVer, Git SHA, lock hash, build PHP,
 * upgrade_protocol 1, per-file hashes) with every payload file present and
 * hash-matching; the locked kip/framework resolved inside vendor/; required
 * stable entry points in production mode; executable PHP syntax; and the
 * upgrade payload extracting byte-identical to the install ZIP's release
 * directory.
 *
 * Development mode (--development) accepts the development_bundle marker and
 * tolerates the not-yet-present stable entry points; it never passes the
 * production gate, and a development bundle never ships.
 */

/** @return never */
function check_release_fail_out(string $message): void
{
    fwrite(STDERR, "check-release: {$message}\n");
    exit(1);
}

/**
 * The stable upgrade engine, carried as site/upgrade/<file> in every install
 * ZIP (the builder copies upgrade/*.php). A production artifact missing any
 * of these cannot be upgraded by the stable coordinator, so the gate refuses
 * it. Extended as the engine grows.
 */
const CHECK_RELEASE_ENGINE_FILES = ['ReleasePointer.php', 'ReleaseVerifier.php', 'Journal.php', 'UpgradeController.php', 'Coordinator.php', 'Restore.php', 'cli.php'];

/**
 * @param array{zip?:string,upgrade_zip?:string,development?:bool,expected_install_digest?:string,expected_upgrade_digest?:string} $o
 * @return array{ok:bool,failures:list<string>,development:bool,id:?string}
 */
function check_release(array $o): array
{
    $failures = [];
    $development = (bool) ($o['development'] ?? false);
    $id = null;

    $zipPath = (string) ($o['zip'] ?? '');
    $upgradePath = (string) ($o['upgrade_zip'] ?? '');
    if ($zipPath === '' && $upgradePath === '') {
        $failures[] = 'give at least one artifact: --zip=<install.zip> --upgrade-zip=<upgrade.zip>';
    }

    $manifest = null;
    $extractDirs = [];

    if ($zipPath !== '') {
        [$zipFailures, $manifest, $id, $extract] = check_release_zip(
            $zipPath, '.manifest.sha256', (string) ($o['expected_install_digest'] ?? ''), install: true
        );
        $failures = [...$failures, ...$zipFailures];
        if ($extract !== null) {
            $extractDirs[$zipPath] = $extract;
        }
    }

    if ($upgradePath !== '') {
        [$upFailures, $upManifest, , $upExtract] = check_release_zip(
            $upgradePath, '.manifest.sha256', (string) ($o['expected_upgrade_digest'] ?? ''), install: false
        );
        $failures = [...$failures, ...$upFailures];
        if ($upExtract !== null) {
            $extractDirs[$upgradePath] = $upExtract;
        }
        if ($manifest !== null && $upManifest !== null && $manifest !== $upManifest) {
            $failures[] = 'RELEASE.json bytes differ between the install and upgrade ZIPs';
        }
    }

    // Production gate: the development marker never passes it. Development
    // mode accepts its own marker (it IS the incomplete-slice contract) but
    // the artifact remains unpublishable.
    if ($manifest !== null) {
        $meta = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        if (!empty($meta['development_bundle']) && !$development) {
            $failures[] = 'development_bundle marker present; production check refuses it';
        }
        if (!preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/', (string) ($meta['version'] ?? ''))) {
            $failures[] = 'manifest version is missing or not SemVer';
        }
        if (!preg_match('/^[0-9a-f]{40}$/', (string) ($meta['git_sha'] ?? ''))) {
            $failures[] = 'manifest git_sha is missing or malformed';
        }
        if (!preg_match('/^[0-9a-f]{64}$/', (string) ($meta['lock_hash'] ?? ''))) {
            $failures[] = 'manifest lock_hash is missing or malformed';
        }
        if (($meta['upgrade_protocol'] ?? 0) !== 1) {
            $failures[] = 'manifest upgrade_protocol must be 1';
        }
        if (($meta['php_version'] ?? '') === '') {
            $failures[] = 'manifest php_version missing';
        }
        if (!is_array($meta['files'] ?? null) || $meta['files'] === []) {
            $failures[] = 'manifest files map is missing or empty';
        }
    }

    // Payload vs manifest: every file present, hash-matching, nothing extra.
    if ($manifest !== null && $zipPath !== '' && isset($extractDirs[$zipPath]) && $id !== null) {
        $failures = [...$failures, ...check_release_payload($zipPath, $extractDirs[$zipPath], $id, $manifest, $development)];
    }

    // Extracted layout + upgrade payload equality + PHP syntax.
    if ($zipPath !== '' && $upgradePath !== '' && isset($extractDirs[$zipPath], $extractDirs[$upgradePath]) && $id !== null) {
        $installRelease = $extractDirs[$zipPath] . '/site/releases/' . $id;
        $upgradeRelease = $extractDirs[$upgradePath];
        $diff = check_release_tree_diff($installRelease, $upgradeRelease);
        foreach ($diff as $line) {
            $failures[] = "upgrade payload differs from the install release tree: {$line}";
        }
        $failures = [...$failures, ...check_release_php_lint($installRelease)];
    } elseif ($zipPath !== '' && isset($extractDirs[$zipPath]) && $id !== null) {
        $failures = [...$failures, ...check_release_php_lint($extractDirs[$zipPath] . '/site/releases/' . $id)];
    } elseif ($upgradePath !== '' && isset($extractDirs[$upgradePath])) {
        $failures = [...$failures, ...check_release_php_lint($extractDirs[$upgradePath])];
    }

    foreach ($extractDirs as $dir) {
        check_release_rrmdir($dir);
    }
    return ['ok' => $failures === [], 'failures' => $failures, 'development' => $development, 'id' => $id];
}

/**
 * One artifact: digest sidecars, entry hygiene, manifest retrieval, required
 * files. @return array{0:list<string>,1:?string,2:?string,3:?string} failures, manifest bytes, id, extract dir
 */
function check_release_zip(string $zipPath, string $manifestSidecarSuffix, string $expectedDigest, bool $install): array
{
    $failures = [];
    $label = basename($zipPath);
    if (!is_file($zipPath)) {
        return [["{$label}: artifact missing"], null, null, null];
    }

    $actual = hash_file('sha256', $zipPath);
    $sidecar = is_file($zipPath . '.sha256') ? trim((string) file_get_contents($zipPath . '.sha256')) : null;
    if ($sidecar === null) {
        $failures[] = "{$label}: missing whole-ZIP checksum sidecar {$label}.sha256";
    } elseif (!hash_equals((string) $sidecar, (string) $actual)) {
        $failures[] = "{$label}: whole-ZIP checksum mismatch (artifact altered or corrupted)";
    }
    if ($expectedDigest !== '' && !hash_equals(strtolower($expectedDigest), (string) $actual)) {
        $failures[] = "{$label}: does not match the expected published digest";
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
        $failures[] = "{$label}: not a readable ZIP archive";
        return [$failures, null, null, null];
    }

    $manifest = null;
    $id = null;
    $required = $install
        ? ['site/current', 'site/bin/kip',
           'site/public/index.php', 'site/public/setup.php', 'site/public/upgrade.php', 'site/public/.htaccess',
           'public_html/index.php', 'public_html/setup.php', 'public_html/upgrade.php', 'public_html/.htaccess',
           ...array_map(static fn (string $f): string => 'site/upgrade/' . $f, CHECK_RELEASE_ENGINE_FILES)]
        : ['RELEASE.json', 'config.php', 'composer.json', 'composer.lock', 'bin/kip',
           'vendor/autoload.php', 'vendor/composer/installed.json', 'public/index.php', 'public/.htaccess'];
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $entries[$name] = $i;
        if (preg_match('/^[\/\\\\]|(^|\/)\.\.($|\/)|\\\\|\x00/', $name)) {
            $failures[] = "{$label}: unsafe entry path {$name}";
        }
        $opsys = 0;
        $attr = 0;
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX
            && (($attr >> 16) & 0xF000) === 0xA000) {
            $failures[] = "{$label}: entry {$name} is a symlink";
        }
        if (preg_match('/\.sqlite(-wal|-shm)?$|\.log$|phpunit\.xml$|setup-token|deployment\.php|setup-complete\.lock|(^|\/)(\.git|tests|scripts|docs|changelog\.d)(\/|$)|^app\/Features\/[^\/]+\/Tests(\/|$)|DBMAP\.md|SKINS\.md|TODOS\.md/', $name)
            // vendor packages may legitimately ship their own tests/docs/changelogs;
            // the allowlist below still pins the release root.
            && !str_starts_with($name, 'vendor/') && !str_contains($name, '/vendor/')) {
            $failures[] = "{$label}: forbidden entry {$name}";
        }
        // site/upgrade/ is the one sanctioned "upgrade" path (the stable
        // engine); an upgrade directory anywhere else, including inside the
        // release payload, is development clutter that must never ship.
        if (preg_match('/(^|\/)upgrade(\/|$)/', $name) && !str_starts_with($name, 'site/upgrade/')) {
            $failures[] = "{$label}: forbidden entry {$name}";
        }
        // The engine is flat, plain PHP files: no nested trees, no data.
        if (str_starts_with($name, 'site/upgrade/') && preg_match('/^site\/upgrade\/[A-Za-z0-9._-]+\.php$/', $name) !== 1) {
            $failures[] = "{$label}: the stable engine carries flat PHP files only: {$name}";
        }
    }
    foreach ($required as $need) {
        if (!isset($entries[$need])) {
            $failures[] = "{$label}: missing required entry {$need}";
        }
    }

    $releasePrefix = null;
    if ($install) {
        $raw = isset($entries['site/current']) ? (string) $zip->getFromIndex($entries['site/current']) : '';
        $id = trim($raw);
        if (preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
            $failures[] = "{$label}: site/current is not a 64-hex release ID";
            $id = null;
        } else {
            $releasePrefix = 'site/releases/' . $id . '/';
            $allowedTop = ['site/', 'public_html/'];
            foreach (array_keys($entries) as $name) {
                if (!str_starts_with($name, 'site/') && !str_starts_with($name, 'public_html/')) {
                    $failures[] = "{$label}: unexpected top-level entry {$name}";
                } elseif (str_starts_with($name, 'site/releases/') && !str_starts_with($name, $releasePrefix)) {
                    $failures[] = "{$label}: entry outside the active release: {$name}";
                }
            }
        }
        $manifestIndex = $releasePrefix !== null ? ($entries[$releasePrefix . 'RELEASE.json'] ?? null) : null;
    } else {
        foreach (array_keys($entries) as $name) {
            $top = explode('/', $name, 2)[0];
            if (!in_array($top, ['app', 'bin', 'public', 'vendor', 'config.php', 'composer.json', 'composer.lock', 'README.md', 'INSTALL.md', 'LICENSE', 'RELEASE.json'], true)) {
                $failures[] = "{$label}: unexpected release-root entry {$name}";
            }
        }
        $manifestIndex = $entries['RELEASE.json'] ?? null;
    }

    if ($manifestIndex !== null) {
        $manifest = (string) $zip->getFromIndex($manifestIndex);
        $digest = hash('sha256', $manifest);
        if ($id !== null && !hash_equals($id, $digest)) {
            $failures[] = "{$label}: RELEASE.json digest does not equal the release ID";
        }
        $sidecarManifest = is_file($zipPath . $manifestSidecarSuffix)
            ? trim((string) file_get_contents($zipPath . $manifestSidecarSuffix))
            : null;
        if ($sidecarManifest === null) {
            $failures[] = "{$label}: missing manifest digest sidecar";
        } elseif ($id !== null && !hash_equals($sidecarManifest, $digest)) {
            $failures[] = "{$label}: manifest digest sidecar does not match RELEASE.json";
        }
    } elseif ($install) {
        $failures[] = "{$label}: RELEASE.json missing from the release directory";
    }

    // Extract for layout, equality, and lint checks; clean up in the caller.
    $extract = sys_get_temp_dir() . '/kiption-check-' . bin2hex(random_bytes(6));
    if (!$zip->extractTo($extract)) {
        $failures[] = "{$label}: archive failed to extract";
        $extract = null;
    }
    $zip->close();
    return [$failures, $manifest, $id, $extract];
}

/** Payload files vs the manifest map: completeness and byte hashes. */
function check_release_payload(string $label, string $extractDir, string $id, string $manifest, bool $development): array
{
    $failures = [];
    $meta = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
    $map = $meta['files'] ?? [];
    $prefix = $extractDir . '/site/releases/' . $id . '/';
    if (!is_dir($prefix)) {
        return ["{$label}: extracted layout lacks site/releases/{$id}/"];
    }
    $actual = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($prefix, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME)
    );
    foreach ($it as $path) {
        $rel = ltrim(substr((string) $path, strlen($prefix)), '/');
        if ($rel === 'RELEASE.json') {
            continue;
        }
        $actual[$rel] = hash_file('sha256', (string) $path);
    }
    ksort($actual);
    $expected = $map;
    ksort($expected);
    foreach (array_diff_key($actual, $expected) as $rel => $unused) {
        $failures[] = "{$label}: payload file not covered by the manifest: {$rel}";
    }
    foreach (array_diff_key($expected, $actual) as $rel => $unused) {
        $failures[] = "{$label}: manifest lists a missing payload file: {$rel}";
    }
    foreach (array_intersect_key($actual, $expected) as $rel => $hash) {
        if (!hash_equals((string) $expected[$rel], (string) $hash)) {
            $failures[] = "{$label}: payload file hash mismatch: {$rel}";
        }
    }

    // The locked framework, resolved from the extracted metadata.
    $installed = @json_decode((string) @file_get_contents($prefix . 'vendor/composer/installed.json'), true);
    if (!is_array($installed)) {
        $failures[] = "{$label}: vendor/composer/installed.json missing or unreadable";
    } else {
        $packages = $installed['packages'] ?? $installed;
        $framework = array_values(array_filter($packages, static fn (array $p): bool => ($p['name'] ?? '') === 'kip/framework'));
        if (count($framework) !== 1) {
            $failures[] = "{$label}: expected exactly one locked kip/framework package";
        } else {
            // The dist-installed package composer.json carries no version
            // field; installed.json is the built metadata that does.
            $bundledVersion = (string) ($framework[0]['version'] ?? '');
            $path = (string) $framework[0]['install-path'];
            if (str_starts_with($path, '/')) {
                $path = ltrim(substr($path, strlen($prefix . 'vendor/composer/')), '/');
            }
            $resolved = realpath($prefix . 'vendor/composer/' . $path);
            $vendorRoot = realpath($prefix . 'vendor');
            if (!is_string($resolved) || !is_string($vendorRoot) || !str_starts_with($resolved, $vendorRoot . '/') || !is_file($resolved . '/composer.json')) {
                $failures[] = "{$label}: locked Kip package missing from vendor/";
            } else {
                $lock = @json_decode((string) @file_get_contents($prefix . 'composer.lock'), true);
                $lockedVersion = null;
                foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $p) {
                    if (($p['name'] ?? '') === 'kip/framework') {
                        $lockedVersion = (string) ($p['version'] ?? '');
                    }
                }
                if ($lockedVersion !== '' && $bundledVersion !== $lockedVersion) {
                    $failures[] = "{$label}: bundled kip/framework does not match composer.lock";
                }
            }
        }
    }

    // A release cannot install without schema.
    $migrations = glob($prefix . 'app/migrations/*.php') ?: [];
    if ($migrations === []) {
        $failures[] = "{$label}: the release carries no app/migrations/*.php";
    }

    // No absolute build-host paths in any code or config payload. Markdown
    // is exempt: operator docs carry example paths (/home/USER/...) by design.
    foreach ($actual as $rel => $unused) {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, ['php', 'json', 'css', 'js', 'txt', 'html'], true) && !str_ends_with($rel, '/.htaccess')) {
            continue;
        }
        $bytes = (string) @file_get_contents($prefix . $rel);
        if (preg_match('#(?<![\w.])/(Users|home)/[A-Za-z0-9_.-]+/#', $bytes)) {
            $failures[] = "{$label}: absolute host path inside {$rel}";
        }
    }
    return $failures;
}

/** @return list<string> differences (paths only) between the two trees */
function check_release_tree_diff(string $a, string $b): array
{
    $flatten = static function (string $root) use (&$flatten): array {
        $out = [];
        foreach (scandir($root) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $root . '/' . $e;
            if (is_dir($p)) {
                foreach ($flatten($p, $flatten) as $rel => $hash) {
                    $out[$e . '/' . $rel] = $hash;
                }
            } else {
                $out[$e] = hash_file('sha256', $p);
            }
        }
        return $out;
    };
    $ta = $flatten($a);
    $tb = $flatten($b);
    $diff = [];
    foreach (array_keys(array_diff_key($ta, $tb)) as $rel) {
        $diff[] = "{$rel} only in the install ZIP";
    }
    foreach (array_keys(array_diff_key($tb, $ta)) as $rel) {
        $diff[] = "{$rel} only in the upgrade ZIP";
    }
    foreach (array_intersect_key($ta, $tb) as $rel => $hash) {
        if (!hash_equals((string) $tb[$rel], (string) $hash)) {
            $diff[] = "{$rel} bytes differ";
        }
    }
    return $diff;
}

/** php -l over every extracted PHP file (dev-side; uses find/xargs batching). */
function check_release_php_lint(string $dir): array
{
    $cmd = sprintf('find %s -name "*.php" -type f -print0 | xargs -0 -n 1 -P 4 %s -l >/dev/null 2>%s',
        escapeshellarg($dir), escapeshellarg(PHP_BINARY), escapeshellarg($dir . '/-lint-errors'));
    exec($cmd, $ignored, $code);
    $failures = [];
    if ($code !== 0) {
        $detail = @file_get_contents($dir . '/-lint-errors') ?: '';
        $failures[] = "PHP syntax check failed:\n" . implode("\n", array_slice(explode("\n", $detail), 0, 10));
    }
    @unlink($dir . '/-lint-errors');
    return $failures;
}

function check_release_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) && !is_link($p) ? check_release_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/** @return int exit code */
function check_release_main(array $args): int
{
    $o = ['zip' => '', 'upgrade_zip' => '', 'development' => false, 'expected_install_digest' => '', 'expected_upgrade_digest' => ''];
    foreach ($args as $arg) {
        if ($arg === '--development') {
            $o['development'] = true;
        } elseif (str_starts_with($arg, '--zip=')) {
            $o['zip'] = substr($arg, 6);
        } elseif (str_starts_with($arg, '--upgrade-zip=')) {
            $o['upgrade_zip'] = substr($arg, 14);
        } elseif (str_starts_with($arg, '--expected-install-digest=')) {
            $o['expected_install_digest'] = strtolower(substr($arg, 26));
        } elseif (str_starts_with($arg, '--expected-upgrade-digest=')) {
            $o['expected_upgrade_digest'] = strtolower(substr($arg, 26));
        } else {
            check_release_fail_out("Unknown argument: {$arg}");
        }
    }
    $r = check_release($o);
    foreach ($r['failures'] as $f) {
        echo "FAIL: {$f}\n";
    }
    if ($r['ok']) {
        echo $r['development']
            ? "Release check passed (development bundle; not a production release).\n"
            : "Release check passed.\n";
        return 0;
    }
    echo count($r['failures']) . " failure(s).\n";
    return 1;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    exit(check_release_main(array_slice($_SERVER['argv'], 1)));
}
