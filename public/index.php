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
  |      \-- miss --> legacy .php URL in the 301 map? --> Location 301 --> exit
  |                     \-- no --> new Kip\App(config, lazy session)
  |                                    |-- App::handle(request)
  |                                    |     |-- route to App\Features\{Feature}\*
  |                                    |     \-- Response (200/404/...)
  |                                    |-- static cache maybeStore(request, response)  (anonymous 200 only)
  |                                    \-- send
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
\App\Lang::setCurrent($config['ui_lang'] ?? 'en'); // every view's strings render through the pack layer

$request = Kip\Http\Request::fromGlobals(trustedProxy: $config['trusted_proxy']);

// Per-user UI language, the cookie-sync seam (phase 12d ruling 1): a validated
// 'lang' cookie (set by the login sync, cleared at logout) switches the pack
// before anything renders. Zero queries, and it sits before the static-cache
// serve whose cookieless rule already makes a cookied request uncacheable. A
// junk cookie keeps the config default: setCurrent would coerce it to en, which
// is only right when en IS the configured archive language.
// The flag consult is the Task-2 ruling: the seam runs before Features::init
// (moving it below would put the flags DB in front of the maintenance 503, a
// framework-level page that must never open it), so it consults ONLY the
// config-shipped peruserlang default. A config-off archive never applies the
// cookie; a runtime flag-off (DB row) cannot reach this high, which is the
// documented stale-cookie window: logins stop syncing immediately, a browser
// already carrying the cookie keeps its language until the next logout.
$cookieLang = $request->cookies['lang'] ?? '';
if (($config['features']['peruserlang'] ?? true)
    && is_string($cookieLang) && preg_match('/^[a-z]{2}$/', $cookieLang) === 1) {
    \App\Lang::setCurrent($cookieLang);
}

$static = ($config['static_cache']['enabled'] ?? false)
    ? new \App\StaticCache\Cache($config['static_cache']['dir'])
    : null;

if (\App\MaintenanceGuard::blocks($config, $request->path)) {
    $static?->maintenancePurge(true);
    \App\MaintenanceGuard::response($config)
        ->withHeader('Cache-Control', 'private, no-store')->send();
    exit;
}

if (!($config['maintenance'] ?? false)) {
    $static?->maintenancePurge(false);
}

if ($static !== null && ($hit = $static->serve($request)) !== null) {
    $hit->send();
    exit;
}

// Legacy eFiction URLs: consult the import's 301 map before routing. Only GET
// requests whose path ends in .php reach the database here; app pages are .php-free.
if ($request->method === 'GET' && preg_match('#\.php$#', $request->path)) {
    $target = (new \App\Import\LegacyRedirects())->lookup($request, new \Kip\Database($config['db']['dsn']));
    if ($target !== null) {
        header('Cache-Control: private, no-store');
        header('Location: ' . $target, true, 301);
        exit;
    }
}

ob_start(); // lazy session may start mid-render; nothing may flush before headers

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($config['trusted_proxy'] && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// Feature flags: resolve once per request, AFTER the static-cache HIT and legacy
// 301 exits above (a cached serve or a maintenance 503 never opens the flags DB)
// and BEFORE the app boots, so every controller and view shares one resolution.
\App\Features::init(new \Kip\Database($config['db']['dsn']), $config['features'] ?? []);

$app = new Kip\App($config, Kip\Session::lazy(new Kip\SessionStarter($https)));
$response = $app->handle($request);
if ($static !== null) {
    $static->maybeStore($request, $response);
}
// The shared-cache contract, decided where store eligibility is known: the one
// response shape a CDN may hold is the one this layer just stored (anonymous,
// 200, cookieless, queryless, whitelisted GET); everything else, including
// every render when the static cache is disabled outright, is private and
// uncacheable. A response that already carries Cache-Control (the reader
// fragment's private, no-store, the beacon's) is overwritten with the value
// its eligibility demands, which is always at least as strict.
$response = $response->withHeader('Cache-Control', $static?->cacheControlFor($request, $response) ?? 'private, no-store');
// Multi-cookie Set-Cookie needs no wire translation since the Kip 0.5 sync:
// Response carries list-valued headers and send() emits each leaf with append
// semantics, so the session cookie survives beside them natively.
$response->send();
ob_end_flush();
// Work queued with App::defer() runs after the response is out. Under PHP-FPM the
// connection closes first, so the client never waits on it.
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
$app->runDeferred();
