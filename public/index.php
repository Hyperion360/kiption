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
  |      \-- miss --> legacy .php URL or /?p=&post_type=fcn_* query in the 301 map? --> Location 301 --> exit
  |                     \-- no --> new Kip\App(config, lazy session)
  |                                    |-- App::handle(request)
  |                                    |     |-- route to App\Features\{Feature}\*
  |                                    |     \-- Response (200/404/...)
  |                                    |-- 404 and a legacy Fictioneer path? --> Location 301 --> exit
  |                                    |-- static cache maybeStore(request, response)  (anonymous 200 only)
  |                                    \-- send
*/
declare(strict_types=1);

// Dev-server passthrough (php -S router mode): real files serve directly, exactly as
// the production .htaccess RewriteCond !-f does. Dead code under Apache/FPM.
// The docroot is the stable wrapper's directory (KIPTION_PUBLIC_DIR) when the
// split layout defines it, this directory otherwise. The passthrough emits the
// file itself: a `return false` here cannot cross the stable wrapper's require
// boundary, which is the top-level router the dev server consults.
if (PHP_SAPI === 'cli-server') {
    // Resolved on both sides: a docroot reached through a symlink (/var on
    // macOS, a symlinked home on a host) never prefixes its own files'
    // resolved paths otherwise, and every asset falls through to the app.
    $docroot = realpath(defined('KIPTION_PUBLIC_DIR') ? KIPTION_PUBLIC_DIR : __DIR__);
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $real = $docroot === false ? false : realpath($docroot . $p);
    if ($real !== false && str_starts_with($real, $docroot . '/') && is_file($real)) {
        $rel = substr($real, strlen($docroot) + 1);
        // .htaccess's two by-name refusals, mirrored: any dotted path segment
        // and the private setup state, so a misupload into the docroot stays
        // unreachable on the dev server exactly as it does under Apache.
        $private = preg_match('#(^|/)\.#', $rel) === 1
            || in_array(basename($rel), ['deployment.php', 'setup-token.txt', 'setup-complete.lock', 'setup.lock', 'setup-attempts'], true);
        // Known static types only: the assets, every extension Storage can
        // write into /uploads, and the real-file artifacts beside index.php
        // (robots.txt, the sitemaps). Anything else, .php above all
        // (setup.php, upgrade.php), falls through to the application like any
        // other route instead of being handed out as source. Text types carry
        // the charset the no-router dev server and PHP's own responses send.
        $type = $private ? null : ([
            'css' => 'text/css; charset=utf-8', 'gif' => 'image/gif', 'ico' => 'image/x-icon',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'js' => 'text/javascript; charset=utf-8',
            'png' => 'image/png', 'svg' => 'image/svg+xml', 'txt' => 'text/plain; charset=utf-8',
            'webp' => 'image/webp', 'woff2' => 'font/woff2', 'xml' => 'application/xml; charset=utf-8',
        ][strtolower(pathinfo($rel, PATHINFO_EXTENSION))] ?? null);
        if ($type !== null) {
            header('Content-Type: ' . $type);
            header('Content-Length: ' . (string) filesize($real));
            readfile($real);
            exit;
        }
    }
}

// The release root: a stable wrapper (site/public/index.php or
// public_html/index.php) resolves site/current and defines KIPTION_ROOT as
// the active release; a flat checkout is its own root.
$root = defined('KIPTION_ROOT') ? KIPTION_ROOT : dirname(__DIR__);

require $root . '/vendor/autoload.php';

$config = require $root . '/config.php';

// Pending browser setup: a generic 503 before the cache, routing, or any
// session work. File checks only (never the database). setup.php itself
// stays reachable as a real file; this closes every routed page and stops
// the static cache from storing anything until setup completes.
if (\App\Setup\SetupGate::pending($config)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    exit("Kiption is being installed. Try again soon.\n");
}

// The shared lease (upgrade discipline): every normal web request holds a
// SHARED flock on run.lock for its whole life, INCLUDING the deferred work
// after the response, so an upgrade's exclusive transition waits out all
// in-flight requests, and the maintenance check below reads a marker that
// is only ever written while the exclusive lease itself is held (so a
// request that got the lease necessarily reads the marker). Released by a
// shutdown function, which covers every exit path below. Zero queries; the
// anonymous cached path gains only one fopen/flock pair.
$runLock = @fopen(($config['data_dir'] ?? dirname(__DIR__) . '/app') . '/run.lock', 'c');
if ($runLock !== false && flock($runLock, LOCK_SH)) {
    register_shutdown_function(static function () use ($runLock): void {
        flock($runLock, LOCK_UN);
        fclose($runLock);
    });
}

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

// Legacy eFiction URLs (.php paths) and Fictioneer's WordPress query fallbacks
// (/?p=N&post_type=fcn_*, /?fcn_chapter=slug): consult the import's 301 map
// before routing. A plain / request carries none of those parameters and
// never reaches the database here.
$legacyQuery = $request->path === '/' && (isset($request->get['p']) || isset($request->get['fcn_story']) || isset($request->get['fcn_chapter']));
if ($request->method === 'GET' && (preg_match('#\.php$#', $request->path) || $legacyQuery)) {
    $redirects = new \App\Import\LegacyRedirects();
    $legacyDb = new \Kip\Database($config['db']['dsn']);
    $target = $legacyQuery ? $redirects->lookupPath($request, $legacyDb) : $redirects->lookup($request, $legacyDb);
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
// The settings seam: operator settings ride the SAME resolution (one statement,
// no new round trip) and overlay config in place, so every $app->config(...) read
// point picks up DB values with zero downstream edits. Guarded: an unmigrated
// database keeps the config-file values instead of fataling the first page.
$config = \App\Settings::apply($config);
// The RTA seam: the operator's site-wide adult label (config 'adult_rta',
// the settings board's row overrides it). Set once at boot, zero queries;
// a page-level withRating() call still wins inside Head.
\App\Seo\Head::siteRating((string) ($config['adult_rta'] ?? ''));
// The footer seam: layout templates read it statically (no config in view
// scope). It inits from the MERGED config, after the settings overlay: the
// board's powered_by row must govern the footer on the very next render.
// Nothing above reads it (the static-cache HIT serves stored bytes, the
// maintenance view is standalone), so init-after-apply covers every render.
\App\Attribution::init($config);
\App\FooterLegal::init($config); // the legal-line seam, same merged-config order
// The skins seam (views_override): the operator's chosen skin directory wins
// over the app defaults template-by-template, '' for the default skin. It sits
// AFTER the static-cache HIT and the maintenance 503 above by construction:
// both serve before the skin layer exists, so neither is ever skinned.
$config['views_override'] = \App\Skins::overrideDir($config);

$app = new Kip\App($config, Kip\Session::lazy(new Kip\SessionStarter($https)));
$response = $app->handle($request);
// Fictioneer legacy paths: only a routed 404 pays the consult (one indexed
// lookup). Every clean page and every static-cache HIT is untouched.
if ($response->status === 404 && $request->method === 'GET') {
    $target = (new \App\Import\LegacyRedirects())->lookupPath($request, new \Kip\Database($config['db']['dsn']));
    if ($target !== null) {
        header('Cache-Control: private, no-store');
        header('Location: ' . $target, true, 301);
        exit;
    }
}
if ($static !== null) {
    $static->maybeStore($request, $response);
}
// The shared-cache contract, decided where store eligibility is known: the
// one page shape a CDN may hold is the one this layer just stored (anonymous,
// 200, cookieless, queryless, whitelisted GET), the syndication XML carries
// its own bounded public lifetime (identical bytes for every reader, ETag
// revalidation), and everything else, including every render when the static
// cache is disabled outright, is private and uncacheable. A response that
// already carries Cache-Control (the reader fragment's private, no-store, the
// beacon's) is overwritten with the value its eligibility demands, which is
// always at least as strict.
$response = $response->withHeader('Cache-Control', $static?->cacheControlFor($request, $response)
    ?? \App\StaticCache\Cache::feedControl($request, $response)
    ?? 'private, no-store');
// Multi-cookie Set-Cookie needs no wire translation since the Kip 0.5 sync:
// Response carries list-valued headers and send() emits each leaf with append
// semantics, so the session cookie survives beside them natively.
$response->send();
ob_end_flush();
// Work queued with App::defer() runs after the response is out. Under PHP-FPM the
// connection closes first, so the client never waits on it.
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
$app->runDeferred();
