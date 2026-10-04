<?php // tests/CliTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** End-to-end CLI checks: bin/kip runs against a throwaway DB via KIP_DB_DSN. */
final class CliTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-cli-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** @return array{0: int, 1: string} exit code, stdout */
    private function kip(string $args, string $cacheDsn = '', string $extraEnv = ''): array
    {
        // KIP_STATIC_CACHE_DIR always points at a throwaway dir: cache:clear
        // purges the static layer too, and the live public/cache must never
        // be touched from a test (the same isolation KIP_CACHE_DB_DSN gives
        // the framework cache database).
        $staticDir = $this->path . '-static';
        if (!is_dir($staticDir)) { mkdir($staticDir); }
        $cmd = sprintf('KIP_DB_DSN=%s%s KIP_STATIC_CACHE_DIR=%s %s %s %s %s 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            $cacheDsn === '' ? '' : ' KIP_CACHE_DB_DSN=' . escapeshellarg($cacheDsn),
            escapeshellarg($staticDir),
            $extraEnv,
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'),
            $args);
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_user_create_makes_an_active_admin_who_can_log_in(): void
    {
        $this->kip('migrate');
        [$code, $out] = $this->kip('user:create cli@e.test password123 --admin');
        $this->assertSame(0, $code, $out);

        $db = new Database('sqlite:' . $this->path);
        $row = $db->one("SELECT role, is_admin, email_verified_at, approved_at FROM users WHERE email = 'cli@e.test'");
        $this->assertNotNull($row, 'user was created');
        $this->assertSame('admin', $row['role']);          // phase-1 iff invariant
        $this->assertSame(1, (int) $row['is_admin']);
        $this->assertNotNull($row['email_verified_at']);   // else the login gates dead-end a CLI account
        $this->assertNotNull($row['approved_at']);

        // The bootstrap admin can actually log in (no verify/approval gate).
        $app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-cli-mail.log', 'from' => 'noreply@localhost'],
        ]);
        $res = (new TestClient($app))->post('/auth/attempt', ['email' => 'cli@e.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status, 'CLI-created admin must pass the login gates: ' . substr($res->body, 0, 200));
    }

    public function test_rollback_undoes_the_last_batch_and_migrate_restores_it(): void
    {
        $this->kip('migrate');
        [$code, $out] = $this->kip('rollback'); // one batch spans app/migrations AND app/Features/*/migrations
        $this->assertSame(0, $code, $out);
        preg_match('/^Rolled back: (.+)$/m', $out, $m);
        $names = array_filter(array_map('trim', explode(',', $m[1] ?? '')));
        $this->assertNotEmpty($names, 'rollback must name at least one migration, got: ' . $out);
        foreach ($names as $n) {
            $this->assertMatchesRegularExpression('/^\d{3}_/', $n, "not a migration name: {$n}");
        }

        // the fixture DB must end fully migrated for any later assertions
        [$code, $out] = $this->kip('migrate');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Ran: ', $out);
    }

    public function test_import_token_prints_token_and_hash_file_line(): void
    {
        [$code, $out] = $this->kip('import:token'); // CliTest's kip() returns [exit, stdout]
        $this->assertSame(0, $code);
        $this->assertStringContainsString('export-token.php', $out);
        $this->assertMatchesRegularExpression('/token: [a-f0-9]{64}/', $out);
        $this->assertMatchesRegularExpression("/<\?php return '[a-f0-9]{64}';/", $out);
        // the printed hash is the sha256 of the printed token
        preg_match('/token: ([a-f0-9]{64})/', $out, $m);
        preg_match("/return '([a-f0-9]{64})';/", $out, $h);
        $this->assertSame(hash('sha256', $m[1]), $h[1]);
    }

    public function test_import_token_is_random_per_invocation(): void
    {
        [, $a] = $this->kip('import:token');
        [, $b] = $this->kip('import:token');
        $this->assertNotSame($a, $b);
    }

    public function test_cache_clear_deletes_the_page_cache_db_and_its_sidecars(): void
    {
        $dir = sys_get_temp_dir() . '/kiption-cli-cache-' . uniqid();
        mkdir($dir);
        $file = $dir . '/cache.sqlite';
        foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
            file_put_contents($f, 'x');
        }
        [$code, $out] = $this->kip('cache:clear', 'sqlite:' . $file);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('framework page cache: ' . $file, $out,
            'both cache layers are named in the clear output');
        $this->assertStringContainsString('static layer:', $out);
        foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
            $this->assertFileDoesNotExist($f, "{$f} must be gone");
        }
        // Absent files are a silent success: exit 0, no error text.
        [$code, $out] = $this->kip('cache:clear', 'sqlite:' . $file);
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('error', strtolower($out));
        // A memory cache has no file to clear: the framework layer is
        // skipped (no framework line) while the static layer still purges.
        [$code, $out] = $this->kip('cache:clear', 'sqlite::memory:');
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('framework page cache', $out);
        $this->assertStringContainsString('static layer:', $out);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function test_doctor_passes_end_to_end_and_names_a_broken_install(): void
    {
        $this->kip('migrate');
        // Healthy: fully migrated temp DB, throwaway cache and backup dirs.
        [$code, $out] = $this->kip('doctor', '', 'KIP_BACKUP_DIR=' . escapeshellarg($this->path . '-backups'));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('All checks passed', $out);
        // Machine-readable mode decodes and agrees with the human run.
        [$code, $json] = $this->kip('doctor --json', '', 'KIP_BACKUP_DIR=' . escapeshellarg($this->path . '-backups'));
        $this->assertSame(0, $code, $json);
        $this->assertTrue(json_decode($json, true)['ok']);
        // A static cache path nothing can create (a regular file in the way)
        // fails the named check with its fix and exits 1.
        $blocker = $this->path . '-blocker';
        file_put_contents($blocker, 'x');
        $cmd = sprintf('KIP_DB_DSN=%s KIP_STATIC_CACHE_DIR=%s KIP_BACKUP_DIR=%s %s %s doctor 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg($blocker . '/cache'),
            escapeshellarg($this->path . '-backups'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'));
        exec($cmd, $sabotage, $scode);
        $sabotageOut = implode("\n", $sabotage);
        $this->assertSame(1, $scode, $sabotageOut);
        $this->assertStringContainsString('[FAIL] static cache writable', $sabotageOut);
        $this->assertStringContainsString('1 check(s) failed', $sabotageOut);
        @unlink($blocker);
    }
}
