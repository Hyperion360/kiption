<?php // tests/RegisterTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class RegisterTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-reg-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-mail-') . '.log';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
    }

    private function client(array $overrides = []): TestClient
    {
        $config = array_merge([
            'env' => 'prod',
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'site_name' => 'Kiption',
            'base_url' => 'https://archive.example',
        ], $overrides);
        return new TestClient(new App($config));
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'penname' => 'newperson', 'email' => 'n@e.test', 'password' => 'password123', 'confirm' => 'password123',
        ], $overrides);
    }

    private function verifyTokenFromMail(): string
    {
        $log = (string) file_get_contents($this->mailLog);
        $ok = preg_match_all('#/auth/verify/([0-9a-f]{64})#', $log, $m);
        $this->assertGreaterThan(0, $ok, 'verification mail was written');
        return $m[1][count($m[1]) - 1]; // LAST token wins (per-test log, no stale-run contamination)
    }

    public function test_open_mode_registers_active(): void
    {
        $res = $this->client(['registration_mode' => 'open'])->post('/auth/store', $this->form());
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT * FROM users WHERE email = 'n@e.test'");
        $this->assertNotNull($row['email_verified_at']);
        $this->assertNotNull($row['approved_at']);
        $this->assertSame('member', $row['role']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM user_prefs WHERE user_id = ?", [$row['id']])['c']);
    }

    public function test_verify_mode_gates_login_until_token(): void
    {
        $client = $this->client();
        $client->post('/auth/store', $this->form(['email' => 'v@e.test', 'penname' => 'verifyme']));
        $login = $client->post('/auth/attempt', ['email' => 'v@e.test', 'password' => 'password123']);
        $this->assertStringContainsString('verify your email', $login->body);

        $token = $this->verifyTokenFromMail();
        $res = $client->get('/auth/verify/' . $token);
        $this->assertSame(302, $res->status);
        $row = $this->db->one("SELECT * FROM users WHERE email = 'v@e.test'");
        $this->assertNotNull($row['email_verified_at']);
        $login = $client->post('/auth/attempt', ['email' => 'v@e.test', 'password' => 'password123']);
        $this->assertSame(302, $login->status);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM email_verifications")['c']);
    }

    public function test_expired_verification_deletes_never_activated_account(): void
    {
        $client = $this->client();
        $client->post('/auth/store', $this->form(['email' => 'g@e.test', 'penname' => 'ghost']));
        $raw = $this->verifyTokenFromMail();
        $this->db->query("UPDATE email_verifications SET expires_at = '2000-01-01T00:00:00Z' WHERE email = 'g@e.test'");
        $client->get('/auth/verify/' . $raw);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE email = 'g@e.test'")['c']);
    }

    public function test_approval_mode_gates_login(): void
    {
        $client = $this->client(['registration_mode' => 'approval']);
        $client->post('/auth/store', $this->form(['email' => 'w@e.test', 'penname' => 'waiting']));
        $login = $client->post('/auth/attempt', ['email' => 'w@e.test', 'password' => 'password123']);
        $this->assertStringContainsString('awaiting approval', $login->body);
        $this->db->query("UPDATE users SET approved_at = '2026-01-01T00:00:00Z' WHERE email = 'w@e.test'");
        $login = $client->post('/auth/attempt', ['email' => 'w@e.test', 'password' => 'password123']);
        $this->assertSame(302, $login->status);
    }

    public function test_invite_mode_requires_unused_code(): void
    {
        $client = $this->client(['registration_mode' => 'invite']);
        $res = $client->post('/auth/store', $this->form(['invite' => 'nope']));
        $this->assertStringContainsString('Invalid invite code', $res->body);
        $this->db->query("INSERT INTO invites (code, created_by) VALUES ('abc123', 1)");
        $res = $client->post('/auth/store', $this->form(['invite' => 'abc123']));
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT used_by, used_at FROM invites WHERE code = 'abc123'");
        $this->assertNotNull($row['used_by']);
        $this->assertNotNull($row['used_at']);
        $res = $client->post('/auth/store', $this->form(['penname' => 'inv2', 'email' => 'i2@e.test', 'invite' => 'abc123']));
        $this->assertStringContainsString('Invalid invite code', $res->body);
    }

    public function test_penname_rules_and_collisions(): void
    {
        $client = $this->client();
        $res = $client->post('/auth/store', $this->form(['penname' => 'DeMo Author']));
        $this->assertStringContainsString('Penname is already taken', $res->body); // NOCASE unique
        $res = $client->post('/auth/store', $this->form(['penname' => 'bad name!']));
        $this->assertStringContainsString('Penname must be 3-30 characters', $res->body);
        $res = $client->post('/auth/store', $this->form(['penname' => 'Upper']));
        $this->assertStringContainsString('lowercase letters', $res->body);
        $res = $client->post('/auth/store', $this->form(['email' => 'demo@example.test']));
        $this->assertStringContainsString('Email is already registered', $res->body);
        $res = $client->post('/auth/store', $this->form(['password' => 'short', 'confirm' => 'short']));
        $this->assertStringContainsString('at least 8 characters', $res->body);
        $res = $client->post('/auth/store', $this->form(['confirm' => 'different']));
        $this->assertStringContainsString('Passwords do not match', $res->body);
    }

    public function test_register_view_is_noindexed(): void
    {
        $res = $this->client()->get('/auth/register');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('name="robots" content="noindex"', $res->body);
    }
}
