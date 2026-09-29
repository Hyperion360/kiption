<?php // app/Features/Auth/Tests/LegacyLoginTest.php
namespace App\Features\Auth\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class LegacyLoginTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private int $uid = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-lgl-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-lgl-mail-') . '.log';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        // a legacy-imported member: modern hash sentinel + legacy md5
        $db->query("INSERT INTO users (email, password_hash, penname, legacy_md5, role, email_verified_at, approved_at, profile_slug) VALUES ('legacy@e.test', ?, 'legacyimport', ?, 'member', ?, ?, 'legacyimport')",
            [password_hash('not-the-password', PASSWORD_DEFAULT), md5('oldpassword'), date('c'), date('c')]);
        $this->uid = (int) $db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
        @unlink($this->mailLog); @unlink(substr($this->mailLog, 0, -4));
    }

    private function client(): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-lgl-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        return new TestClient($app);
    }

    public function test_old_password_logs_in_rehashes_and_mails(): void
    {
        $res = $this->client()->post('/auth/attempt', ['email' => 'legacy@e.test', 'password' => 'oldpassword']);
        $this->assertSame(302, $res->status, $res->body);
        $db = new Database('sqlite:' . $this->path);
        $row = $db->one('SELECT password_hash, legacy_md5 FROM users WHERE id = ?', [$this->uid]);
        $this->assertNull($row['legacy_md5'], 'legacy hash cleared');
        $this->assertTrue(password_verify('oldpassword', (string) $row['password_hash']), 'modern hash works');
        $mail = file_get_contents($this->mailLog);
        $this->assertStringContainsString('legacy@e.test', $mail);
        $this->assertStringContainsString('still works', $mail);
    }

    public function test_wrong_password_and_modern_flow_untouched(): void
    {
        $res = $this->client()->post('/auth/attempt', ['email' => 'legacy@e.test', 'password' => 'wrong']);
        $this->assertNotSame(302, $res->status);
        $db = new Database('sqlite:' . $this->path);
        $this->assertNotNull($db->one('SELECT legacy_md5 FROM users WHERE id = ?', [$this->uid])['legacy_md5'], 'hash untouched on failure');
        // the seeder's modern user still logs in normally
        $res2 = $this->client()->post('/auth/attempt', ['email' => 'demo@example.test', 'password' => 'password123']);
        $this->assertSame(302, $res2->status);
    }

    public function test_throttle_semantics_one_row_per_failure_upgrade_nets_zero(): void
    {
        $client = $this->client();
        // wrong password: the conditional retry never fires, so exactly ONE
        // login_attempts row burns (the md5 hook cannot be driven for free)
        $client->post('/auth/attempt', ['email' => 'legacy@e.test', 'password' => 'wrong']);
        $db = new Database('sqlite:' . $this->path);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM login_attempts')['c'], 'a modern failure costs exactly one row');
        // successful upgrade: the successful second attempt clears the first
        // failure's row, so the whole upgrade nets ZERO throttle rows
        $res = $client->post('/auth/attempt', ['email' => 'legacy@e.test', 'password' => 'oldpassword']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM login_attempts')['c'], 'the upgrade nets zero rows');
    }
}
