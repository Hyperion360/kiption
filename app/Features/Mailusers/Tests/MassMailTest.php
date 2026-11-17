<?php // app/Features/Mailusers/Tests/MassMailTest.php
namespace App\Features\Mailusers\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\MailGuard;
use App\MassMail;
use Kip\Database;
use Kip\Mailer;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** The recipient rule and the ten-minute broadcast slot, without the screen. */
#[Medium]
final class MassMailTest extends TestCase
{
    private string $path = '';
    private string $log = '';
    private Database $db;
    /** @var list<int> */
    private array $admins = [];

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::path('kiption-mm-');
        $this->log = tempnam(sys_get_temp_dir(), 'kiption-mm-log-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->db->query('DELETE FROM users');
        // two locked users to stand in as the sending admins; locked, so never recipients
        $this->member('admin-one@e.test', ['is_locked' => 1]);
        $this->member('admin-two@e.test', ['is_locked' => 1]);
        $this->admins = array_map('intval', array_column($this->db->all('SELECT id FROM users ORDER BY id'), 'id'));
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm'); @unlink($this->log);
    }

    private function member(string $email, array $over = []): void
    {
        $row = $over + ['penname' => explode('@', $email)[0], 'is_locked' => 0, 'approved_at' => '2026-01-01', 'email_verified_at' => '2026-01-01'];
        $this->db->query('INSERT INTO users (email, password_hash, penname, is_locked, approved_at, email_verified_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$email, 'x', $row['penname'], $row['is_locked'], $row['approved_at'], $row['email_verified_at']]);
    }

    public function test_only_approved_verified_unlocked_members_with_a_penname_are_recipients_oldest_first(): void
    {
        $this->member('first@e.test');
        $this->member('locked@e.test', ['is_locked' => 1]);
        $this->member('unapproved@e.test', ['approved_at' => null]);
        $this->member('unverified@e.test', ['email_verified_at' => null]);
        $this->member('nopen@e.test', ['penname' => null]);
        $this->member('second@e.test');
        $this->assertSame(['first@e.test', 'second@e.test'], MassMail::recipients($this->db));
    }

    public function test_no_members_means_no_recipients_and_nothing_sent(): void
    {
        $this->assertSame([], MassMail::recipients($this->db));
        $guard = MailGuard::off($this->db, new Mailer(['transport' => 'log', 'log_path' => $this->log, 'from' => 'a@e.test']));
        $this->assertSame(0, MassMail::send($guard, [], 'S', 'B'));
        $this->assertSame('', (string) file_get_contents($this->log));
    }

    public function test_a_transport_failure_for_every_recipient_is_survived_and_counts_zero(): void
    {
        $guard = MailGuard::off($this->db, new Mailer(['transport' => 'nonsense', 'from' => 'a@e.test']));
        $previous = ini_set('error_log', $this->log);
        try {
            $this->assertSame(0, MassMail::send($guard, ['a@e.test', 'b@e.test', 'c@e.test'], 'S', 'B'));
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertSame(3, substr_count((string) file_get_contents($this->log), 'mail:users failed for one recipient'), 'each failure logged, none stops the batch');
        $this->assertStringNotContainsString('@e.test', (string) file_get_contents($this->log), 'addresses never reach the log');
    }

    public function test_the_slot_is_free_once_the_last_broadcast_is_older_than_the_cooldown(): void
    {
        $this->assertTrue(MassMail::claim($this->db, $this->admins[0], 'One', 5), 'first ever broadcast');
        $this->assertFalse(MassMail::claim($this->db, $this->admins[0], 'Two', 5), 'right after');
        $this->db->query('UPDATE mass_mail_log SET sent_at = ?', [gmdate('c', time() - MassMail::COOLDOWN + 30)]);
        $this->assertFalse(MassMail::claim($this->db, $this->admins[0], 'Three', 5), 'still inside the window');
        $this->db->query('UPDATE mass_mail_log SET sent_at = ?', [gmdate('c', time() - MassMail::COOLDOWN - 30)]);
        $this->assertTrue(MassMail::claim($this->db, $this->admins[0], 'Four', 7), 'just outside the window');
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM mass_mail_log')['c'], 'refused claims leave no row');
        $this->assertSame(7, (int) $this->db->one("SELECT recipients FROM mass_mail_log WHERE subject = 'Four'")['recipients']);
    }

    public function test_the_cooldown_is_archive_wide_not_per_admin(): void
    {
        $this->assertTrue(MassMail::claim($this->db, $this->admins[0], 'One', 5));
        $this->assertFalse(MassMail::claim($this->db, $this->admins[1], 'Two', 5), 'a different admin is held back too');
    }
}
