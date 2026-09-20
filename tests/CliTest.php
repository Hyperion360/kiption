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
    private function kip(string $args): array
    {
        $cmd = sprintf('KIP_DB_DSN=%s %s %s %s 2>&1',
            escapeshellarg('sqlite:' . $this->path),
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
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-cli-mail.log', 'from' => 'noreply@localhost'],
        ]);
        $res = (new TestClient($app))->post('/auth/attempt', ['email' => 'cli@e.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status, 'CLI-created admin must pass the login gates: ' . substr($res->body, 0, 200));
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
}
