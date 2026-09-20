<?php // tests/DigestTest.php
namespace App\Tests;
use App\Digest;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class DigestTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private Database $db;
    private int $fanId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-dig-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-dig-mail-') . '.log';
        touch($this->mailLog);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('dg@e.test', ?, 'digestfan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->fanId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO user_prefs (user_id) VALUES (?)', [$this->fanId]); // Digest INNER JOINs user_prefs; raw INSERTs create none
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
    }

    private function client(int $as): TestClient
    {
        return (new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-dig-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->actingAs($as);
    }

    public function test_favoriter_gets_site_notification_on_publish(): void
    {
        $this->client($this->fanId)->postWithToken('/favorites/toggle/the-rabbit-hole');
        $this->db->query('DELETE FROM notifications');
        $this->client(1)->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'More', 'content' => 'Falling further still.', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE kind = 'update' AND user_id = ?", [$this->fanId])['c']);
    }

    public function test_digest_send_batches_unread_and_marks(): void
    {
        $this->client($this->fanId)->postWithToken('/follow/author/1');
        $this->client($this->fanId)->postWithToken('/follow/mode/1'); // email
        $this->client($this->fanId)->postWithToken('/follow/mode/1'); // digest
        $this->db->query('DELETE FROM notifications');
        $this->client(1)->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'DigestMe', 'content' => 'One more chapter.', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame('', (string) file_get_contents($this->mailLog)); // digest mode: no immediate mail
        $sent = (new Digest($this->db, new \Kip\Mailer(['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost']), 'https://archive.example'))->send();
        $this->assertGreaterThanOrEqual(1, $sent);
        $mail = (string) file_get_contents($this->mailLog);
        $this->assertStringContainsString('The Rabbit Hole', $mail);
        $row = $this->db->one('SELECT digest_sent_at FROM user_prefs WHERE user_id = ?', [$this->fanId]);
        $this->assertNotNull($row['digest_sent_at']);
    }

    public function test_digest_marker_format_suppresses_remail(): void
    {
        $this->client($this->fanId)->postWithToken('/follow/author/1');
        $this->client($this->fanId)->postWithToken('/follow/mode/1'); // email
        $this->client($this->fanId)->postWithToken('/follow/mode/1'); // digest
        $this->db->query('DELETE FROM notifications');
        $this->client(1)->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'OnceOnly', 'content' => 'Batched exactly once.', 'notes_before' => '', 'notes_after' => '']);
        $digest = new Digest($this->db, new \Kip\Mailer(['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost']), 'https://archive.example');
        $this->assertGreaterThanOrEqual(1, $digest->send());
        $mail = (string) file_get_contents($this->mailLog);
        $this->assertNotSame('', $mail); // something was really mailed
        // No new notifications exist: the marker must be byte-comparable with
        // notifications.created_at (SQLite's own strftime format) so the next
        // run's string comparison filters the already-batched rows exactly.
        $this->assertSame(0, $digest->send());
        $this->assertSame($mail, (string) file_get_contents($this->mailLog));
    }
}
