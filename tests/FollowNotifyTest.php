<?php // tests/FollowNotifyTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class FollowNotifyTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private string $mailLog = '';
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-fnotify-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-fnotify-mail-') . '.log';
        touch($this->mailLog); // site mode sends no mail; the empty-log assertion reads a real empty file
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('fn@e.test', ?, 'followerone', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->memberId = (int) $this->db->lastInsertId();
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
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->actingAs($as);
    }

    public function test_site_mode_notifies_inbox_only(): void
    {
        $this->client($this->memberId)->postWithToken('/follow/author/1');
        $this->db->query('DELETE FROM notifications'); // drop the follow notification itself
        $this->client(1)->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'Again', 'content' => 'Back down once more.', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE kind = 'update' AND user_id = ?", [$this->memberId])['c']);
        $this->assertSame('', (string) file_get_contents($this->mailLog)); // site mode sends no mail
    }

    public function test_email_mode_mails_and_queue_approval_notifies(): void
    {
        $client = $this->client($this->memberId);
        $client->postWithToken('/follow/author/1');
        $client->postWithToken('/follow/mode/1'); // site -> email
        $this->db->query('DELETE FROM notifications');
        // queue approval of a re-opened chapter fans out too
        \App\Adminness::setRole($this->db, 1, 'moderator');
        $this->db->query("UPDATE chapters SET validated = 0 WHERE position = 1 AND story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole')");
        $chId = (int) $this->db->one("SELECT id FROM chapters WHERE position = 1 AND story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole')")['id'];
        $this->client(1)->postWithToken('/queue/chapter/' . $chId . '/approve');
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE kind = 'update' AND user_id = ?", [$this->memberId])['c']);
        $mail = (string) file_get_contents($this->mailLog);
        $this->assertStringContainsString('The Rabbit Hole', $mail);
        $this->assertStringContainsString('/story/read/the-rabbit-hole/3', $mail); // storyForNotify links the LATEST validated chapter
    }
}
