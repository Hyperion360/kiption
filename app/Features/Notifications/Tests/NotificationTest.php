<?php // app/Features/Notifications/Tests/NotificationTest.php
namespace App\Features\Notifications\Tests;
use App\Notifications;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    private Database $db;
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-notif-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    public function test_create_and_inbox_roundtrip(): void
    {
        // second user: actor_id has an FK to users, and the seeder ships one user
        $this->db->query("INSERT INTO users (email, password_hash, penname) VALUES ('n2@e.test', ?, 'notifactor')",
            [password_hash('password123', PASSWORD_DEFAULT)]);
        $actor = (int) $this->db->lastInsertId();
        $n = new Notifications($this->db);
        $n->create(1, 'follow', null, $actor, null);
        usleep(2000); // distinct ms: created_at has ms precision and ties flip the order
        $n->create(1, 'kudos', 1, $actor, 'The Rabbit Hole'); // kudos LAST = newest
        $rows = $n->inboxRows(1);
        $this->assertCount(2, $rows);
        $this->assertSame('kudos', $rows[0]['kind']); // newest first, no same-ms tie
        $this->assertNull($rows[0]['read_at']);
    }

    public function test_mark_all_read(): void
    {
        $n = new Notifications($this->db);
        $n->create(1, 'kudos', 1, 1, 'The Rabbit Hole'); // actor = self (exists), self-notify guard is controller-side
        $n->markAllRead(1);
        $rows = $n->inboxRows(1);
        $this->assertNotNull($rows[0]['read_at']);
    }

    public function test_inbox_only_shows_own_rows(): void
    {
        $n = new Notifications($this->db);
        $n->create(1, 'kudos', 1, 1, 'The Rabbit Hole');
        $this->assertSame([], $n->inboxRows(999));
    }
}
