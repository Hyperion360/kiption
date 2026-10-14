<?php // app/Features/Invites/Tests/InvitesTest.php
namespace App\Features\Invites\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The admin invite-code surface. Registration mode `invite` consumes codes
 *  in UserRepository::register; this surface is where codes come from. The
 *  bootstrap mirrors WranglingTest (tempnam sqlite, the app's migrations,
 *  the demo seed, a test-created admin). Every assertion resolves ids from
 *  the database, never from literals. */
final class InvitesTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private Database $db;
    private int $memberRowId = 0;
    private int $adminRowId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-invite-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-invite-mail-') . '.log';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->memberRowId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('inviteadmin@e.test', ?, 'inviteadmin', 'admin', 1, ?, ?, 'inviteadmin')",
            [$hash, date('c'), date('c')]);
        $this->adminRowId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        // The FeaturesTest discipline: reset the global resolver state.
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function memberId(): int
    {
        return $this->memberRowId;
    }

    private function adminId(): int
    {
        return $this->adminRowId;
    }

    private function client(?int $as = null, array $config = []): TestClient
    {
        $client = new TestClient(new App(array_merge([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-invite-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $config)));
        return $as === null ? $client : $client->actingAs($as);
    }

    /** A minted code, through the surface itself: POST /invites/create and
     *  read the row back. */
    private function mintCode(TestClient $admin): string
    {
        $this->assertSame(302, $admin->postWithToken('/invites/create')->status);
        return (string) $this->db->one('SELECT code FROM invites ORDER BY id DESC LIMIT 1')['code'];
    }

    public function test_guest_and_member_draw_forbidden(): void
    {
        $this->assertSame(403, $this->client(null)->get('/invites')->status);
        $this->assertSame(403, $this->client($this->memberId())->get('/invites')->status);
        // the write actions too: never a login redirect, never a mint
        $this->assertSame(403, $this->client(null)->postWithToken('/invites/create')->status);
        $this->assertSame(403, $this->client($this->memberId())->postWithToken('/invites/revoke/1')->status);
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM invites')['c'], 'nothing minted through the gate');
    }

    public function test_admin_sees_empty_index_and_creates_a_code(): void
    {
        $admin = $this->client($this->adminId());
        $html = $admin->get('/invites')->body;
        $this->assertStringContainsString('Invites', $html);
        $this->assertStringContainsString('No invite codes yet.', $html);
        $this->assertSame(302, $admin->postWithToken('/invites/create')->status);
        $row = $this->db()->one('SELECT code, used_by FROM invites');
        $this->assertNotNull($row['code']);
        $this->assertSame(16, strlen($row['code']), 'the code is 16 hex chars (random_bytes(8))');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $row['code']);
        $this->assertNull($row['used_by']);
        $this->assertStringContainsString($row['code'], $admin->get('/invites')->body);
    }

    public function test_revoke_removes_only_unused_codes(): void
    {
        $admin = $this->client($this->adminId());
        // an unused code revokes clean: the row is gone
        $code = $this->mintCode($admin);
        $unusedId = (int) $this->db()->one('SELECT id FROM invites WHERE code = ?', [$code])['id'];
        $this->assertSame(302, $admin->postWithToken('/invites/revoke/' . $unusedId)->status);
        $this->assertNull($this->db()->one('SELECT id FROM invites WHERE id = ?', [$unusedId]));
        // a consumed code survives revoke: the audit trail keeps used_by set
        $code = $this->mintCode($admin);
        [$ok] = (new \App\Repositories\UserRepository($this->db()))
            ->register('invite', 'invitedmember', 'invited@e.test', 'password123', $code);
        $this->assertTrue($ok, 'the minted code registers a member');
        $usedId = (int) $this->db()->one('SELECT id FROM invites WHERE code = ?', [$code])['id'];
        $usedBy = $this->db()->one('SELECT used_by FROM invites WHERE id = ?', [$usedId])['used_by'];
        $this->assertNotNull($usedBy);
        $this->assertSame(302, $admin->postWithToken('/invites/revoke/' . $usedId)->status);
        $row = $this->db()->one('SELECT used_by FROM invites WHERE id = ?', [$usedId]);
        $this->assertNotNull($row, 'a used code is never deleted');
        $this->assertSame((int) $usedBy, (int) $row['used_by']);
        // an unknown id still redirects (the delete is a no-op)
        $this->assertSame(302, $admin->postWithToken('/invites/revoke/999')->status);
    }

    public function test_created_code_actually_registers(): void
    {
        // The full loop pinned end to end: the surface mints a code, the
        // existing consumer (UserRepository::register through /auth/store)
        // accepts it, invites.used_by lands on the new member.
        $admin = $this->client($this->adminId());
        $code = $this->mintCode($admin);
        $guest = $this->client(null, ['registration_mode' => 'invite']);
        $res = $guest->postWithToken('/auth/store', [
            'penname' => 'invitee', 'email' => 'invitee@e.test',
            'password' => 'password123', 'confirm' => 'password123', 'invite' => $code,
        ]);
        $this->assertSame(302, $res->status, 'invite-mode registration lands in the member directory');
        $memberId = (int) $this->db()->one("SELECT id FROM users WHERE penname = 'invitee'")['id'];
        $this->assertGreaterThan(0, $memberId);
        $row = $this->db()->one('SELECT used_by, used_at FROM invites WHERE code = ?', [$code]);
        $this->assertSame($memberId, (int) $row['used_by']);
        $this->assertNotNull($row['used_at']);
        // the consumed code cannot register a second member
        [$ok, $error] = (new \App\Repositories\UserRepository($this->db()))
            ->register('invite', 'secondmember', 'second@e.test', 'password123', $code);
        $this->assertFalse($ok);
        $this->assertSame('Invalid invite code.', $error);
    }
}
