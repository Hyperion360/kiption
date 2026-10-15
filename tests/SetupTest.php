<?php // tests/SetupTest.php
namespace App\Tests;

use App\Setup\Install;
use App\Setup\Preflight;
use PHPUnit\Framework\TestCase;

/**
 * The shared-host setup seam: Preflight proves the host before setup offers
 * to install; SetupGate authorizes the one-use browser setup; Install is the
 * one migration-plus-admin service shared by CLI and browser.
 */
final class SetupTest extends TestCase
{
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        if (getenv('KIPTION_KEEP_TMP') !== false) {
            return; // debug seam: keep the temp trees for inspection
        }
        foreach ($this->tmpDirs as $dir) {
            $this->chmodRecursive($dir, 0775);
            $this->rrmdir($dir);
        }
    }

    private function tmptree(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0775, true));
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    private function chmodRecursive(string $dir, int $mode): void
    {
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, $mode);
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? $this->chmodRecursive($p, $mode) : @chmod($p, $mode);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** @param array<string,mixed> $overrides */
    private function config(string $dataDir, array $overrides = []): array
    {
        return [...[
            'app_dir' => dirname(__DIR__) . '/app',
            'data_dir' => $dataDir,
            'db' => ['dsn' => 'sqlite:' . $dataDir . '/data.sqlite'],
            'base_url' => 'http://localhost:8080',
            'mail' => ['transport' => 'log', 'log_path' => $dataDir . '/mail.log', 'from' => 'noreply@localhost'],
            'static_cache' => ['dir' => $dataDir . '/cache'],
            'uploads' => ['dir' => $dataDir . '/uploads'],
            'backups' => ['dir' => $dataDir . '/backups'],
        ], ...$overrides];
    }

    // ------------------------------------------------------------ preflight

    public function test_preflight_passes_a_compatible_host(): void
    {
        $home = $this->tmptree('kiption-pf-');
        $data = $home . '/site/shared';
        $public = $home . '/public_html';
        mkdir($data, 0775, true);
        mkdir($public, 0775, true);

        $r = Preflight::run($this->config($data), $public);
        $this->assertTrue($r['ok'], json_encode($r['checks'], JSON_PRETTY_PRINT));
        $names = array_column($r['checks'], 'name');
        foreach (['php', 'pdo_sqlite', 'wal', 'private_root', 'https', 'rewrite'] as $need) {
            $this->assertContains($need, $names, "preflight must report {$need}");
        }
        foreach ($r['checks'] as $check) {
            $this->assertArrayHasKey('fix', $check);
            if (!$check['ok']) {
                $this->assertSame('unverified', $check['status'] ?? 'failed', "hard failure: {$check['name']}");
            }
        }
        $rewrite = $r['checks'][array_search('rewrite', $names, true)];
        $this->assertSame('unverified', $rewrite['status'], 'rewrite is a host smoke check, never a PHP claim');
    }

    public function test_preflight_fails_a_path_under_a_regular_file(): void
    {
        $home = $this->tmptree('kiption-pf-');
        $public = $home . '/public_html';
        mkdir($public, 0775, true);
        file_put_contents($home . '/regular', 'x');

        $r = Preflight::run($this->config($home . '/regular/child'), $public);
        $this->assertFalse($r['ok']);
        $byName = array_column($r['checks'], null, 'name');
        $this->assertFalse($byName['writable private data']['ok'], 'a path under a regular file is unwritable');
        $this->assertNotSame('', $byName['writable private data']['fix']);
    }

    public function test_preflight_reports_missing_pdo_sqlite_via_the_capability_map(): void
    {
        $home = $this->tmptree('kiption-pf-');
        mkdir($home . '/site/shared', 0775, true);
        mkdir($home . '/public_html', 0775, true);

        $r = Preflight::run($this->config($home . '/site/shared'), $home . '/public_html', ['pdo_sqlite' => false]);
        $this->assertFalse($r['ok']);
        $byName = array_column($r['checks'], null, 'name');
        $this->assertFalse($byName['pdo_sqlite']['ok']);
        $this->assertNotSame('', $byName['pdo_sqlite']['fix'], 'the report carries an actionable fix');
    }

    public function test_preflight_rejects_a_private_root_under_the_docroot(): void
    {
        $home = $this->tmptree('kiption-pf-');
        $public = $home . '/public_html';
        mkdir($public . '/data', 0775, true);

        $r = Preflight::run($this->config($public . '/data'), $public);
        $this->assertFalse($r['ok']);
        $byName = array_column($r['checks'], null, 'name');
        $this->assertFalse($byName['private_root']['ok']);
        $this->assertStringContainsString('web root', (string) $byName['private_root']['fix']);
    }

    public function test_preflight_wal_probe_cleans_up_and_never_touches_existing_data(): void
    {
        $home = $this->tmptree('kiption-pf-');
        $data = $home . '/site/shared';
        $public = $home . '/public_html';
        mkdir($data, 0775, true);
        mkdir($public, 0775, true);

        $r = Preflight::run($this->config($data), $public);
        $byName = array_column($r['checks'], null, 'name');
        $this->assertTrue($byName['wal']['ok'], 'WAL must work on a plain local filesystem');
        $this->assertSame([], glob($data . '/preflight-wal-probe*'), 'the probe and its sidecars are removed');

        // An unwritable data directory fails the probe concretely, and the
        // operator's existing files survive untouched.
        file_put_contents($data . '/data.sqlite', 'not really a database, but it is the operator\'s');
        $this->chmodRecursive($data, 0555);
        try {
            $r2 = Preflight::run($this->config($data), $public);
            $byName2 = array_column($r2['checks'], null, 'name');
            $this->assertFalse($byName2['wal']['ok']);
            $this->assertNotSame('', $byName2['wal']['fix']);
        } finally {
            $this->chmodRecursive($data, 0775);
        }
        $this->assertFileExists($data . '/data.sqlite', 'a failed probe deletes nothing');
        $this->assertSame("not really a database, but it is the operator's", (string) file_get_contents($data . '/data.sqlite'));
    }

    public function test_preflight_injected_wal_capability_skips_the_live_probe(): void
    {
        $home = $this->tmptree('kiption-pf-');
        mkdir($home . '/site/shared', 0775, true);
        mkdir($home . '/public_html', 0775, true);

        $r = Preflight::run($this->config($home . '/site/shared'), $home . '/public_html', ['wal' => false]);
        $byName = array_column($r['checks'], null, 'name');
        $this->assertFalse($byName['wal']['ok']);
        $this->assertSame([], glob($home . '/site/shared/preflight-wal-probe*'), 'no probe ran');

        $r2 = Preflight::run($this->config($home . '/site/shared'), $home . '/public_html', ['https' => true, 'rewrite' => true]);
        $byName2 = array_column($r2['checks'], null, 'name');
        $this->assertTrue($byName2['https']['ok']);
        $this->assertTrue($byName2['rewrite']['ok'], 'a host-matrix-verified rewrite is a pass, not unverified');
    }

    public function test_preflight_flags_a_plain_http_public_url(): void
    {
        $home = $this->tmptree('kiption-pf-');
        mkdir($home . '/site/shared', 0775, true);
        mkdir($home . '/public_html', 0775, true);

        $r = Preflight::run($this->config($home . '/site/shared', [
            'base_url' => 'http://archive.example.com',
        ]), $home . '/public_html');
        $byName = array_column($r['checks'], null, 'name');
        $this->assertFalse($byName['https']['ok'], 'a public http URL is a real failure');
    }

    // -------------------------------------------------------------- install

    /** CLI fixture env for bin/kip: throwaway DB, cache dir, and nav file. */
    private function kipEnv(string $dbPath): string
    {
        $static = $dbPath . '-static';
        if (!is_dir($static)) {
            mkdir($static);
        }
        return sprintf(
            'KIP_DB_DSN=%s KIP_STATIC_CACHE_DIR=%s KIP_NAV_FILE=%s KIP_BACKUP_DIR=%s',
            escapeshellarg('sqlite:' . $dbPath),
            escapeshellarg($static),
            escapeshellarg($dbPath . '-nav.json'),
            escapeshellarg($dbPath . '-backups')
        );
    }

    private function kip(string $dbPath, string $args): array
    {
        exec(sprintf(
            'PATH="/opt/homebrew/bin:/usr/bin:/bin" %s %s %s %s 2>&1',
            $this->kipEnv($dbPath),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'),
            $args
        ), $out, $code);
        return [$code, implode("\n", $out)];
    }

    /** Fresh-install config for the in-process path (matches the CLI env). */
    private function installConfig(string $dbPath): array
    {
        return [
            'app_dir' => dirname(__DIR__) . '/app',
            'data_dir' => $dbPath . '-data',
            'db' => ['dsn' => 'sqlite:' . $dbPath],
            'nav_file' => $dbPath . '-nav.json',
            'backups' => ['dir' => $dbPath . '-backups'],
            'static_cache' => ['enabled' => false, 'dir' => $dbPath . '-static'],
            'base_url' => 'http://localhost:8080',
            'mail' => ['transport' => 'log', 'log_path' => $dbPath . '-mail.log', 'from' => 'noreply@localhost'],
        ];
    }

    public function test_install_parity(): void
    {
        // The CLI path: migrate, blank bootstrap, admin user.
        $cliDb = $this->tmptree('kiption-parity-cli-') . '/data.sqlite';
        [$code, $out] = $this->kip($cliDb, 'migrate');
        $this->assertSame(0, $code, $out);
        [$code, $out] = $this->kip($cliDb, 'db:blank');
        $this->assertSame(0, $code, $out);
        [$code, $out] = $this->kip($cliDb, 'user:create cli@e.test password123 --admin');
        $this->assertSame(0, $code, $out);

        // The browser-setup path: one call.
        $freshDb = $this->tmptree('kiption-parity-fresh-') . '/data.sqlite';
        Install::fresh($this->installConfig($freshDb), 'setup@e.test', 'password123');

        $cli = new \Kip\Database('sqlite:' . $cliDb);
        $fresh = new \Kip\Database('sqlite:' . $freshDb);
        $this->assertSame(
            (int) $cli->one('SELECT COUNT(*) c FROM _migrations')['c'],
            (int) $fresh->one('SELECT COUNT(*) c FROM _migrations')['c'],
            'both paths run the identical migration inventory'
        );
        foreach (['ratings', 'tag_types', 'tags', 'categories', 'characters'] as $table) {
            $this->assertSame(
                $cli->all("SELECT * FROM {$table} ORDER BY 1"),
                $fresh->all("SELECT * FROM {$table} ORDER BY 1"),
                "starter taxonomy ({$table}) identical"
            );
        }
        $this->assertSame(
            $cli->all('SELECT slug, title, body FROM pages ORDER BY slug'),
            $fresh->all('SELECT slug, title, body FROM pages ORDER BY slug'),
            'starter pages identical (timestamps are naturally per-run)'
        );
        $this->assertSame(
            $cli->all('SELECT label, url, position FROM nav_links ORDER BY position'),
            $fresh->all('SELECT label, url, position FROM nav_links ORDER BY position'),
            'starter nav identical'
        );
        foreach ([$cli, $fresh] as $db) {
            $admin = $db->one('SELECT email, is_admin, role, email_verified_at, approved_at FROM users');
            $this->assertSame(1, (int) $admin['is_admin']);
            $this->assertSame('admin', $admin['role']);
            $this->assertNotNull($admin['email_verified_at'], 'the admin is born verified');
            $this->assertNotNull($admin['approved_at'], 'the admin is born approved');
            $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM stories')['c'], 'no demo content');
        }
    }

    public function test_install_fresh_refuses_a_second_admin(): void
    {
        $dbPath = $this->tmptree('kiption-fresh2-') . '/data.sqlite';
        $config = $this->installConfig($dbPath);
        Install::fresh($config, 'first@e.test', 'password123');
        try {
            Install::fresh($config, 'second@e.test', 'password123');
            $this->fail('a second fresh install must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('admin', $e->getMessage());
        }
        $db = new \Kip\Database('sqlite:' . $dbPath);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
    }

    public function test_install_fresh_validates_inputs_before_any_mutation(): void
    {
        foreach ([['not-an-email', 'password123'], ['a@e.test', 'short']] as [$email, $password]) {
            $dbPath = $this->tmptree('kiption-invalid-') . '/data.sqlite';
            try {
                Install::fresh($this->installConfig($dbPath), $email, $password);
                $this->fail("invalid input [{$email}/{$password}] must refuse");
            } catch (\InvalidArgumentException) {
            }
            if (is_file($dbPath)) {
                $db = new \Kip\Database('sqlite:' . $dbPath);
                $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
            }
        }
    }

    public function test_install_rolls_back_a_crash_after_register(): void
    {
        $dbPath = $this->tmptree('kiption-crash-') . '/data.sqlite';
        $config = $this->installConfig($dbPath);
        try {
            Install::fresh($config, 'crash@e.test', 'password123', afterRegister: function (): void {
                throw new \RuntimeException('injected crash before promotion');
            });
            $this->fail('the injected crash must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected crash before promotion', $e->getMessage());
        }
        $db = new \Kip\Database('sqlite:' . $dbPath);
        $this->assertSame(0, (int) $db->one("SELECT COUNT(*) c FROM users WHERE email = 'crash@e.test'")['c'],
            'rollback leaves no user with the proposed email');
        // A retry on the same (already-migrated, already-blank) database
        // creates exactly one admin.
        Install::fresh($config, 'crash@e.test', 'password123');
        $db = new \Kip\Database('sqlite:' . $dbPath);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $admin = $db->one("SELECT is_admin, email_verified_at, approved_at FROM users WHERE email = 'crash@e.test'");
        $this->assertSame(1, (int) $admin['is_admin']);
        $this->assertNotNull($admin['email_verified_at']);
        $this->assertNotNull($admin['approved_at']);
    }

    public function test_install_migration_dirs_refuse_a_broken_features_tree(): void
    {
        $home = $this->tmptree('kiption-mdirs-');
        file_put_contents($home . '/features-file', 'x');
        $this->assertSame(
            [$home . '/app/migrations'],
            Install::migrationDirs(['app_dir' => $home . '/app', 'features_dir' => $home . '/nowhere']),
            'a missing features tree falls back to the app migrations alone'
        );
        try {
            Install::migrationDirs(['app_dir' => $home . '/app', 'features_dir' => $home . '/features-file']);
            $this->fail('a features_dir that is a regular file must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing to migrate a partial inventory', $e->getMessage());
        }
    }

    // --------------------------------------------------------- browser setup

    /** Setup config for one disposable install state. */
    private function setupConfig(string $dataDir, array $overrides = []): array
    {
        return [...[
            'app_dir' => dirname(__DIR__) . '/app',
            'data_dir' => $dataDir,
            'db' => ['dsn' => 'sqlite:' . $dataDir . '/data.sqlite'],
            'public_dir' => $dataDir . '-public',
            'base_url' => 'http://localhost:8080',
            'mail' => ['transport' => 'log', 'log_path' => $dataDir . '/mail.log', 'from' => 'noreply@localhost'],
            'static_cache' => ['enabled' => false, 'dir' => $dataDir . '-public/cache'],
            'uploads' => ['dir' => $dataDir . '-public/uploads'],
            'backups' => ['dir' => $dataDir . '/backups'],
            'nav_file' => $dataDir . '/nav.json',
            // Test seams (production config.php never sets these): the mail
            // seam redirects the setup test send to a local log so a test can
            // complete a "successful send" without a live SMTP peer; the fault
            // and stall seams drive the recovery and lock tests.
            'setup_mail_log' => $dataDir . '/setup-mail.log',
        ], ...$overrides];
    }

    /** @return array{status:int, headers:list<string>, body:string} */
    private function callSetup(array $config, array $server, array $post, array &$session): array
    {
        static $loaded = false;
        if (!$loaded) {
            define('KIPTION_SETUP_LIBRARY_ONLY', 1);
            require_once dirname(__DIR__) . '/public/setup.php';
            $loaded = true;
        }
        return setup_handle([...$server], $post, [], $config, $session);
    }

    private function get(array $config, array &$session, array $server = []): array
    {
        return $this->callSetup($config, [
            'REQUEST_METHOD' => 'GET',
            'REMOTE_ADDR' => '127.0.0.1',
            ...$server,
        ], [], $session);
    }

    private function post(array $config, array $post, array &$session, array $server = []): array
    {
        return $this->callSetup($config, [
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '127.0.0.1',
            ...$server,
        ], $post, $session);
    }

    private function freshToken(array $config): string
    {
        $token = bin2hex(random_bytes(32));
        file_put_contents($this->tokenPath($config), $token . "\n");
        chmod($this->tokenPath($config), 0600);
        return $token;
    }

    private function tokenPath(array $config): string
    {
        return $config['data_dir'] . '/setup-token.txt';
    }

    /** A CSRF-paired session for POST tests. */
    private function sessionWithCsrf(array $config): array
    {
        $session = [];
        $this->get($config, $session);
        return $session;
    }

    /** @return array<string,string> the full valid operator POST body */
    private function goodPost(string $token, string $csrf): array
    {
        return [
            'setup_token' => $token,
            'csrf' => $csrf,
            'email' => 'owner@archive.example',
            'password' => 'correct horse battery',
            'confirm' => 'correct horse battery',
            'base_url' => 'https://Archive.Example.com/',
            'mail_transport' => 'smtp',
            'mail_from' => 'noreply@archive.example',
            'smtp_host' => 'smtp.archive.example',
            'smtp_port' => '587',
            'smtp_username' => 'archive',
            'smtp_password' => 'smtp-secret-123',
        ];
    }

    private function assertSetupClosed(array $config, string $stage, bool $deploymentMayExist = false): void
    {
        if (!$deploymentMayExist) {
            $this->assertFileDoesNotExist($config['data_dir'] . '/deployment.php', "{$stage}: deployment must not be written");
        }
        $this->assertFileDoesNotExist($config['data_dir'] . '/setup-complete.lock', "{$stage}: setup must not complete");
        $dbFile = substr((string) $config['db']['dsn'], strlen('sqlite:'));
        if (is_file($dbFile)) {
            $db = new \Kip\Database($config['db']['dsn']);
            $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM users')['c'], "{$stage}: no admin may exist");
        }
    }

    public function test_browser_setup_get(): void
    {
        $config = $this->setupConfig($this->tmptree('kiption-setup-'));
        $session = [];

        // No token: a controlled 404, never a traceback.
        $r = $this->get($config, $session);
        $this->assertSame(404, $r['status']);
        $this->assertStringContainsString('private, no-store', implode("\n", $r['headers']));

        // With the token: the form and a generic preflight summary only.
        $token = $this->freshToken($config);
        $r = $this->get($config, $session);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('setup_token', $r['body']);
        $this->assertStringContainsString('name="csrf"', $r['body']);
        $this->assertStringContainsString('private, no-store', implode("\n", $r['headers']));
        $this->assertStringNotContainsString($token, $r['body'], 'the token never renders');
        $this->assertStringNotContainsString($config['data_dir'], $r['body'], 'no filesystem paths');
        $this->assertStringNotContainsString('noreply@', $r['body'], 'no configuration values');

        // GET initializes nothing.
        $this->assertFileDoesNotExist(substr((string) $config['db']['dsn'], 7));
        $this->assertFileDoesNotExist($config['data_dir'] . '/deployment.php');
        $this->assertFileDoesNotExist($config['data_dir'] . '/setup-complete.lock');
        $this->assertFileExists($this->tokenPath($config), 'GET keeps the token usable');
    }

    public function test_browser_setup_post_token_failures(): void
    {
        $config = $this->setupConfig($this->tmptree('kiption-setup-'));
        $this->freshToken($config);
        $session = $this->sessionWithCsrf($config);
        $csrf = $session['setup_csrf'];

        foreach (['', str_repeat('a', 64), '../etc/passwd', bin2hex(random_bytes(31))] as $bad) {
            $body = $this->goodPost($bad, $csrf);
            unset($body['setup_token']);
            if ($bad !== '') {
                $body['setup_token'] = $bad;
            }
            $r = $this->post($config, $body, $session);
            $this->assertSame(403, $r['status'], "token [{$bad}] must fail");
            $this->assertStringNotContainsString($config['data_dir'], $r['body']);
        }
        $this->assertSetupClosed($config, 'bad tokens');
        $this->assertFileExists($this->tokenPath($config), 'failures keep the token');
        $this->assertFileExists($config['data_dir'] . '/setup-attempts', 'failures are rate-counted');

        // The tenth-minute window: the eleventh failure is turned away.
        for ($i = 0; $i < 10; $i++) {
            $r = $this->post($config, $this->goodPost('wrong', $csrf), $session);
            $this->assertContains($r['status'], [403, 429], 'attempt ' . $i);
        }
        $r = $this->post($config, $this->goodPost('wrong', $csrf), $session);
        $this->assertSame(429, $r['status'], 'the window closes after repeated failures');

        // Time passes; the window reopens.
        $later = [...$config, 'setup_now' => time() + 601];
        $r = $this->post($later, $this->goodPost('wrong', $csrf), $session);
        $this->assertSame(403, $r['status'], 'an expired window no longer rate-limits');
    }

    public function test_browser_setup_post_validations(): void
    {
        $config = $this->setupConfig($this->tmptree('kiption-setup-'));
        $token = $this->freshToken($config);
        $session = $this->sessionWithCsrf($config);
        $csrf = $session['setup_csrf'];

        $cases = [
            'plain http url' => ['base_url' => 'http://archive.example.com'],
            'localhost url' => ['base_url' => 'https://localhost/'],
            'userinfo url' => ['base_url' => 'https://u:p@archive.example.com/'],
            'query url' => ['base_url' => 'https://archive.example.com/?x=1'],
            'fragment url' => ['base_url' => 'https://archive.example.com/#f'],
            'path url' => ['base_url' => 'https://archive.example.com/forum'],
            'log transport' => ['mail_transport' => 'log'],
            'smtp missing host' => ['smtp_host' => ''],
            'smtp missing port' => ['smtp_port' => ''],
            'smtp missing username' => ['smtp_username' => ''],
            'smtp missing password' => ['smtp_password' => ''],
            'invalid admin email' => ['email' => 'not-an-email'],
            'short password' => ['password' => 'short', 'confirm' => 'short'],
            'invalid sender' => ['mail_from' => 'not-an-email'],
        ];
        foreach ($cases as $name => $override) {
            $r = $this->post($config, [...$this->goodPost($token, $csrf), ...$override], $session);
            $this->assertSame(400, $r['status'], "{$name} must fail validation: {$r['body']}");
            $this->assertStringNotContainsString('smtp-secret-123', $r['body'], 'no credential echo');
            $this->assertSetupClosed($config, $name);
        }
        $this->assertFileExists($this->tokenPath($config), 'validation failures keep the token usable');
    }

    public function test_browser_setup_csrf_and_origin(): void
    {
        $config = $this->setupConfig($this->tmptree('kiption-setup-'));
        $token = $this->freshToken($config);
        $session = $this->sessionWithCsrf($config);
        $csrf = $session['setup_csrf'];
        $good = $this->goodPost($token, $csrf);

        $r = $this->post($config, $good, $session, ['HTTP_ORIGIN' => 'https://evil.example']);
        $this->assertSame(403, $r['status'], 'a foreign Origin fails');
        $this->assertSetupClosed($config, 'foreign origin');

        $badCsrf = [...$good, 'csrf' => str_repeat('0', 64)];
        $r = $this->post($config, $badCsrf, $session, ['HTTP_ORIGIN' => 'https://archive.example.com']);
        $this->assertSame(403, $r['status'], 'a wrong CSRF token fails');

        $noCsrf = $good;
        unset($noCsrf['csrf']);
        $r = $this->post($config, $noCsrf, $session);
        $this->assertSame(403, $r['status'], 'a missing CSRF token fails');

        // Plain HTTP from a public address is refused before anything runs.
        $r = $this->post($config, $good, $session, ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_ORIGIN' => 'http://archive.example.com']);
        $this->assertSame(403, $r['status'], 'setup requires HTTPS off-loopback');
        $this->assertFileExists($this->tokenPath($config));
    }

    public function test_browser_setup_happy_path(): void
    {
        $config = $this->setupConfig($this->tmptree('kiption-setup-'));
        $token = $this->freshToken($config);
        $session = $this->sessionWithCsrf($config);

        $r = $this->post($config, $this->goodPost($token, $session['setup_csrf']), $session,
            ['HTTP_ORIGIN' => 'https://archive.example.com']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('invite', $r['body'], 'the completion page explains invite-only start');
        $this->assertStringContainsString('/settings', $r['body'], 'the completion page points at the settings board');
        $this->assertStringNotContainsString('smtp-secret-123', $r['body'], 'no credential echo');

        // The test send went out to the admin address.
        $mail = (string) file_get_contents($config['setup_mail_log']);
        $this->assertStringContainsString('owner@archive.example', $mail);
        $this->assertStringContainsString('Kiption mail test', $mail);

        // The private deployment record: canonical URL, public path, mail, invite mode.
        $deployment = require $config['data_dir'] . '/deployment.php';
        $this->assertSame('https://archive.example.com', $deployment['base_url'], 'URL is canonical (lowercase host, no path)');
        $this->assertSame($config['public_dir'], $deployment['public_dir']);
        $this->assertSame('smtp', $deployment['mail']['transport']);
        $this->assertSame(587, $deployment['mail']['port']);
        $this->assertSame('invite', $deployment['registration_mode']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($config['data_dir'] . '/deployment.php')), -4));

        // Exactly one admin, born verified and approved.
        $db = new \Kip\Database($config['db']['dsn']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $admin = $db->one("SELECT is_admin, email_verified_at, approved_at FROM users WHERE email = 'owner@archive.example'");
        $this->assertSame(1, (int) $admin['is_admin']);
        $this->assertNotNull($admin['email_verified_at']);
        $this->assertNotNull($admin['approved_at']);

        // Setup closes forever.
        $this->assertFileExists($config['data_dir'] . '/setup-complete.lock');
        $this->assertFileDoesNotExist($this->tokenPath($config));
        $this->assertSame(404, $this->get($config, $session)['status']);
        $this->assertSame(404, $this->post($config, $this->goodPost($token, $session['setup_csrf']), $session)['status']);
    }

    public function test_browser_setup_concurrent_posts_yield_one_winner(): void
    {
        $home = $this->tmptree('kiption-race-');
        $config = $this->setupConfig($home . '/shared');
        mkdir($config['data_dir'], 0775, true);
        mkdir($config['public_dir'], 0775, true);
        $token = $this->freshToken($config);
        file_put_contents($home . '/config.php', '<?php return ' . var_export($config, true) . ";\n");
        file_put_contents($home . '/harness.php', $this->harnessSource());

        $spec = fn (string $who): string => json_encode([
            'harness' => $home . '/harness.php', 'setup' => dirname(__DIR__) . '/public/setup.php',
            'config' => $home . '/config.php',
            'result' => $home . '/result-' . $who . '.json',
            'ready' => $home . '/ready-' . $who,
            'go' => $home . '/go',
            'post' => $this->goodPost($token, '{csrf}'),
            'origin' => 'https://archive.example.com',
            'server' => ['REMOTE_ADDR' => '127.0.0.1'],
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents($home . '/spec-a.json', $spec('a'));
        file_put_contents($home . '/spec-b.json', $spec('b'));
        $run = static function (string $specFile) use ($home): mixed {
            $pipes = [];
            $log = str_replace('.json', '.stderr.log', $specFile);
            $proc = proc_open([PHP_BINARY, $home . '/harness.php', $specFile],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']], $pipes);
            if (!is_resource($proc)) {
                fwrite(STDERR, "harness failed to start: {$specFile}\n");
                exit(1);
            }
            return $proc;
        };
        $pa = $run($home . '/spec-a.json');
        $pb = $run($home . '/spec-b.json');

        // Both racers fetch the form (CSRF), then fire the POST together.
        $deadline = microtime(true) + 15;
        while ((!is_file($home . '/ready-a') || !is_file($home . '/ready-b')) && microtime(true) < $deadline) {
            usleep(50000);
        }
        file_put_contents($home . '/go', '1');
        $deadline = microtime(true) + 90;
        while ((!is_file($home . '/result-a.json') || !is_file($home . '/result-b.json')) && microtime(true) < $deadline) {
            usleep(100000);
        }
        foreach ([$pa, $pb] as $p) {
            proc_terminate($p);
            proc_close($p);
        }

        $statuses = [];
        foreach (['a', 'b'] as $who) {
            $this->assertFileExists($home . "/result-{$who}.json", 'both racers finished');
            $statuses[] = json_decode((string) file_get_contents($home . "/result-{$who}.json"), true)['status'];
        }
        sort($statuses);
        $this->assertSame([200, 409], $statuses, 'exactly one winner: ' . json_encode($statuses));

        $db = new \Kip\Database($config['db']['dsn']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c'], 'one admin, not two');
        $this->assertFileExists($config['data_dir'] . '/setup-complete.lock');
        $this->assertFileDoesNotExist($this->tokenPath($config));
    }

    public function test_browser_setup_killed_worker_releases_the_lock(): void
    {
        $home = $this->tmptree('kiption-kill-');
        $config = $this->setupConfig($home . '/shared');
        mkdir($config['data_dir'], 0775, true);
        mkdir($config['public_dir'], 0775, true);
        $token = $this->freshToken($config);
        $stall = $home . '/stalled';
        $config['setup_test_stall'] = $stall;
        file_put_contents($home . '/config.php', '<?php return ' . var_export($config, true) . ";\n");
        file_put_contents($home . '/harness.php', $this->harnessSource());

        $spec = json_encode([
            'harness' => $home . '/harness.php', 'setup' => dirname(__DIR__) . '/public/setup.php',
            'config' => $home . '/config.php', 'result' => $home . '/result.json',
            'post' => $this->goodPost($token, '{csrf}'),
            'origin' => 'https://archive.example.com',
            'server' => ['REMOTE_ADDR' => '127.0.0.1'],
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents($home . '/spec.json', $spec);
        $pipes = [];
        $proc = proc_open([PHP_BINARY, $home . '/harness.php', $home . '/spec.json'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($proc);

        // It reaches the POST, takes the lock, and parks inside the mutation.
        $deadline = microtime(true) + 15;
        while (!is_file($stall) && microtime(true) < $deadline) {
            usleep(50000);
        }
        $this->assertFileExists($stall, 'the worker holds the lock');
        $status = proc_get_status($proc);
        exec('kill -9 ' . (int) $status['pid']);
        proc_close($proc);
        $this->assertFileExists($this->tokenPath($config), 'the kill leaves no completion');

        // The next valid POST acquires the released OS lock and finishes.
        $session = $this->sessionWithCsrf($config);
        $r = $this->post($config, $this->goodPost($token, $session['setup_csrf']), $session,
            ['HTTP_ORIGIN' => 'https://archive.example.com']);
        $this->assertSame(200, $r['status'], $r['body']);
        $db = new \Kip\Database($config['db']['dsn']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertFileExists($config['data_dir'] . '/setup-complete.lock');
    }

    public function test_browser_setup_fault_injection_converges_to_one_site(): void
    {
        foreach (['migrated', 'blank', 'deployed'] as $fault) {
            $config = $this->setupConfig($this->tmptree('kiption-fault-'));
            $token = $this->freshToken($config);
            $session = $this->sessionWithCsrf($config);

            // The faulted run reports a generic failure and keeps the token.
            $r = $this->post([...$config, 'setup_test_fault' => $fault],
                $this->goodPost($token, $session['setup_csrf']), $session,
                ['HTTP_ORIGIN' => 'https://archive.example.com']);
            $this->assertSame(500, $r['status'], "{$fault}: the fault surfaces as a server error");
            $this->assertFileExists($this->tokenPath($config), "{$fault}: token survives the fault");
            $this->assertStringNotContainsString('<?php', $r['body'], "{$fault}: no source or trace leak");
            $this->assertSetupClosed($config, $fault, $fault === 'deployed');

            // The retry (fault off) converges to exactly one complete site.
            $r2 = $this->post($config, $this->goodPost($token, $session['setup_csrf']), $session,
                ['HTTP_ORIGIN' => 'https://archive.example.com']);
            $this->assertSame(200, $r2['status'], "{$fault}: retry completes: {$r2['body']}");
            $db = new \Kip\Database($config['db']['dsn']);
            $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c'], "{$fault}: one admin");
            $this->assertFileExists($config['data_dir'] . '/setup-complete.lock');
            $this->assertFileDoesNotExist($this->tokenPath($config));
        }
    }

    public function test_browser_setup_admin_fault_goes_through_owner_recovery(): void
    {
        $config = $this->setupConfig($this->tmptree('kiption-recover-'));
        $token = $this->freshToken($config);
        $session = $this->sessionWithCsrf($config);

        // The crash lands after admin creation, before the completion marker.
        $r = $this->post([...$config, 'setup_test_fault' => 'admin'],
            $this->goodPost($token, $session['setup_csrf']), $session,
            ['HTTP_ORIGIN' => 'https://archive.example.com']);
        $this->assertSame(500, $r['status']);
        $db = new \Kip\Database($config['db']['dsn']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c'], 'the admin exists');
        $this->assertFileDoesNotExist($config['data_dir'] . '/setup-complete.lock');

        // GET now shows the owner-recovery form.
        $r = $this->get($config, $session);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('recover', strtolower($r['body']));

        // A token POST cannot create another admin: the gate denies it.
        $r = $this->post($config, $this->goodPost($token, $session['setup_csrf']), $session,
            ['HTTP_ORIGIN' => 'https://archive.example.com']);
        $this->assertSame(403, $r['status']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c']);

        // Wrong password fails closed.
        $r = $this->post($config, [
            'recover_email' => 'owner@archive.example', 'recover_password' => 'wrong-password',
            'csrf' => $session['setup_csrf'],
        ], $session);
        $this->assertSame(403, $r['status']);
        $this->assertFileDoesNotExist($config['data_dir'] . '/setup-complete.lock');

        // The owner finalizes with the real admin credentials.
        $r = $this->post($config, [
            'recover_email' => 'owner@archive.example', 'recover_password' => 'correct horse battery',
            'csrf' => $session['setup_csrf'],
        ], $session);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertFileExists($config['data_dir'] . '/setup-complete.lock');
        $this->assertFileDoesNotExist($this->tokenPath($config));
        $this->assertSame(404, $this->get($config, $session)['status']);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c'], 'recovery never duplicates the admin');
    }

    public function test_browser_setup_session_cookie_params(): void
    {
        $params = \App\Setup\SetupGate::sessionCookieParams();
        // The session option spellings, not ini names: session_start() ignores
        // unknown keys, so 'secure'/'httponly' would silently drop the flags.
        $this->assertTrue($params['cookie_secure']);
        $this->assertTrue($params['cookie_httponly']);
        $this->assertSame('Lax', $params['cookie_samesite']);
        $this->assertArrayNotHasKey('secure', $params);
        $this->assertArrayNotHasKey('httponly', $params);
        $this->assertArrayNotHasKey('samesite', $params);
    }

    /** The gate's defensive paths: corrupt state, vanished files, contention. */
    public function test_setup_gate_defensive_paths(): void
    {
        $home = $this->tmptree('kiption-gate-');
        $data = $home . '/shared';
        mkdir($data, 0775, true);
        $config = $this->setupConfig($data);

        // A corrupt database is "no admin", never a crash.
        file_put_contents($data . '/data.sqlite', 'garbage, not a database');
        $this->assertFalse(\App\Setup\SetupGate::hasAdmin($config));
        $this->assertFalse(Install::hasAdmin($config));

        // pending() is the file-only 503 predicate.
        $token = $this->freshToken($config);
        $this->assertTrue(\App\Setup\SetupGate::pending($config));
        file_put_contents($data . '/setup-complete.lock', "done\n");
        $this->assertFalse(\App\Setup\SetupGate::pending($config));
        unlink($data . '/setup-complete.lock');

        // authorize refuses a valid-shaped token with no file behind it, and
        // refuses even a real token once a completion marker exists.
        unlink($this->tokenPath($config));
        $this->assertFalse(\App\Setup\SetupGate::authorize($config, $token));
        file_put_contents($this->tokenPath($config), $token . "\n");
        file_put_contents($data . '/setup-complete.lock', "done\n");
        $this->assertFalse(\App\Setup\SetupGate::authorize($config, $token));
        unlink($data . '/setup-complete.lock');

        // The lock: an unwritable home, contention, and recovery.
        $this->assertNull(\App\Setup\SetupGate::acquireLock(['data_dir' => $home . '/nowhere']));
        $handle = fopen($data . '/setup.lock', 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $this->assertNull(\App\Setup\SetupGate::acquireLock($config), 'the OS lock excludes a second mutation');
        flock($handle, LOCK_UN);
        fclose($handle);
        $lock = \App\Setup\SetupGate::acquireLock($config);
        $this->assertNotNull($lock);
        flock($lock, LOCK_UN);
        fclose($lock);

        // Completion cannot half-land: an unwritable home refuses the marker.
        chmod($data, 0555);
        try {
            \App\Setup\SetupGate::complete($config);
            $this->fail('complete must refuse an unwritable home');
        } catch (\RuntimeException) {
        }
        // A failure count cannot be recorded without the file either.
        \App\Setup\SetupGate::recordFailure($config);
        $this->assertSame(0, \App\Setup\SetupGate::failures($config));
        chmod($data, 0775);

        // A corrupt attempts file reads as an empty window.
        file_put_contents($data . '/setup-attempts', 'not json');
        $this->assertSame(0, \App\Setup\SetupGate::failures($config));

        // Preflight's HTTPS posture: a real https URL passes without seams.
        $public = $home . '/public';
        mkdir($public, 0775, true);
        $r = Preflight::run($this->config($data, ['base_url' => 'https://archive.example.com']), $public);
        $byName = array_column($r['checks'], null, 'name');
        $this->assertTrue($byName['https']['ok']);
    }

    public function test_setup_state_serves_a_generic_503_from_the_front_controller(): void
    {
        $home = $this->tmptree('kiption-503gate-');
        $shared = $home . '/shared';
        $public = $home . '/public';
        mkdir($shared, 0775, true);
        mkdir($public, 0775, true);
        $prepend = $home . '/prepend.php';
        file_put_contents($prepend, '<?php
define("KIPTION_ROOT", ' . var_export(dirname(__DIR__), true) . ');
define("KIPTION_SHARED_DIR", ' . var_export($shared, true) . ');
define("KIPTION_PUBLIC_DIR", ' . var_export($public, true) . ');
');
        // Pending setup state: the front controller must 503 before anything else.
        file_put_contents($shared . '/setup-token.txt', bin2hex(random_bytes(32)) . "\n");

        $port = $this->freePort(8097);
        $handle = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-S', '127.0.0.1:' . $port, '-t', $public, dirname(__DIR__) . '/public/index.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertIsResource($handle);
        try {
            $deadline = microtime(true) + 10;
            while (@fsockopen('127.0.0.1', $port, timeout: 0.2) === false && microtime(true) < $deadline) {
                usleep(100000);
            }
            $this->assertSame(503, $this->httpStatus($port, '/'));
            $this->assertSame(503, $this->httpStatus($port, '/browse'));
            $body = (string) $this->httpBody($port, '/');
            $this->assertStringNotContainsString($home, $body, 'no filesystem paths in the gate');
            $this->assertSame([], glob($public . '/cache/*.html') ?: [], 'no setup response lands in the static cache');
        } finally {
            proc_terminate($handle);
            proc_close($handle);
        }
    }

    public function test_web_and_cli_boot_the_same_deployment(): void
    {
        $home = $this->tmptree('kiption-parity-deploy-');
        $shared = $home . '/shared';
        mkdir($shared, 0775, true);
        file_put_contents($shared . '/deployment.php', "<?php\nreturn [\n"
            . "    'public_dir' => " . var_export($home . '/public_html', true) . ",\n"
            . "    'base_url' => 'https://archive.example.com',\n"
            . "    'mail' => ['transport' => 'log', 'log_path' => " . var_export($shared . '/mail.log', true) . ", 'from' => 'noreply@archive.example'],\n"
            . "    'registration_mode' => 'invite',\n"
            . "];\n");
        // One subprocess per side: constants must not leak across tests.
        $read = static function (string $side) use ($shared): array {
            $code = 'define("KIPTION_SHARED_DIR", ' . var_export($shared, true) . ');'
                . '$c = require ' . var_export(dirname(__DIR__) . '/config.php', true) . ';'
                . 'echo json_encode(["base_url" => $c["base_url"], "mail" => $c["mail"], "registration_mode" => $c["registration_mode"]]);';
            $out = shell_exec(sprintf('%s -r %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($code)));
            $decoded = json_decode((string) $out, true);
            return is_array($decoded) ? $decoded : [];
        };
        foreach (['web' => 'w', 'cli' => 'c'] as $side) {
            $r = $read((string) $side);
            $this->assertSame('https://archive.example.com', $r['base_url'], "{$side} boots the canonical URL");
            $this->assertSame('invite', $r['registration_mode'], "{$side} boots invite-only");
            $this->assertSame('noreply@archive.example', $r['mail']['from'], "{$side} boots the recorded mail");
        }

        // After the operator opts into verify mode, registration links use the
        // canonical URL: one registration through the front controller.
        $config = $this->setupConfig($home . '/data', [
            'base_url' => 'https://archive.example.com',
            'registration_mode' => 'verify',
            'mail' => ['transport' => 'log', 'log_path' => $home . '/data/mail.log', 'from' => 'noreply@archive.example'],
            'db' => ['dsn' => 'sqlite:' . $home . '/data/data.sqlite'],
        ]);
        mkdir($home . '/data', 0775, true);
        Install::fresh($config, 'seed@archive.example', 'password123');
        $app = new \Kip\App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => $config['db'],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'base_url' => 'https://archive.example.com',
            'registration_mode' => 'verify',
            'mail' => $config['mail'],
            'features' => ['comments' => false, 'pms' => false],
        ]);
        $client = new \Kip\Testing\TestClient($app);
        $res = $client->post('/auth/store', ['penname' => 'rhea', 'email' => 'rhea@archive.example',
            'password' => 'password123', 'confirm' => 'password123']);
        $this->assertSame(302, $res->status, substr($res->body, 0, 300));
        $mail = (string) file_get_contents($home . '/data/mail.log');
        $this->assertStringContainsString('https://archive.example.com/auth/verify/', $mail);
    }

    private function freePort(int $start): int
    {
        for ($port = $start; $port < $start + 8; $port++) {
            $sock = @fsockopen('127.0.0.1', $port, timeout: 0.2);
            if ($sock === false) {
                return $port;
            }
            fclose($sock);
        }
        $this->fail('no free port in the ad-hoc range');
    }

    private function httpStatus(int $port, string $path): int
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        if ($body === false) {
            return 0;
        }
        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $code = (int) $m[1];
            }
        }
        return $code;
    }

    private function httpBody(int $port, string $path): string|false
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        return @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    }

    /** The child-process harness for the concurrency and killed-worker tests. */
    private function harnessSource(): string
    {
        return <<<'PHP'
<?php
define('KIPTION_SETUP_LIBRARY_ONLY', 1);
$spec = json_decode(file_get_contents($argv[1]), true);
require $spec['setup'];
$config = require $spec['config'];
$session = [];
$out = setup_handle(['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1'], [], [], $config, $session);
if (($out['status'] ?? 0) !== 200) {
    file_put_contents($spec['result'], json_encode($out));
    exit(0);
}
if (isset($spec['ready'])) {
    file_put_contents($spec['ready'], '1');
    $deadline = microtime(true) + 20;
    while (!is_file($spec['go']) && microtime(true) < $deadline) { usleep(20000); }
}
// The CSRF value only exists inside this process's session; the spec carries
// the '{csrf}' placeholder for it.
$post = array_map(static fn ($v) => $v === '{csrf}' ? (string) $session['setup_csrf'] : $v, $spec['post']);
$out = setup_handle(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_ORIGIN' => $spec['origin']] + $spec['server'], $post, [], $config, $session);
file_put_contents($spec['result'], json_encode($out));
PHP;
    }
}
