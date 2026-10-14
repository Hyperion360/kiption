<?php // upgrade/stable/docroot-setup.php
declare(strict_types=1);
/*
 * Stable setup entry for the fixed public_html layout (site/ uploaded as a
 * sibling of the document root): resolves the active release exactly like
 * the stable index wrapper, then hands off to that release's public/
 * setup.php. Generic 503 on any resolution problem; never prints a
 * filesystem path. Self-contained by design: each stable file must stay
 * independently uploadable by file manager during the rare maintenance that
 * touches it.
 */
$fail = static function (): never {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo "Service unavailable\n";
    exit;
};

$site = dirname(__DIR__) . '/site'; // site/ is uploaded as a sibling of public_html/
$id = trim((string) @file_get_contents($site . '/current'));
if (preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
    $fail();
}
$releases = realpath($site . '/releases');
$root = is_string($releases) ? realpath($releases . '/' . $id) : false;
if (!is_string($root) || !str_starts_with($root, $releases . '/')
    || !is_file($root . '/public/setup.php')) {
    $fail();
}

define('KIPTION_ROOT', $root);
define('KIPTION_SHARED_DIR', $site . '/shared');
define('KIPTION_PUBLIC_DIR', __DIR__);
require $root . '/public/setup.php';
