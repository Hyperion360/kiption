<?php // scripts/build-release.php
declare(strict_types=1);

/**
 * The release builder: one locked-Composer build, two artifacts.
 *
 *  - Install ZIP: site/releases/<id>/ (the versioned payload), the stable
 *    site/public/ entry, the stable site/bin/kip launcher, the fixed-docroot
 *    public_html/, an empty site/shared/, and a plain-text site/current.
 *  - Upgrade ZIP: the same release payload directly at the archive root, no
 *    site/ prefix, ready to extract as the next site/releases/<id>/.
 *
 * The canonical RELEASE.json (version, Git SHA, lock hash, build PHP,
 * upgrade_protocol, per-file hashes) is written into the release root with no
 * self-hash and no ID; the SHA-256 of its exact bytes IS the release ID. The
 * builder runs on the release owner's machine against a clean checkout; it
 * never reads host secrets and never talks to a host.
 *
 * Development builds (--development) stamp development_bundle: true and may
 * precede the later stable entry points; the checker refuses them as
 * production and publication never treats them as a release.
 */

const RELEASE_FILES_TIMESTAMP = 1704067200; // fixed mtime written into both archives

/** Paths (relative to the source checkout) that never enter the payload:
 *  operator data, generated caches, and development clutter. */
const RELEASE_EXCLUDED_PATHS = [
    'public/cache/',
    'public/uploads/',
    'app/backups/',
    'app/nav.json',
    'app/maintenance.lock',
];

/** File names that never enter the payload wherever they appear. */
function build_release_excluded_name(string $name): bool
{
    return (bool) preg_match(
        '/\.sqlite$|\.sqlite-wal$|\.sqlite-shm$|\.log$|^\.phpunit\.result\.cache$|^\.DS_Store$|^sitemap.*\.xml$|^run\.lock$|^maintenance\.lock$/',
        $name
    );
}

/** @return never */
function build_release_fail(string $message): void
{
    fwrite(STDERR, "build-release: {$message}\n");
    exit(1);
}

/** @return array{development:bool,allow_dirty:bool,version:string,git_sha:string,output:string,upgrade_output:string} */
function build_release_options(array $args): array
{
    $o = ['development' => false, 'allow_dirty' => false, 'version' => '', 'git_sha' => '', 'output' => '', 'upgrade_output' => ''];
    foreach ($args as $arg) {
        if ($arg === '--development') {
            $o['development'] = true;
        } elseif ($arg === '--allow-dirty') {
            $o['allow_dirty'] = true;
        } elseif (str_starts_with($arg, '--version=')) {
            $o['version'] = substr($arg, 10);
        } elseif (str_starts_with($arg, '--git-sha=')) {
            $o['git_sha'] = substr($arg, 10);
        } elseif (str_starts_with($arg, '--output=')) {
            $o['output'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--upgrade-output=')) {
            $o['upgrade_output'] = substr($arg, 17);
        } else {
            build_release_fail("Unknown argument: {$arg}");
        }
    }
    return $o;
}

/**
 * Recursive copier for the versioned payload: refuses symlinks outright and
 * drops operator data, caches, and development artifacts on the way. The
 * forbidden-name rules here and in check-release.php must agree.
 */
function build_release_copy(string $src, string $dst, string $rel = ''): void
{
    if (!mkdir($dst, 0775, true) && !is_dir($dst)) {
        build_release_fail("cannot create {$dst}");
    }
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $childRel = $rel === '' ? $e . '/' : $rel . $e . '/';
        if (in_array($childRel, RELEASE_EXCLUDED_PATHS, true)
            || in_array(rtrim($childRel, '/'), RELEASE_EXCLUDED_PATHS, true)
            // Feature folders carry their own PHPUnit suites; development-only.
            // The copier runs rooted at app/ for the payload and at the release
            // root for the install tree, so both path forms match.
            || preg_match('#^(?:app/)?Features/[^/]+/Tests(/|$)#', $childRel) === 1
            || build_release_excluded_name($e)) {
            continue;
        }
        $s = $src . '/' . $e;
        if (is_link($s)) {
            build_release_fail("refusing symlink in source tree: {$rel}{$e}");
        }
        if (is_dir($s)) {
            build_release_copy($s, $dst . '/' . $e, $childRel);
        } elseif (is_file($s)) {
            if (!copy($s, $dst . '/' . $e)) {
                build_release_fail("cannot copy {$rel}{$e}");
            }
            @chmod($dst . '/' . $e, 0644);
        }
    }
}

function build_release_hash_tree(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME)
    );
    foreach ($it as $path) {
        $rel = ltrim(substr((string) $path, strlen($root)), '/');
        $files[$rel] = hash_file('sha256', (string) $path);
    }
    ksort($files);
    return $files;
}

/** Adds every file under $treeRoot to the archive, sorted, fixed mtimes. */
function build_release_add_tree(ZipArchive $zip, string $treeRoot): void
{
    $paths = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($treeRoot, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME)
    );
    foreach ($it as $path) {
        $paths[] = ltrim(substr((string) $path, strlen($treeRoot)), '/');
    }
    sort($paths, SORT_STRING);
    foreach ($paths as $rel) {
        if (!$zip->addFile($treeRoot . '/' . $rel, $rel)) {
            build_release_fail("cannot add {$rel} to the archive");
        }
    }
    if (method_exists($zip, 'setMtimeIndex')) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zip->setMtimeIndex($i, RELEASE_FILES_TIMESTAMP);
        }
    }
}

function build_release_write_zip(string $zipPath, string $treeRoot, array $emptyDirs = []): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        build_release_fail("cannot write {$zipPath}");
    }
    foreach ($emptyDirs as $dir) {
        $zip->addEmptyDir($dir);
    }
    build_release_add_tree($zip, $treeRoot);
    $zip->close();
}

/** Runs composer install inside a directory; the builder is dev-side. */
function build_release_composer(string $dir): void
{
    $binary = (string) getenv('COMPOSER_BINARY');
    if ($binary === '' || !is_executable($binary)) {
        foreach (['/opt/homebrew/bin/composer', '/usr/local/bin/composer', '/usr/bin/composer'] as $candidate) {
            if (is_executable($candidate)) {
                $binary = $candidate;
                break;
            }
        }
    }
    if ($binary === '') {
        $binary = 'composer';
    }
    $cmd = sprintf('cd %s && %s install --no-dev --prefer-dist --classmap-authoritative --no-interaction 2>&1',
        escapeshellarg($dir), escapeshellarg($binary));
    exec($cmd, $out, $code);
    if ($code !== 0) {
        build_release_fail("composer install failed:\n" . implode("\n", array_slice($out, -15)));
    }
    if (!is_file($dir . '/vendor/autoload.php')) {
        build_release_fail('composer install produced no vendor/autoload.php');
    }
}

/** @return array{0:string,1:string} id, manifest bytes */
function build_release_manifest(string $stage, string $version, string $sha, bool $development): array
{
    $files = build_release_hash_tree($stage);
    $payload = [
        'version' => $version,
        'git_sha' => $sha,
        'lock_hash' => hash_file('sha256', $stage . '/composer.lock'),
        'php_version' => PHP_VERSION,
        'upgrade_protocol' => 1,
        'files' => $files,
    ];
    if ($development) {
        $payload['development_bundle'] = true;
    }
    $manifest = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    return [hash('sha256', $manifest), $manifest];
}

/** The locked framework, resolved from built metadata, verified inside vendor/. */
function build_release_verify_framework(string $releaseRoot): void
{
    $installed = json_decode((string) file_get_contents($releaseRoot . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $packages = $installed['packages'] ?? $installed;
    $framework = array_values(array_filter($packages, static fn (array $p): bool => ($p['name'] ?? '') === 'kip/framework'));
    if (count($framework) !== 1) {
        build_release_fail('Expected one locked Kip package');
    }
    $path = $framework[0]['install-path'] ?? '';
    if (str_starts_with((string) $path, '/')) {
        $path = ltrim(substr((string) $path, strlen($releaseRoot . '/vendor/composer/')), '/'); // normalize absolute form
    }
    $resolved = realpath($releaseRoot . '/vendor/composer/' . $path);
    $vendorRoot = realpath($releaseRoot . '/vendor');
    if (!is_string($resolved) || !is_string($vendorRoot) || !str_starts_with($resolved, $vendorRoot . '/') || !is_file($resolved . '/composer.json')) {
        build_release_fail('Locked Kip package missing from release');
    }
}

/** @return int exit code */
function build_release_main(array $args, string $sourceRoot): int
{
    $o = build_release_options($args);
    if (!preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/', $o['version'])) {
        build_release_fail('give an explicit release version: --version=MAJOR.MINOR.PATCH[-suffix]');
    }
    if ($o['output'] === '' && $o['upgrade_output'] === '') {
        build_release_fail('give at least one artifact path: --output=<install.zip> [--upgrade-output=<upgrade.zip>]');
    }
    if (!is_file($sourceRoot . '/config.php') || !is_file($sourceRoot . '/composer.lock')) {
        build_release_fail('run the builder from a Kiption source checkout (config.php and composer.lock not found)');
    }

    // Dirty-tree guard: production artifacts build from a clean checkout.
    exec('git -C ' . escapeshellarg($sourceRoot) . ' status --porcelain 2>&1', $status, $statusCode);
    if (($statusCode !== 0 || $status !== []) && !$o['allow_dirty']) {
        build_release_fail('the source tree is dirty or not a git checkout; commit or stash, or pass --allow-dirty for a test build');
    }

    // Provenance: the source Git SHA. The version tag is cut later, at
    // publication, against this exact SHA.
    $sha = $o['git_sha'];
    if ($sha === '') {
        exec('git -C ' . escapeshellarg($sourceRoot) . ' rev-parse HEAD 2>&1', $shaOut, $shaCode);
        if ($shaCode !== 0) {
            build_release_fail('cannot read the source Git SHA; build from a checkout or pass --git-sha=<sha> (test builds)');
        }
        $sha = trim(implode("\n", $shaOut));
    }
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
        build_release_fail('--git-sha must be a 40-hex commit SHA');
    }

    // Stage the allowlisted versioned payload, then install the locked graph
    // into it fresh: the release vendor tree comes from composer.lock alone.
    $stage = sys_get_temp_dir() . '/kiption-build-' . bin2hex(random_bytes(6));
    foreach (['app', 'bin', 'public'] as $dir) {
        if (!is_dir($sourceRoot . '/' . $dir)) {
            build_release_fail("missing required source directory: {$dir}");
        }
        // rel carries the directory prefix so RELEASE_EXCLUDED_PATHS (which
        // name paths from the release root, e.g. app/backups/) match this
        // app-rooted copy the same way they match the install-tree copy;
        // without it, runtime strays (nav.json, backups/) sail into the
        // stage, the manifest, and the upgrade ZIP while the install tree
        // excludes them: three artifacts disagreeing about one payload.
        build_release_copy($sourceRoot . '/' . $dir, $stage . '/' . $dir, $dir . '/');
    }
    foreach (['config.php', 'composer.json', 'composer.lock', 'README.md', 'INSTALL.md', 'LICENSE'] as $file) {
        if (!is_file($sourceRoot . '/' . $file)) {
            build_release_fail("missing required source file: {$file}");
        }
        copy($sourceRoot . '/' . $file, $stage . '/' . $file);
    }
    build_release_composer($stage);
    build_release_verify_framework($stage);

    [$id, $manifest] = build_release_manifest($stage, $o['version'], $sha, $o['development']);
    file_put_contents($stage . '/RELEASE.json', $manifest);

    // Stable entry sources (never in the release root, never in the upgrade ZIP).
    // upgrade/*.php at the repo root is the stable upgrade engine, installed
    // as site/upgrade/; dispatch.php is the CLI launcher and installs as
    // site/bin/kip instead. The stable wrappers map one-to-one onto the two
    // web roots.
    $stable = dirname(__DIR__) . '/upgrade';
    $engineFiles = array_map('basename', array_filter(glob($stable . '/*.php') ?: [], 'is_file'));
    $engineFiles = array_values(array_diff($engineFiles, ['dispatch.php']));
    if ($engineFiles === []) {
        build_release_fail('missing stable engine sources: upgrade/*.php');
    }
    foreach (['dispatch.php', 'stable/public-index.php', 'stable/docroot-index.php',
        'stable/public-setup.php', 'stable/docroot-setup.php',
        'stable/public-upgrade.php', 'stable/docroot-upgrade.php'] as $src) {
        if (!is_file($stable . '/' . $src)) {
            build_release_fail("missing stable wrapper source: upgrade/{$src}");
        }
    }

    if ($o['upgrade_output'] !== '') {
        $upgradeDir = dirname($o['upgrade_output']);
        if ($upgradeDir !== '' && !is_dir($upgradeDir)) {
            mkdir($upgradeDir, 0775, true) || build_release_fail("cannot create {$upgradeDir}");
        }
        build_release_write_zip($o['upgrade_output'], $stage);
        file_put_contents($o['upgrade_output'] . '.sha256', hash_file('sha256', $o['upgrade_output']) . "\n");
        file_put_contents($o['upgrade_output'] . '.manifest.sha256', $id . "\n");
    }

    if ($o['output'] !== '') {
        $tree = sys_get_temp_dir() . '/kiption-ziproot-' . bin2hex(random_bytes(6));
        $release = $tree . '/site/releases/' . $id;
        build_release_copy($stage, $release);
        file_put_contents($tree . '/site/current', $id . "\n");
        mkdir($tree . '/site/shared', 0775, true);
        // The stable web roots carry only the wrappers, the routing rules,
        // and the assets; application code stays private in the release.
        foreach (['site/public', 'public_html'] as $web) {
            mkdir($tree . '/' . $web, 0775, true);
            copy($stable . '/stable/' . ($web === 'site/public' ? 'public-index.php' : 'docroot-index.php'), $tree . '/' . $web . '/index.php');
            copy($stable . '/stable/' . ($web === 'site/public' ? 'public-setup.php' : 'docroot-setup.php'), $tree . '/' . $web . '/setup.php');
            copy($stable . '/stable/' . ($web === 'site/public' ? 'public-upgrade.php' : 'docroot-upgrade.php'), $tree . '/' . $web . '/upgrade.php');
            copy($sourceRoot . '/public/.htaccess', $tree . '/' . $web . '/.htaccess');
            build_release_copy($sourceRoot . '/public/assets', $tree . '/' . $web . '/assets', 'public/assets/');
        }
        mkdir($tree . '/site/bin', 0775, true);
        copy($stable . '/dispatch.php', $tree . '/site/bin/kip');
        @chmod($tree . '/site/bin/kip', 0755);
        // The stable upgrade engine sits beside the releases it coordinates,
        // outside every web root and outside site/releases/.
        mkdir($tree . '/site/upgrade', 0775, true);
        foreach ($engineFiles as $name) {
            copy($stable . '/' . $name, $tree . '/site/upgrade/' . $name);
        }
        if (!is_dir(dirname($o['output']))) {
            mkdir(dirname($o['output']), 0775, true) || build_release_fail('cannot create the output directory');
        }
        build_release_write_zip($o['output'], $tree, ['site/shared/']);
        file_put_contents($o['output'] . '.sha256', hash_file('sha256', $o['output']) . "\n");
        self_rrmdir($tree);
        file_put_contents($o['output'] . '.manifest.sha256', $id . "\n");
    }

    self_rrmdir($stage);
    echo json_encode([
        'release_id' => $id,
        'version' => $o['version'],
        'git_sha' => $sha,
        'development' => $o['development'],
        'install_zip' => $o['output'],
        'upgrade_zip' => $o['upgrade_output'],
    ], JSON_UNESCAPED_SLASHES) . "\n";
    return 0;
}

function self_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) && !is_link($p) ? self_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    exit(build_release_main(array_slice($_SERVER['argv'], 1), getcwd() ?: '.'));
}
