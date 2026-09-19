<?php // tests/AdminnessTest.php
namespace App\Tests;
use App\Adminness;
use Kip\Database;
use Kip\Http\Response;
use Kip\Session;
use PHPUnit\Framework\TestCase;

final class AdminnessTest extends TestCase
{
    private Database $db;
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-adminness-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    public function test_set_role_enforces_the_iff_invariant(): void
    {
        $id = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        foreach (['member', 'validated_author', 'moderator', 'admin'] as $role) {
            Adminness::setRole($this->db, $id, $role);
            $row = $this->db->one('SELECT role, is_admin FROM users WHERE id = ?', [$id]);
            $this->assertSame($role, $row['role']);
            $this->assertSame($role === 'admin' ? 1 : 0, (int) $row['is_admin']);
        }
    }

    public function test_set_role_rejects_unknown_roles(): void
    {
        $id = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $this->expectException(\InvalidArgumentException::class);
        Adminness::setRole($this->db, $id, 'superuser');
    }

    public function test_seeded_users_satisfy_the_invariant(): void
    {
        $this->assertSame([], $this->db->all(
            "SELECT id FROM users WHERE (role = 'admin') <> (is_admin = 1)"));
    }

    public function test_moderator_gate_fail_closed(): void
    {
        $store = [];
        $session = new Session($store);
        $this->assertInstanceOf(Response::class, Adminness::requireModerator($this->db, $session));
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('m@e.test', ?, 'plainperson', ?, ?)",
            [password_hash('x1234567', PASSWORD_DEFAULT), date('c'), date('c')]);
        $member = (int) $this->db->lastInsertId();
        $session->set('user_id', $member);
        $this->assertInstanceOf(Response::class, Adminness::requireModerator($this->db, $session));
        $demo = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        Adminness::setRole($this->db, $demo, 'moderator');
        $session->set('user_id', $demo);
        $row = Adminness::requireModerator($this->db, $session);
        $this->assertIsArray($row);
        $this->assertSame('moderator', $row['role']);
    }

    public function test_moderator_gate_survives_a_broken_users_table(): void
    {
        // The PDOException arm: any database failure is a 403, never a 500
        // and never an accidental pass-through.
        $store = [];
        $session = new Session($store);
        $session->set('user_id', 1);
        $this->db->query('DROP TABLE users');
        $res = Adminness::requireModerator($this->db, $session);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(403, $res->status);
    }
}
