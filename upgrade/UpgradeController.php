<?php // upgrade/UpgradeController.php
declare(strict_types=1);

namespace Upgrade;

require_once __DIR__ . '/ReleasePointer.php';
require_once __DIR__ . '/Journal.php';
require_once __DIR__ . '/ReleaseVerifier.php';

/**
 * The stable upgrade controller: the one routing, authorization, and lease
 * layer shared by the stable web wrappers (site/public/upgrade.php and
 * public_html/upgrade.php) and the private CLI (site/upgrade/cli.php). It
 * loads stable engine code only. Release classes are loaded solely to
 * authenticate the preparing admin against the SOURCE release; activate,
 * continue, and recover never read the users table, so a half-migrated
 * schema cannot lock the owner out.
 *
 * Authorization:
 *  - prepare: a valid admin session on the source release plus password
 *    re-entry, CSRF, a same-origin POST, HTTPS (loopback development
 *    exempt), a failure-rate cap, and a verified staged release. The
 *    browser path also runs the bounded rehearsal, because its transitions
 *    must fit one request; the CLI path verifies without it.
 *  - activate and continue: the session capability minted at prepare,
 *    checked with hash_equals against the journal hash.
 *  - recover: the capability, or a one-time 64-hex recovery token the
 *    owner places at site/shared/upgrade/recovery-token through the file
 *    manager. At the prepared state nothing has switched, so recovery
 *    cancels the record; once the coordinator's states exist, recovery of
 *    a switched upgrade is its restore path.
 *  - CLI: filesystem ownership. No session, no password, no capability,
 *    and nothing printed that could substitute for one.
 *
 * Leasing: every mutating command takes the EXCLUSIVE flock on
 * shared/run.lock, the same lock every normal web request holds SHARED for
 * its whole life and every stable CLI command holds for its run, so
 * browser and CLI transitions serialize against each other and against
 * all site work. Read-only status never locks.
 *
 * No filesystem path, password, or capability is ever rendered. Errors are
 * authored strings; unexpected failures are not caught into 200s.
 *
 * Test seams (env keys, never set by production wrappers or the CLI
 * launcher): lease_wait caps the exclusive-lease wait, now fixes the
 * failure-rate clock and capability expiry.
 */
final class UpgradeController
{
    private const RATE_WINDOW = 600;
    private const RATE_MAX = 5;

    /**
     * One web request in, one response out.
     *
     * @param array<string,mixed> $server a $_SERVER-shaped array
     * @param array<string,mixed> $post a $_POST-shaped array
     * @param array<string,mixed> $session the session array (by reference)
     * @param array<string,mixed> $env site_root, shared_dir, source_root,
     *        public_dir; optional base_url override and test seams
     * @return array{status:int, headers:list<string>, body:string}
     */
    public static function handleWeb(array $server, array $post, array &$session, array $env): array
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

        // HTTPS everywhere except loopback development.
        $https = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
        $loopback = in_array((string) ($server['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
        if (!$https && !$loopback) {
            return $deny(403, 'The upgrade endpoint must run over HTTPS');
        }

        $shared = (string) ($env['shared_dir'] ?? '');
        $now = isset($env['now']) ? (int) $env['now'] : null;

        if ($method === 'GET') {
            if (!isset($session['upgrade_csrf'])) {
                $session['upgrade_csrf'] = bin2hex(random_bytes(32));
            }
            $journal = new Journal($shared, ['now' => $now]);
            return $page(self::statusBody($journal, $session));
        }
        if ($method !== 'POST') {
            return $deny(405, 'Method not allowed');
        }

        // The failure-rate cap precedes every other check.
        if (self::rateLimited($shared, $now)) {
            return $deny(429, 'Too many attempts; wait ten minutes and try again');
        }

        $csrf = (string) ($post['csrf'] ?? '');
        if (!is_string($session['upgrade_csrf'] ?? null) || !hash_equals((string) $session['upgrade_csrf'], $csrf)) {
            self::recordFailure($shared, $now);
            return $deny(403, 'Upgrade refused this request');
        }

        // A same-origin POST only: a presented Origin must match the
        // archive origin (the canonical URL the owner installed with).
        $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && !self::originMatches($origin, self::baseUrl($env))) {
            self::recordFailure($shared, $now);
            return $deny(403, 'Upgrade refused this request');
        }

        $journal = new Journal($shared, ['now' => $now]);
        switch ((string) ($post['command'] ?? '')) {
            case 'prepare':
                return self::webPrepare($post, $session, $env, $journal, $deny, $page);
            case 'activate':
            case 'continue':
                return self::webTransition((string) $post['command'], $session, $env, $journal, $deny);
            case 'recover':
                return self::webRecover($post, $session, $env, $journal, $deny, $page);
            default:
                return $deny(400, 'Unknown upgrade command');
        }
    }

    /** The SAPI guard the private CLI entry applies before anything runs. */
    public static function cliGuard(string $sapi): ?string
    {
        return $sapi === 'cli' ? null : 'the private upgrade CLI runs on the command line only';
    }

    /**
     * The private CLI: prepare|activate|continue|recover|status with the
     * same verifier, lease, and journal as the browser. Returns the process
     * exit code.
     *
     * @param list<string> $args the argv after the script name
     * @param array<string,mixed> $env the same env the wrappers build
     */
    public static function runCli(array $args, array $env): int
    {
        $usage = "usage: php site/upgrade/cli.php prepare --digest=<64-hex RELEASE.json digest> | activate | continue"
            . " | recover | status\n";
        $command = (string) ($args[0] ?? '');
        $digest = '';
        foreach (array_slice($args, 1) as $arg) {
            if (str_starts_with($arg, '--digest=')) {
                $digest = substr($arg, 9);
            } else {
                fwrite(STDERR, $usage);
                return 1;
            }
        }
        $shared = (string) ($env['shared_dir'] ?? '');
        $journal = new Journal($shared, ['now' => isset($env['now']) ? (int) $env['now'] : null]);

        if ($command === 'status') {
            $record = $journal->read();
            echo $record === null
                ? "no upgrade is recorded\n"
                : 'upgrade state: ' . $record['state'] . ' (target ' . (string) ($record['target_version'] ?? '?') . ")\n";
            return 0;
        }

        if ($command === 'prepare') {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                fwrite(STDERR, "prepare needs --digest=<the 64-character RELEASE.json digest from the official release page>\n");
                return 1;
            }
            $lease = self::acquireLease($env);
            if ($lease === null) {
                fwrite(STDERR, "another Kiption command holds the run lock; a committed import never waits one out\n");
                return 1;
            }
            try {
                if (self::maintenanceSet($env)) {
                    fwrite(STDERR, "Kiption is in maintenance; resolve that first\n");
                    return 1;
                }
                [$failures, $id, $version] = self::verifyStage($env, $digest, rehearse: false);
                if ($failures !== []) {
                    fwrite(STDERR, "the staged release did not verify:\n" . implode("\n", $failures) . "\n");
                    return 1;
                }
                $sourceId = self::activeId($env);
                if ($sourceId === null) {
                    fwrite(STDERR, "no active release to upgrade from\n");
                    return 1;
                }
                $journal->prepare($sourceId, $id, $version, web: false);
                echo "prepared {$version}; run activate next\n";
                return 0;
            } catch (\RuntimeException $e) {
                fwrite(STDERR, $e->getMessage() . "\n");
                return 1;
            } finally {
                flock($lease, LOCK_UN);
                fclose($lease);
            }
        }

        if (in_array($command, ['activate', 'continue', 'recover'], true)) {
            // The coordinator takes the exclusive lease itself for the
            // transition; leasing here too would nest the lock against
            // this very process.
            return self::runCliCoordinator($command, $env);
        }

        fwrite(STDERR, $usage);
        return 1;
    }

    // ------------------------------------------------------------- internals

    /**
     * The browser prepare: authenticate the admin against the source
     * release (the one place release classes load), verify the staged
     * release read-only, then take the exclusive lease and record.
     */
    private static function webPrepare(array $post, array &$session, array $env, Journal $journal, callable $deny, callable $page): array
    {
        $fail = static function () use ($env, $deny): array {
            self::recordFailure((string) ($env['shared_dir'] ?? ''), isset($env['now']) ? (int) $env['now'] : null);
            return $deny(403, 'Upgrade refused this request');
        };

        $source = (string) ($env['source_root'] ?? '');
        if ($source === '' || !is_dir($source)) {
            return $deny(409, 'No active release to upgrade from');
        }
        if (self::maintenanceSet($env)) {
            return $deny(409, 'Kiption is in maintenance; resolve that before preparing an upgrade');
        }
        // The source release's classes, only for this authentication.
        if (!class_exists(\Kip\Auth::class)) {
            require_once $source . '/vendor/autoload.php';
        }
        $db = new \Kip\Database('sqlite:' . ((string) $env['shared_dir']) . '/data.sqlite');
        $auth = new \Kip\Auth($db, new \Kip\Session($session));
        if (!$auth->sessionValid()) {
            return $fail();
        }
        $admin = $db->one('SELECT id, password_hash FROM users WHERE id = ? AND is_admin = 1', [$session['user_id']]);
        if ($admin === null || !password_verify((string) ($post['upgrade_password'] ?? ''), (string) $admin['password_hash'])) {
            return $fail();
        }

        // The published digest: shape first, then the staged tree.
        $digest = (string) ($post['expected_sha256'] ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            return $deny(400, 'Enter the 64-character RELEASE.json digest from the official release page');
        }
        // The browser path proves the backup-and-restore rehearsal fits one
        // request; the verifier itself is read-only and never touches the
        // pointer, journal, or maintenance marker.
        [$failures, $id, $version] = self::verifyStage($env, $digest, rehearse: true);
        if ($failures !== []) {
            return $deny(400, 'The staged release did not verify: ' . implode(' ', $failures));
        }

        $sourceId = self::activeId($env);
        if ($sourceId === null) {
            return $deny(409, 'No active release to upgrade from');
        }
        $lease = self::acquireLease($env);
        if ($lease === null) {
            return $deny(409, 'Another Kiption command holds the run lock; try again when it finishes');
        }
        try {
            [$record, $capability] = $journal->prepare($sourceId, $id, $version, web: true);
            $session['upgrade_capability'] = $capability;
            @unlink(self::rateFile((string) $env['shared_dir'])); // a success clears the failure window
            return $page(self::statusBody($journal, $session));
        } catch (\RuntimeException $e) {
            return $deny(409, $e->getMessage());
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    /**
     * The capability-bearing transitions. Authorization only; the state
     * machine itself lives in the coordinator, which a stable install
     * carries as site/upgrade/Coordinator.php.
     */
    private static function webTransition(string $command, array &$session, array $env, Journal $journal, callable $deny): array
    {
        $capability = $session['upgrade_capability'] ?? null;
        if (!$journal->capabilityValid(is_string($capability) ? $capability : null)) {
            self::recordFailure((string) ($env['shared_dir'] ?? ''), isset($env['now']) ? (int) $env['now'] : null);
            return $deny(403, 'Upgrade refused this request');
        }
        return self::runWebCoordinator($command, $session, $env);
    }

    /**
     * Recovery: the capability authorizes directly; without it, the
     * one-time recovery token rotates in a fresh capability (never a URL,
     * always a file the owner places). The coordinator owns every recorded
     * state: prepared cancels cleanly, and a switched upgrade restores.
     */
    private static function webRecover(array $post, array &$session, array $env, Journal $journal, callable $deny, callable $page): array
    {
        $capability = $session['upgrade_capability'] ?? null;
        $authorized = $journal->capabilityValid(is_string($capability) ? $capability : null);
        if (!$authorized) {
            try {
                $session['upgrade_capability'] = $journal->rotateWithRecoveryToken((string) ($post['recovery_token'] ?? ''));
            } catch (\RuntimeException) {
                self::recordFailure((string) ($env['shared_dir'] ?? ''), isset($env['now']) ? (int) $env['now'] : null);
                return $deny(403, 'Upgrade refused this request');
            }
        }
        return self::runWebCoordinator('recover', $session, $env);
    }

    /**
     * The coordinator seam for web transitions. A stable install ships the
     * coordinator beside this controller; its absence is a clean refusal,
     * never a crash and never a partial state.
     */
    private static function runWebCoordinator(string $command, array &$session, array $env): array
    {
        $coordinator = __DIR__ . '/Coordinator.php';
        if (!is_file($coordinator)) {
            return self::plain(503, 'The upgrade engine on this install cannot run this step; reinstall the stable upgrade engine or use the PHP CLI path');
        }
        require_once $coordinator;
        return Coordinator::web($command, $session, $env);
    }

    private static function runCliCoordinator(string $command, array $env): int
    {
        $coordinator = __DIR__ . '/Coordinator.php';
        if (!is_file($coordinator)) {
            fwrite(STDERR, "the upgrade engine on this install cannot run {$command}; the transition engine is missing\n");
            return 1;
        }
        require_once $coordinator;
        return Coordinator::cli($command, $env);
    }

    /**
     * The staged release, verified without executing any of it. The active
     * manifest anchors the version comparison; the three live databases and
     * the public tree anchor the disk, lock, and rename probes.
     *
     * @return array{0:list<string>,1:string,2:string} failures, verified id, version
     */
    private static function verifyStage(array $env, string $digest, bool $rehearse): array
    {
        $siteRoot = (string) ($env['site_root'] ?? '');
        $shared = (string) ($env['shared_dir'] ?? '');
        $activeId = self::activeId($env);
        $activeManifest = $activeId !== null
            ? (string) @file_get_contents($siteRoot . '/releases/' . $activeId . '/RELEASE.json')
            : '';
        $verifier = new ReleaseVerifier($siteRoot, $activeManifest, [
            'data' => 'sqlite:' . $shared . '/data.sqlite',
            'logs' => 'sqlite:' . $shared . '/logs.sqlite',
            'cache' => 'sqlite:' . $shared . '/cache.sqlite',
        ], self::publicDir($env), (array) ($env['verify_options'] ?? []));
        $result = $verifier->verify($siteRoot . '/releases/' . $digest, $digest, $rehearse);
        return [$result['failures'], (string) $result['id'], (string) $result['version']];
    }

    /** The active release ID from the env's already-validated source root. */
    private static function activeId(array $env): ?string
    {
        $source = (string) ($env['source_root'] ?? '');
        $id = basename($source);
        return $source !== '' && preg_match('/^[0-9a-f]{64}$/', $id) === 1 ? $id : null;
    }

    /** @return array{status:int, headers:list<string>, body:string} a plain-text response */
    public static function plain(int $status, string $message): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: private, no-store'],
            'body' => $message . "\n",
        ];
    }

    /** @return array{status:int, headers:list<string>, body:string} an HTML page response */
    public static function page(string $body): array
    {
        return ['status' => 200,
            'headers' => ['Content-Type: text/html; charset=utf-8', 'Cache-Control: private, no-store',
                'X-Frame-Options: DENY', 'Referrer-Policy: no-referrer'],
            'body' => $body];
    }

    /** @return array{status:int, headers:list<string>, body:string} a same-origin 303 */
    public static function redirect(string $location): array
    {
        return ['status' => 303, 'headers' => ['Location: ' . $location, 'Cache-Control: private, no-store'], 'body' => ''];
    }

    /**
     * The exclusive run.lock lease one upgrade transition holds across its
     * side effects; the coordinator acquires it through here so the wait
     * rules live in one place.
     *
     * @return resource|null
     */
    public static function exclusiveLease(array $env)
    {
        return self::acquireLease($env);
    }

    /**
     * The maintenance marker, tagged "upgrade" so a marker this engine
     * wrote is distinguishable from a committed import's; recovery lifts
     * only ours.
     */
    public static function writeMaintenance(string $sharedDir, string $note): void
    {
        if (@file_put_contents($sharedDir . '/maintenance.lock', 'upgrade ' . $note . ' ' . date('c')) === false) {
            throw new \RuntimeException('cannot set the maintenance marker');
        }
    }

    /** Lifts the marker only when this engine owns it; an import's stays. */
    public static function clearUpgradeMaintenance(string $sharedDir): void
    {
        $file = $sharedDir . '/maintenance.lock';
        $raw = @file_get_contents($file);
        if ($raw === false || str_starts_with($raw, 'upgrade')) {
            @unlink($file);
        }
    }

    /** @return resource|null the exclusive lease, or null on timeout */
    private static function acquireLease(array $env)
    {
        $handle = @fopen((string) ($env['shared_dir'] ?? '') . '/run.lock', 'c');
        if ($handle === false) {
            return null;
        }
        $wait = (float) ($env['lease_wait'] ?? 30.0);
        $held = flock($handle, LOCK_EX | LOCK_NB);
        $waited = 0.0;
        while (!$held && $waited < $wait) {
            usleep(100000);
            $waited += 0.1;
            $held = flock($handle, LOCK_EX | LOCK_NB);
        }
        if (!$held) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private static function maintenanceSet(array $env): bool
    {
        return is_file((string) ($env['shared_dir'] ?? '') . '/maintenance.lock');
    }

    /** The archive origin the owner installed with: env override, then the deployment record. */
    private static function baseUrl(array $env): string
    {
        if (isset($env['base_url']) && $env['base_url'] !== '') {
            return (string) $env['base_url'];
        }
        $deploymentFile = (string) ($env['shared_dir'] ?? '') . '/deployment.php';
        $deployment = is_file($deploymentFile) ? (require $deploymentFile) : [];
        return (string) ($deployment['base_url'] ?? '');
    }

    /**
     * The public tree the asset steps and the disk peak need: env override,
     * then the deployment record. Public because the coordinator resolves
     * it the same way (the CLI env carries no KIPTION_PUBLIC_DIR).
     */
    public static function publicDir(array $env): string
    {
        if (isset($env['public_dir']) && $env['public_dir'] !== '') {
            return (string) $env['public_dir'];
        }
        $deploymentFile = (string) ($env['shared_dir'] ?? '') . '/deployment.php';
        $deployment = is_file($deploymentFile) ? (require $deploymentFile) : [];
        return (string) ($deployment['public_dir'] ?? '');
    }

    private static function originMatches(string $origin, string $baseUrl): bool
    {
        $canonical = static function (string $url): string {
            $parts = parse_url($url);
            if (!is_array($parts)) {
                return '';
            }
            return strtolower((string) ($parts['scheme'] ?? '')) . '://'
                . strtolower((string) ($parts['host'] ?? ''))
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
        };
        $originCanonical = $canonical($origin);
        return $originCanonical !== '' && !in_array($originCanonical, ['://', 'https://', 'http://'], true)
            && hash_equals($originCanonical, $canonical($baseUrl));
    }

    // ------------------------------------------------- failure-rate file cap

    private static function rateFile(string $shared): string
    {
        return $shared . '/upgrade/failures';
    }

    private static function failures(string $shared, ?int $now): int
    {
        $now ??= time();
        $raw = @file_get_contents(self::rateFile($shared));
        if ($raw === false) {
            return 0;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || (int) ($state['window_start'] ?? 0) + self::RATE_WINDOW <= $now) {
            return 0;
        }
        return (int) ($state['count'] ?? 0);
    }

    private static function rateLimited(string $shared, ?int $now): bool
    {
        return self::failures($shared, $now) >= self::RATE_MAX;
    }

    private static function recordFailure(string $shared, ?int $now): void
    {
        $now ??= time();
        $count = self::failures($shared, $now);
        $raw = @file_get_contents(self::rateFile($shared));
        $state = is_string($raw) ? json_decode($raw, true) : null;
        $windowStart = is_array($state) ? (int) ($state['window_start'] ?? $now) : $now;
        $dir = dirname(self::rateFile($shared));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $handle = @fopen(self::rateFile($shared), 'c');
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode(['count' => $count + 1, 'window_start' => $windowStart]));
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    // ------------------------------------------------------------- rendering

    /** The status page: state, forms, and nothing that leaks a path or credential. */
    public static function statusBody(Journal $journal, array $session): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $csrfField = '<input type="hidden" name="csrf" value="' . $h((string) ($session['upgrade_csrf'] ?? '')) . '">';
        $record = $journal->read();
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>Kiption upgrade</title></head><body><main>'
            . '<h1>Kiption upgrade</h1>';
        if ($record === null) {
            $body .= '<p>Paste the RELEASE.json SHA-256 exactly as published on the official'
                . ' release page, with the release extracted into the matching staging'
                . ' directory under the private releases tree.</p>'
                . '<form method="post">'
                . '<input type="hidden" name="command" value="prepare">' . $csrfField
                . '<p><label>Release digest <input name="expected_sha256" required minlength="64" maxlength="64" autocomplete="off"></label></p>'
                . '<p><label>Admin password <input type="password" name="upgrade_password" required autocomplete="current-password"></label></p>'
                . '<p><button type="submit">Verify staged release</button></p></form>';
            return $body . '</main></body></html>';
        }
        $capability = $session['upgrade_capability'] ?? null;
        $capable = $journal->capabilityValid(is_string($capability) ? $capability : null);
        $body .= '<p>Upgrade state: <strong>' . $h((string) $record['state']) . '</strong>'
            . ' (target version ' . $h((string) ($record['target_version'] ?? '?')) . ')</p>';
        if ($record['state'] === Journal::PREPARED && $capable) {
            $body .= '<form method="post">'
                . '<input type="hidden" name="command" value="continue">' . $csrfField
                . '<p><button type="submit">Continue upgrade</button></p></form>';
        }
        $body .= '<h2>Recovery</h2>'
            . '<p>Lost the session or the capability expired? Place a fresh 64-character'
            . ' recovery token in the private upgrade directory with the hosting file'
            . ' manager, then submit it here. The token is consumed once.</p>'
            . '<form method="post">'
            . '<input type="hidden" name="command" value="recover">' . $csrfField
            . '<p><label>Recovery token <input name="recovery_token" autocomplete="off"></label></p>'
            . '<p><button type="submit">Recover</button></p></form>'
            . '</main></body></html>';
        return $body;
    }
}
