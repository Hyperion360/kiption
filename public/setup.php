<?php // public/setup.php
declare(strict_types=1);
/*
 * The one-use browser setup endpoint. It is the ONLY install entry point on
 * the web: a generic preflight view, a form gated by the operator's private
 * token, one atomic install, and then it closes forever.
 *
 * Trust boundary: anyone on the internet can reach this file. The operator
 * creates the 64-hex secret (site/shared/setup-token.txt, outside the web
 * root) through the hosting file manager; without it everything here is a
 * 404 or a 403. The mutation path holds the OS lock on setup.lock for the
 * whole request and re-checks the token, completion marker, and admin count
 * after taking it, so two racers yield one winner and a killed worker only
 * releases the lock. Every response is private and uncacheable; no token,
 * SMTP credential, filesystem path, or configuration value is ever rendered,
 * logged, or put in a URL. Errors become generic messages with a random
 * event id; exception text (which can carry SMTP credentials) never does.
 *
 * Runs under a stable wrapper (site/public/setup.php or public_html/
 * setup.php) that resolves site/current and defines KIPTION_ROOT, or as a
 * plain file in a flat checkout. The include-guard below lets tests drive
 * setup_handle() in-process with an array session.
 */

use App\Setup\Install;
use App\Setup\Preflight;
use App\Setup\SetupGate;

// The endpoint boots the framework itself: in the release layout it runs as a
// standalone real file (no front controller has loaded anything yet).
$setupRoot = defined('KIPTION_ROOT') ? KIPTION_ROOT : dirname(__DIR__);
require $setupRoot . '/vendor/autoload.php';

/**
 * The whole endpoint as a function: one request in, one response out.
 *
 * @param array<string,mixed> $server a $_SERVER-shaped array
 * @param array<string,mixed> $post a $_POST-shaped array
 * @param array<string,mixed> $cookie reserved (the endpoint reads no cookies)
 * @param array<string,mixed> $config the app config
 * @param array<string,mixed> $session the session array (by reference); the
 *        direct-execution path wires $_SESSION to it
 * @return array{status:int, headers:list<string>, body:string}
 */
function setup_handle(array $server, array $post, array $cookie, array $config, array &$session): array
{
    $plain = static fn (int $status, string $message): array => [
        'status' => $status,
        'headers' => ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: private, no-store'],
        'body' => $message . "\n",
    ];
    $page = static function (string $body): array {
        return ['status' => 200,
            'headers' => ['Content-Type: text/html; charset=utf-8', 'Cache-Control: private, no-store',
                'X-Frame-Options: DENY', 'Referrer-Policy: no-referrer'],
            'body' => $body];
    };
    $deny = static fn (int $status, string $message): array => $plain($status, $message);

    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    $now = isset($config['setup_now']) ? (int) $config['setup_now'] : null; // test seam: window expiry

    // State decides everything. No token and nothing to recover: this
    // endpoint does not exist for the visitor.
    $state = SetupGate::state($config);
    if (!$state['available'] && !$state['recovery']) {
        return $deny(404, 'Not found');
    }

    // HTTPS everywhere except loopback development.
    $https = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
    $loopback = in_array((string) ($server['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    if (!$https && !$loopback) {
        return $deny(403, 'Setup must run over HTTPS');
    }

    if ($method === 'GET') {
        if (SetupGate::rateLimited($config, $now)) {
            return $deny(429, 'Too many attempts; wait ten minutes and reload');
        }
        if (!isset($session['setup_csrf'])) {
            $session['setup_csrf'] = bin2hex(random_bytes(32));
        }
        // Generic preflight status only: check names and pass/fail, never
        // paths, settings values, or fix instructions that embed paths.
        $preflight = Preflight::run($config, (string) ($config['public_dir'] ?? ''));
        $rows = '';
        foreach ($preflight['checks'] as $check) {
            $name = htmlspecialchars((string) $check['name'], ENT_QUOTES);
            $verdict = ($check['status'] ?? '') === 'unverified'
                ? 'unverified'
                : ($check['ok'] ? 'pass' : 'fail');
            $rows .= '<tr><td>' . $name . '</td><td>' . $verdict . '</td></tr>';
        }
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>Kiption setup</title></head><body><main><h1>Kiption setup</h1>';
        if ($state['recovery']) {
            $body .= '<p>An interrupted install was found. Confirm the administrator'
                . ' account to finish setup. No new account will be created.</p>'
                . '<form method="post" action="setup.php">'
                . '<input type="hidden" name="csrf" value="' . htmlspecialchars((string) $session['setup_csrf'], ENT_QUOTES) . '">'
                . '<p><label>Admin email <input type="email" name="recover_email" required></label></p>'
                . '<p><label>Admin password <input type="password" name="recover_password" required></label></p>'
                . '<p><button type="submit">Finish setup</button></p></form>';
        } else {
            $body .= '<h2>Host check</h2><table><tbody>' . $rows . '</tbody></table>'
                . '<h2>Create the first administrator</h2>'
                . '<form method="post" action="setup.php">'
                . '<input type="hidden" name="csrf" value="' . htmlspecialchars((string) $session['setup_csrf'], ENT_QUOTES) . '">'
                . '<p><label>Setup token <input type="password" name="setup_token" required autocomplete="off"></label></p>'
                . '<p><label>Admin email <input type="email" name="email" required></label></p>'
                . '<p><label>Admin password <input type="password" name="password" required minlength="8"></label></p>'
                . '<p><label>Confirm password <input type="password" name="confirm" required></label></p>'
                . '<p><label>Archive URL (https, the real public address) <input type="url" name="base_url" placeholder="https://archive.example.com/" required></label></p>'
                . '<p><label>Mail transport <select name="mail_transport"><option value="smtp">SMTP</option><option value="mail">PHP mail()</option></select></label></p>'
                . '<p><label>From address <input type="email" name="mail_from" required></label></p>'
                . '<fieldset><legend>SMTP</legend>'
                . '<p><label>Host <input type="text" name="smtp_host"></label></p>'
                . '<p><label>Port <input type="number" name="smtp_port" min="1" max="65535" value="587"></label></p>'
                . '<p><label>Username <input type="text" name="smtp_username" autocomplete="off"></label></p>'
                . '<p><label>Password <input type="password" name="smtp_password" autocomplete="new-password"></label></p>'
                . '</fieldset>'
                . '<p><button type="submit">Install</button></p></form>'
                . '<p>Registration starts invite-only. Confirm the test email arrived,'
                . ' then switch registration to open verification in the settings board.</p>';
        }
        $body .= '</main></body></html>';
        return $page($body);
    }

    if ($method !== 'POST') {
        return $deny(405, 'Method not allowed');
    }

    if (SetupGate::rateLimited($config, $now)) {
        return $deny(429, 'Too many attempts; wait ten minutes');
    }

    // Owner recovery: finalize an install whose admin was created but whose
    // completion marker never landed. Never runs a fresh install.
    if ($state['recovery']) {
        return setup_recover($server, $post, $config, $session, $now, $deny, $page);
    }

    $fail = static function () use ($config, $now, $deny): array {
        SetupGate::recordFailure($config, $now);
        return $deny(403, 'Setup refused this request');
    };

    // The CSRF check comes first: the token comparison is string-exact, the
    // session pair proves the request came through the form.
    $csrf = (string) ($post['csrf'] ?? '');
    if (!is_string($session['setup_csrf'] ?? null) || !hash_equals((string) $session['setup_csrf'], $csrf)) {
        return $fail();
    }
    // The token: exactly 64 lowercase hex, hash-compared against the private
    // file, denied when an admin or a completion marker already exists.
    if (!SetupGate::authorize($config, (string) ($post['setup_token'] ?? ''))) {
        return $fail();
    }

    // The operator-entered canonical URL. Never derived from Host or proxy
    // headers; strict shape; public HTTPS hosts only.
    try {
        $canonical = setup_canonical_url((string) ($post['base_url'] ?? ''));
        $mailConfig = setup_mail_config($post);
        $email = (string) ($post['email'] ?? '');
        $password = (string) ($post['password'] ?? '');
        $confirm = (string) ($post['confirm'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid admin email address.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('The password must be at least 8 characters.');
        }
        if ($password !== $confirm) {
            throw new InvalidArgumentException('The passwords do not match.');
        }
    } catch (InvalidArgumentException $e) {
        return $deny(400, $e->getMessage());
    }

    // A same-origin POST only: the Origin header must match the canonical
    // URL the operator just submitted.
    $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $parts = parse_url($origin);
        $originCanonical = strtolower((string) ($parts['scheme'] ?? '')) . '://'
            . strtolower((string) ($parts['host'] ?? ''))
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($originCanonical !== $canonical || in_array($originCanonical, ['://', 'https://'], true)) {
            return $fail();
        }
    }

    $lock = SetupGate::acquireLock($config);
    if ($lock === null) {
        return $deny(409, 'Another setup request is running; try again');
    }
    $eventId = bin2hex(random_bytes(8));
    try {
        // The under-the-lock recheck: a second request that passed the earlier
        // checks before the first one completed is refused here.
        if (!SetupGate::holdsAfterRecheck($config, (string) ($post['setup_token'] ?? ''))) {
            return $deny(403, 'Setup is closed');
        }
        $preflight = Preflight::run($config, (string) ($config['public_dir'] ?? ''));
        if (!$preflight['ok']) {
            $names = implode(', ', array_map(
                static fn (array $c): string => (string) $c['name'],
                array_filter($preflight['checks'], static fn (array $c): bool => !$c['ok'] && ($c['status'] ?? '') !== 'unverified')
            ));
            return $deny(400, 'This host does not meet the requirements yet: ' . $names);
        }

        // The shared install service, in stages the operator can retry: each
        // stage is idempotent, so a failure anywhere leaves a retry (or the
        // owner-recovery form) one step from a complete site.
        $db = new \Kip\Database((string) $config['db']['dsn']);
        Install::migrate($config);
        setup_test_fault('migrated', $config);
        Install::blank($db, $config);
        setup_test_fault('blank', $config);

        $publicDir = defined('KIPTION_PUBLIC_DIR') ? KIPTION_PUBLIC_DIR : (string) ($config['public_dir'] ?? '');
        $deployment = ['public_dir' => $publicDir, 'base_url' => $canonical,
            'mail' => $mailConfig, 'registration_mode' => 'invite'];
        setup_write_private_php(SetupGate::path($config, 'deployment.php'), $deployment);
        setup_test_fault('deployed', $config);

        // The test send: proving the host can actually deliver before the
        // site exists. A transport that cannot send stops setup here, token
        // still usable.
        $sendConfig = $mailConfig;
        if (isset($config['setup_mail_log'])) { // test seam: deliver to a local log, record the real transport
            $sendConfig = ['transport' => 'log', 'log_path' => (string) $config['setup_mail_log'], 'from' => $mailConfig['from']];
        }
        (new \Kip\Mailer($sendConfig))->send($email, 'Kiption mail test',
            'Confirm this message arrived before enabling public registration.');

        setup_test_stall($config);
        Install::registerTrusted($db, $email, $password, true);
        setup_test_fault('admin', $config);

        SetupGate::complete($config);
        return $page(setup_page('Setup complete',
            '<p>The archive is installed. A test message is on its way to '
            . htmlspecialchars($email, ENT_QUOTES) . '.</p>'
            . '<p>Confirm it arrived, then open the settings board (open /settings, sign in as the'
            . ' administrator) and switch registration from invite to verify so readers can join.</p>'
            . '<p>Until then the archive starts invite-only with no invite codes issued: no public'
            . ' registrations can happen.</p><p>This setup endpoint is now closed for good.</p>'));
    } catch (\Throwable $e) {
        // Generic message only; the event id maps to a server-side log line
        // that never carries exception text (SMTP credentials live there).
        error_log("kiption setup event {$eventId}: failure during install");
        return $deny(500, 'Setup failed (event ' . $eventId . '); nothing was broken, the token still works: fix the cause and retry');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Owner recovery: verify the existing admin, verify deployment and blank seed, finalize. */
function setup_recover(array $server, array $post, array $config, array &$session, ?int $now, callable $deny, callable $page): array
{
    $csrf = (string) ($post['csrf'] ?? '');
    if (!is_string($session['setup_csrf'] ?? null) || !hash_equals((string) $session['setup_csrf'], $csrf)) {
        SetupGate::recordFailure($config, $now);
        return $deny(403, 'Setup refused this request');
    }
    $email = (string) ($post['recover_email'] ?? '');
    $password = (string) ($post['recover_password'] ?? '');
    $db = new \Kip\Database((string) $config['db']['dsn']);
    $admin = $db->one('SELECT id, password_hash FROM users WHERE email = ? AND is_admin = 1', [$email]);
    if ($admin === null || !password_verify($password, (string) $admin['password_hash'])) {
        SetupGate::recordFailure($config, $now);
        return $deny(403, 'Setup refused this request');
    }
    // Verify the interrupted install actually deployed and seeded; repair the
    // blank seed if it is the one missing piece.
    if (!is_file(SetupGate::path($config, 'deployment.php'))) {
        return $deny(409, 'The deployment record is missing; recovery cannot continue');
    }
    $ratings = (int) ($db->one('SELECT COUNT(*) c FROM ratings')['c'] ?? 0);
    if ($ratings === 0) {
        Install::blank($db, $config);
    }
    SetupGate::complete($config);
    return $page(setup_page('Setup complete',
        '<p>The interrupted install is finalized. Confirm the original test email'
        . ' arrived, then switch registration from invite to verify in the settings'
        . ' board. This setup endpoint is now closed for good.</p>'));
}

/** Strict canonical HTTPS root URL from operator input; never from Host. */
function setup_canonical_url(string $supplied): string
{
    $url = trim($supplied);
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)) {
        throw new InvalidArgumentException('Enter the archive HTTPS root URL.');
    }
    if (in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1', '[::1]'], true)) {
        throw new InvalidArgumentException('Enter the public archive host.');
    }
    return 'https://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
}

/** The outbound mail configuration from the form; every field validated. */
function setup_mail_config(array $post): array
{
    $transport = (string) ($post['mail_transport'] ?? '');
    if (!in_array($transport, ['smtp', 'mail'], true)) {
        throw new InvalidArgumentException('Choose SMTP or PHP mail.');
    }
    $email = (string) ($post['email'] ?? '');
    $from = (string) ($post['mail_from'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter valid admin and sender addresses.');
    }
    $mailConfig = ['transport' => $transport, 'from' => $from];
    if ($transport === 'smtp') {
        $host = (string) ($post['smtp_host'] ?? '');
        $port = filter_var($post['smtp_port'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $username = (string) ($post['smtp_username'] ?? '');
        $secret = (string) ($post['smtp_password'] ?? '');
        if (preg_match('/^[a-z0-9.-]+$/i', $host) !== 1 || !is_int($port)
            || $username === '' || $secret === '') {
            throw new InvalidArgumentException('Enter SMTP host, port, and credentials.');
        }
        $mailConfig += ['host' => $host, 'port' => $port, 'tls' => true,
            'username' => $username, 'password' => $secret];
    }
    return $mailConfig;
}

/** A private 0600 PHP file written by temp-file rename, never in place. */
function setup_write_private_php(string $path, array $value): void
{
    $bytes = "<?php\nreturn " . var_export($value, true) . ";\n";
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $bytes) === false) {
        throw new RuntimeException('cannot write the private record');
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('cannot finalize the private record');
    }
}

/** Test seams: never set by production config.php. */
function setup_test_fault(string $stage, array $config): void
{
    if (($config['setup_test_fault'] ?? '') === $stage) {
        throw new RuntimeException('injected setup fault');
    }
}

function setup_test_stall(array $config): void
{
    if (isset($config['setup_test_stall'])) {
        touch((string) $config['setup_test_stall']);
        sleep(30);
    }
}

function setup_page(string $title, string $bodyHtml): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . htmlspecialchars($title, ENT_QUOTES)
        . '</title></head><body><main><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
        . $bodyHtml . '</main></body></html>';
}

if (!defined('KIPTION_SETUP_LIBRARY_ONLY')) {
    // Direct execution: the stable wrapper or the webserver reached this file.
    $sessionStarted = session_start([...SetupGate::sessionCookieParams(), 'cookie_path' => '/']);
    $session = $sessionStarted ? $_SESSION : [];
    $config = require $setupRoot . '/config.php';
    $result = setup_handle($_SERVER, $_POST, $_COOKIE, $config, $session);
    if ($sessionStarted) {
        $_SESSION = $session;
    }
    http_response_code($result['status']);
    foreach ($result['headers'] as $header) {
        header($header);
    }
    echo $result['body'];
}
