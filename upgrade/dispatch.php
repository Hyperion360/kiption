<?php // upgrade/dispatch.php
declare(strict_types=1);
/*
 * The stable Kiption CLI launcher, bundled as site/bin/kip. Resolves the
 * active release from the private site/current pointer, leases the shared
 * run lock, and dispatches to that release's bin/kip. It never loads
 * application code itself, so a broken release cannot break the launcher.
 *
 * Leasing (site/shared/run.lock, flock): ordinary commands hold a shared
 * lease, so cron and operator commands overlap freely but never run beside a
 * committed eFiction import. import:efiction --commit is the one writer: it
 * takes an EXCLUSIVE lease and sets the maintenance marker BEFORE dispatch,
 * and the marker is lifted only when the versioned import itself returns
 * success. A failed or killed import leaves maintenance in place for the
 * documented recovery path (INSTALL.md); a killed worker releases the OS
 * lock, so the next command is never blocked by a ghost.
 *
 * Refusals: serve (a long-lived server would hold a shared lease forever;
 * VPS operators run a supervised process outside this launcher), and every
 * ordinary command while maintenance is active (version and help stay
 * available for diagnosis).
 */

$site = dirname(dirname((string) realpath(__FILE__)));
$fail = static function (string $message): never {
    fwrite(STDERR, "kip: {$message}\n");
    exit(1);
};

$cmd = $argv[1] ?? 'help';
if ($cmd === 'serve') {
    $fail('serve is not available through the stable launcher; use a checkout for the development server, or a supervised process for production');
}

$shared = $site . '/shared';
$maintenance = $shared . '/maintenance.lock';

// import:efiction flags are settled before ANY lock, marker, or dispatch.
$importCommit = $importDry = false;
if ($cmd === 'import:efiction') {
    foreach (array_slice($argv, 2) as $a) {
        if ($a === '--commit') {
            if ($importCommit) {
                $fail('give --dry-run or --commit exactly once');
            }
            $importCommit = true;
        } elseif ($a === '--dry-run') {
            if ($importDry) {
                $fail('give --dry-run or --commit exactly once');
            }
            $importDry = true;
        }
    }
    if ($importCommit === $importDry) {
        $fail('usage: kip import:efiction <bundle.tar.gz> (--dry-run|--commit)');
    }
}

// Maintenance gate: refused BEFORE the release pointer or any application
// code is touched.
if (is_file($maintenance) && !in_array($cmd, ['import:efiction', 'version', 'help'], true)) {
    $fail('Kiption is in maintenance; see INSTALL.md for the recovery path');
}

// Resolve the pointer and the active release.
$id = trim((string) @file_get_contents($site . '/current'));
if (preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
    $fail('Kiption is not installed here or its release pointer is unreadable');
}
$releases = realpath($site . '/releases');
$root = is_string($releases) ? realpath($releases . '/' . $id) : false;
if (!is_string($root) || !str_starts_with($root, $releases . '/') || !is_file($root . '/bin/kip')) {
    $fail('the active Kiption release is missing; see INSTALL.md');
}

// The same seams the web wrappers define: the dispatched command boots the
// release's code against the shared private tree.
define('KIPTION_ROOT', $root);
define('KIPTION_SHARED_DIR', $shared);

// The lease. Existence of run.lock is never the lock; the OS lock is.
if (!is_dir($shared) && !@mkdir($shared, 0775, true)) {
    $fail('cannot create the shared data directory');
}
$lockHandle = @fopen($shared . '/run.lock', 'c');
if ($lockHandle === false) {
    $fail('cannot open the shared run lock');
}
$mode = $importCommit ? LOCK_EX : LOCK_SH;
$timeout = (float) (getenv('KIPTION_RUN_LOCK_TIMEOUT') ?: ($importCommit ? 0 : 30));
$held = flock($lockHandle, $mode | LOCK_NB);
$waited = 0.0;
while (!$held && $waited < $timeout) {
    usleep(200000);
    $waited += 0.2;
    $held = flock($lockHandle, $mode | LOCK_NB);
}
if (!$held) {
    $fail($importCommit
        ? 'another Kiption command holds the run lock; a committed import never waits one out'
        : 'another Kiption command holds the run lock; try again when it finishes');
}

// The committed import is the one maintenance-making command. It dispatches
// only after the marker exists, and only its success lifts the marker.
if ($importCommit && !@file_put_contents($maintenance, 'import:efiction ' . date('c'))) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    $fail('cannot set the maintenance marker');
}

// Re-check under the lease: the early check read the marker before an upgrade
// transition could acquire the lock, raise maintenance, and release.
if (is_file($maintenance) && !in_array($cmd, ['import:efiction', 'version', 'help'], true)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    $fail('Kiption entered maintenance while this command waited; see INSTALL.md for the recovery path');
}

require $root . '/bin/kip';
// Reaching this line means the versioned command returned success. bin/kip
// exits on its own for every failure path, so maintenance is lifted only
// after a successful committed import.
if ($importCommit) {
    @unlink($maintenance);
}
exit(0);
