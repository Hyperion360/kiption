<?php // public/upgrade.php
declare(strict_types=1);
/*
 * The upgrade endpoint, flat-development form. A versioned install never
 * serves this file: its stable web roots carry their own upgrade.php
 * wrappers (site/public/upgrade.php, public_html/upgrade.php) that load
 * the stable engine from site/upgrade/. This copy serves a flat checkout,
 * where the engine lives beside the source and no release pointer exists,
 * so prepare has nothing to anchor to and refuses cleanly. It fails closed
 * outside a checkout: a copy without the engine beside it is a generic
 * 503.
 */
$fail = static function (): never {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo "Service unavailable\n";
    exit;
};

$siteRoot = dirname(__DIR__);
$controller = dirname(__DIR__) . '/upgrade/UpgradeController.php';
if (!is_file($controller)) {
    $fail();
}
require_once $controller;

session_start(['cookie_secure' => true, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_path' => '/', 'use_strict_mode' => true]); // the app's own starter sets strict mode; a planted session id must not be adopted here
$shared = defined('KIPTION_SHARED_DIR') ? KIPTION_SHARED_DIR : $siteRoot . '/app';
$active = \Upgrade\ReleasePointer::active($siteRoot);
$root = defined('KIPTION_ROOT') ? KIPTION_ROOT : $siteRoot;
$result = \Upgrade\UpgradeController::handleWeb($_SERVER, $_POST, $_SESSION, [
    'site_root' => $siteRoot,
    'shared_dir' => $shared,
    'source_root' => $active !== null ? $siteRoot . '/releases/' . $active : (defined('KIPTION_ROOT') ? KIPTION_ROOT : ''),
    'public_dir' => defined('KIPTION_PUBLIC_DIR') ? KIPTION_PUBLIC_DIR : $siteRoot . '/public',
]);
http_response_code($result['status']);
foreach ($result['headers'] as $header) {
    header($header);
}
echo $result['body'];
