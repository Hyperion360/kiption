<?php // skeleton/public/index.php
/*
Request flow through the front controller:

request
  |
  v
public/index.php
  |-- config.php  (maintenance = lock file OR env; static_cache dir)
  |-- Request::fromGlobals(trustedProxy)
  |-- MaintenanceGuard::blocks(config, path)?
  |      |-- yes --> static cache maintenancePurge(true) --> 503 view --> exit
  |      \-- no  --> static cache maintenancePurge(false)
  |-- static cache serve(request)?  (GET, no cookies, no query, whitelisted path)
  |      |-- HIT  --> send --> exit            (App never boots)
  |      \-- miss --> new Kip\App(config, lazy session)
  |                     |-- App::handle(request)
  |                     |     |-- route to App\Controllers\*
  |                     |     \-- Response (200/404/...)
  |                     |-- static cache maybeStore(request, response)  (anonymous 200 only)
  |                     \-- send
*/
declare(strict_types=1);

// Dev-server passthrough (php -S router mode): real files serve directly, exactly as
// the production .htaccess RewriteCond !-f does. Dead code under Apache/FPM.
if (PHP_SAPI === 'cli-server') {
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $real = realpath(__DIR__ . $p);
    if ($real !== false && str_starts_with($real, __DIR__ . '/') && is_file($real)) return false;
}

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';
$config['views'] = $config['app_dir'] . '/views';

$request = Kip\Http\Request::fromGlobals(trustedProxy: $config['trusted_proxy']);

$static = ($config['static_cache']['enabled'] ?? false)
    ? new \App\StaticCache\Cache($config['static_cache']['dir'])
    : null;

if (\App\MaintenanceGuard::blocks($config, $request->path)) {
    $static?->maintenancePurge(true);
    $body = (new Kip\View($config['views']))->render('maintenance');
    (new Kip\Http\Response($body, 503))->send();
    exit;
}

if (!($config['maintenance'] ?? false)) {
    $static?->maintenancePurge(false);
}

if ($static !== null && ($hit = $static->serve($request)) !== null) {
    $hit->send();
    exit;
}

ob_start(); // lazy session may start mid-render; nothing may flush before headers

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($config['trusted_proxy'] && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

$app = new Kip\App($config, Kip\Session::lazy(new Kip\SessionStarter($https)));
$response = $app->handle($request);
if ($static !== null) {
    $static->maybeStore($request, $response);
}
$response->send();
ob_end_flush();
