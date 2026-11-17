<?php // tests/SessionGuardBadgesTest.php
namespace App\Tests;
use App\SessionGuard;
use Kip\Database;
use Kip\Session;
use PHPUnit\Framework\TestCase;

// The site header shows an Inbox count and, for staff, a waiting count. Both
// ride the one users row the session check already reads, as scalar
// subqueries, so no page pays an extra query for them.
final class SessionGuardBadgesTest extends TestCase
{
    private Database $db;
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-badges-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function session(int $id): Session
    {
        $store = [];
        $s = new Session($store);
        $hash = (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash'];
        $s->set('user_id', $id);
        $s->set('pwd_epoch', substr($hash, 0, 12));
        return $s;
    }

    private function member(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    public function test_unread_notifications_and_messages_are_counted_in_the_same_query(): void
    {
        $id = $this->member();
        $this->db->query('DELETE FROM notifications WHERE user_id = ?', [$id]);
        $this->db->query('DELETE FROM messages WHERE recipient_id = ?', [$id]);
        $this->db->query("INSERT INTO notifications (user_id, kind, story_title) VALUES (?, 'kudos', 'A')", [$id]);
        $this->db->query("INSERT INTO notifications (user_id, kind, story_title, read_at) VALUES (?, 'kudos', 'B', '2026-01-01')", [$id]);
        $this->db->query("INSERT INTO messages (sender_id, recipient_id, body) VALUES (?, ?, 'hi')", [$id, $id]);
        $seen = [];
        $tapped = new Database('sqlite:' . $this->path);
        $tapped->onQuery(static function (string $sql) use (&$seen): void { $seen[] = $sql; });
        $this->assertTrue(SessionGuard::valid($tapped, $this->session($id)));
        $this->assertSame([SessionGuard::SQL], $seen, 'one statement, the exempt one');
        $this->assertSame(1, SessionGuard::unreadNotifications());
        $this->assertSame(1, SessionGuard::unreadMessages());
        $this->assertSame(0, SessionGuard::waiting(), 'a plain member has no waiting count');
        $this->assertSame('betafriend', SessionGuard::penname());
        $this->assertNotSame('', SessionGuard::profileSlug());
    }

    public function test_staff_get_a_waiting_count_and_members_do_not(): void
    {
        $id = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $this->db->query("INSERT INTO reports (story_id, reason) SELECT id, 'x' FROM stories LIMIT 1");
        \App\Adminness::setRole($this->db, $id, 'admin');
        SessionGuard::valid($this->db, $this->session($id));
        $admin = SessionGuard::waiting();
        $this->assertGreaterThanOrEqual(1, $admin);
        \App\Adminness::setRole($this->db, $id, 'member');
        SessionGuard::valid($this->db, $this->session($id));
        $this->assertSame(0, SessionGuard::waiting());
    }

    public function test_a_failed_check_clears_the_badge_state(): void
    {
        SessionGuard::valid($this->db, $this->session($this->member()));
        $store = [];
        $this->assertFalse(SessionGuard::valid($this->db, new Session($store)));
        $this->assertSame(0, SessionGuard::unreadNotifications());
        $this->assertSame('', SessionGuard::penname());
        $this->assertSame('', SessionGuard::role());
    }
}
