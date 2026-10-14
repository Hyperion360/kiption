<?php // upgrade/stable/docroot-index.php
declare(strict_types=1);
/*
 * Stable web entry for the fixed public_html layout: this file sits in the
 * host's only document root, and the private site/ tree is its sibling.
 * Resolves the active release from site/current and hands the request to
 * that release's front controller. Any problem is a generic 503 that never
 * prints a filesystem path. Deliberately self-contained (not shared with
 * site/public/index.php): each stable file must stay independently
 * uploadable by file manager during the rare maintenance that touches it.
 */
$fail = static function (): never {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo "Service unavailable\n";
    exit;
};

$site = __DIR__ . '/site';
$id = trim((string) @file_get_contents($site . '/current'));
if (preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
    $fail();
}
$releases = realpath($site . '/releases');
$root = is_string($releases) ? realpath($releases . '/' . $id) : false;
if (!is_string($root) || !str_starts_with($root, $releases . '/')
    || !is_file($root . '/public/index.php')) {
    $fail();
}

define('KIPTION_ROOT', $root);
define('KIPTION_SHARED_DIR', $site . '/shared');
define('KIPTION_PUBLIC_DIR', __DIR__);
require $root . '/public/index.php';
