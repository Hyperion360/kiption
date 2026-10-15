<?php // upgrade/stable/docroot-upgrade.php
declare(strict_types=1);
/*
 * Stable upgrade entry for the fixed-docroot layout (the host's
 * public_html is the document root; the private site/ tree sits beside
 * it). Loads ONLY the stable private engine at site/upgrade/, never the
 * active release's code, so the upgrade endpoint answers even when either
 * release cannot boot. Any missing engine piece is a generic 503 that
 * never prints a filesystem path. Self-contained by design: each stable
 * file must stay independently uploadable by file manager during the rare
 * maintenance that touches it.
 */
$fail = static function (): never {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo "Service unavailable\n";
    exit;
};

$site = dirname(__DIR__) . '/site';
$controller = $site . '/upgrade/UpgradeController.php';
if (!is_file($controller)) {
    $fail();
}
require_once $controller;

session_start(['cookie_secure' => true, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_path' => '/']);
$active = \Upgrade\ReleasePointer::active($site);
$result = \Upgrade\UpgradeController::handleWeb($_SERVER, $_POST, $_SESSION, [
    'site_root' => $site,
    'shared_dir' => $site . '/shared',
    'source_root' => $active !== null ? $site . '/releases/' . $active : '',
    'public_dir' => __DIR__,
]);
http_response_code($result['status']);
foreach ($result['headers'] as $header) {
    header($header);
}
echo $result['body'];
