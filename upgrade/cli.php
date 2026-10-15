<?php // upgrade/cli.php
declare(strict_types=1);
/*
 * The private PHP CLI upgrade entry, installed as site/upgrade/cli.php. It
 * runs prepare|activate|continue|recover|status with the same verifier,
 * lease, journal, and coordinator as the browser endpoint, so both paths
 * share one recovery story. Filesystem ownership is the authority: it
 * never asks for or prints a web password or a browser capability.
 *
 * A web hit is a generic 404 that executes nothing (and the engine
 * directory sits outside every web root in both supported layouts). The
 * SAPI guard is shared with the controller so tests can unit-check it.
 *
 * activate and continue are separate invocations on purpose: each is a
 * fresh PHP process, so source and target release classes never coexist.
 */

$guard = null;
$controller = __DIR__ . '/UpgradeController.php';
if (is_file($controller)) {
    require_once $controller;
    $guard = \Upgrade\UpgradeController::cliGuard(PHP_SAPI);
} elseif (PHP_SAPI !== 'cli') {
    $guard = 'the private upgrade CLI runs on the command line only';
}
if ($guard !== null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo "Not found\n";
    exit(1);
}
if (!is_file($controller)) {
    fwrite(STDERR, "the stable upgrade engine is missing from this install\n");
    exit(1);
}

// The site root: site/ in an installed release layout, the checkout root in
// flat development (where no upgrade is ever recorded, because the pointer
// and releases tree do not exist there).
$site = dirname(__DIR__);
$shared = is_dir($site . '/shared') ? $site . '/shared' : $site . '/app';
$active = \Upgrade\ReleasePointer::active($site);

exit(\Upgrade\UpgradeController::runCli(array_slice($argv, 1), [
    'site_root' => $site,
    'shared_dir' => $shared,
    'source_root' => $active !== null ? $site . '/releases/' . $active : '',
    'public_dir' => (string) (defined('KIPTION_PUBLIC_DIR') ? KIPTION_PUBLIC_DIR : ''),
]));
