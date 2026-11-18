<?php // tests/SessionGuardTest.php
namespace App\Tests;
use Kip\Auth;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Session;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/** The per-request session check. It signs a member out only for what is wrong
 *  with the session, never for a database hiccup. */
#[Small]
final class SessionGuardTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-guard-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** A session for the first member the seed holds. */
    private function sessionFor(): array
    {
        $u = $this->db->one("SELECT id, password_hash FROM users WHERE role = 'member' AND is_locked = 0 ORDER BY id LIMIT 1");
        return ['user_id' => (int) $u['id'], 'pwd_epoch' => substr((string) $u['password_hash'], 0, Auth::EPOCH_LEN)];
    }

    public function test_a_good_session_is_valid(): void
    {
        $data = $this->sessionFor();
        $this->assertTrue(\App\SessionGuard::valid($this->db, new Session($data)));
    }

    public function test_a_missing_table_mid_deploy_reads_as_signed_out(): void
    {
        $data = $this->sessionFor();
        $this->db->query('DROP TABLE reading_history');
        $this->assertFalse(\App\SessionGuard::valid($this->db, new Session($data)));
    }

    /** Regression: ISSUE-004 / Found by /qa on 2026-11-18. A database error that
     *  is not about the schema (a busy or locked file) signed the member out. */
    public function test_other_database_errors_are_not_mistaken_for_a_bad_session(): void
    {
        $data = $this->sessionFor();
        $this->db->query('DROP TABLE reading_history');
        $this->db->query('CREATE VIEW reading_history AS SELECT abs(-9223372036854775808) AS user_id, 1 AS story_id, NULL AS marked_at');
        $this->expectException(\PDOException::class);
        \App\SessionGuard::valid($this->db, new Session($data));
    }
}
