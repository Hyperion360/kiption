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
}
