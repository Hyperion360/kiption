<?php // tests/ReadingTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ReadingTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-read-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('r@e.test', ?, 'readerone', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->memberId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -strlen('.sqlite'))); // the zero-byte tempnam stub itself
    }

    private function client(int $as): TestClient
    {
        return (new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-read-upl'],  // AccountController autowires Storage
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->actingAs($as);
    }

    public function test_reading_records_progress(): void
    {
        $res = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/2');
        $this->assertSame(200, $res->status);
        $row = $this->db->one('SELECT last_position FROM reading_history WHERE user_id = ? AND story_id = (SELECT id FROM stories WHERE slug = \'the-rabbit-hole\')', [$this->memberId]);
        $this->assertSame(2, (int) $row['last_position']);
    }

    public function test_progress_never_moves_backwards(): void
    {
        $client = $this->client($this->memberId);
        $client->get('/story/read/the-rabbit-hole/3');
        $client->get('/story/read/the-rabbit-hole/1');
        $row = $this->db->one('SELECT last_position FROM reading_history WHERE user_id = ?', [$this->memberId]);
        $this->assertSame(3, (int) $row['last_position']);
    }

    public function test_anonymous_reads_record_nothing(): void
    {
        (new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ])))->get('/story/read/the-rabbit-hole/1');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM reading_history')['c']);
    }

    public function test_mark_for_later_and_account_sections(): void
    {
        $client = $this->client($this->memberId);
        $client->get('/story/read/the-rabbit-hole/2');
        $res = $client->postWithToken('/story/mark/the-rabbit-hole');
        $this->assertSame(302, $res->status, $res->body);
        $body = $client->get('/account')->body;
        $this->assertStringContainsString('Continue reading', $body);
        $this->assertStringContainsString('Marked for later', $body);
        $this->assertStringContainsString('The Rabbit Hole', $body);
    }
}
