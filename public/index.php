<?php // skeleton/public/index.php
/*
Request flow through the front controller:

request
  |
  v
public/index.php
  |-- config.php  (maintenance = lock file OR env)
  |-- Request::fromGlobals(trustedProxy)
  |-- MaintenanceGuard::blocks(config, path)?
  |      |-- yes --> render app/views/maintenance.php --> Response 503 --> send --> exit
  |      \-- no  --> new Kip\App(config, lazy session)
  |                    |-- App::handle(request)
  |                    |     |-- route to App\Controllers\*
  |                    |     \-- Response (200/404/...)
  |                    \-- send
*/
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';
$config['views'] = $config['app_dir'] . '/views';

$request = Kip\Http\Request::fromGlobals(trustedProxy: $config['trusted_proxy']);

if (\App\MaintenanceGuard::blocks($config, $request->path)) {
    $body = (new Kip\View($config['views']))->render('maintenance');
    (new Kip\Http\Response($body, 503))->send();
    exit;
}

ob_start(); // lazy session may start mid-render; nothing may flush before headers

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($config['trusted_proxy'] && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

$app = new Kip\App($config, Kip\Session::lazy(new Kip\SessionStarter($https)));
$app->handle($request)->send();
ob_end_flush();
