<?php // tests/ReportTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $fanId = 0;
    private int $reviewId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rep-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        \App\Adminness::setRole($this->db, 1, 'moderator');
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('rp@e.test', ?, 'reportfan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->fanId = (int) $this->db->lastInsertId();
        $storyId = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db->query('INSERT INTO reviews (story_id, user_id, body, ip) VALUES (?, ?, ?, \'127.0.0.1\')',
            [$storyId, $this->fanId, 'Reportable review.']);
        $this->reviewId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(int $as): TestClient
    {
        return (new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-rep-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-rep-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->actingAs($as);
    }

    public function test_member_reports_story_once(): void
    {
        $res = $this->client($this->fanId)->postWithToken('/report/story/the-rabbit-hole', ['reason' => 'Wrong rating.']);
        $this->assertSame(302, $res->status, $res->body);
        $res = $this->client($this->fanId)->postWithToken('/report/story/the-rabbit-hole', ['reason' => 'Again.']);
        $this->assertSame(409, $res->status); // one open report per target per reporter
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM reports')['c']);
    }

    public function test_report_review_and_moderator_queue_lists(): void
    {
        $this->client($this->fanId)->postWithToken('/report/review/' . $this->reviewId, ['reason' => 'Spam.']);
        $body = $this->client(1)->get('/queue')->body;
        $this->assertStringContainsString('Spam.', $body);
        $this->assertStringContainsString('Reportable review.', $body);
    }

    public function test_moderator_resolves_report(): void
    {
        $this->client($this->fanId)->postWithToken('/report/review/' . $this->reviewId, ['reason' => 'Spam.']);
        $id = (int) $this->db->one('SELECT id FROM reports')['id'];
        $res = $this->client(1)->postWithToken('/report/resolve/' . $id . '/dismiss');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertNotNull($this->db->one('SELECT resolved_at FROM reports WHERE id = ?', [$id])['resolved_at']);
        $body = $this->client(1)->get('/queue')->body;
        $this->assertStringNotContainsString('Spam.', $body);
    }

    public function test_member_cannot_resolve(): void
    {
        $this->client($this->fanId)->postWithToken('/report/story/the-rabbit-hole', ['reason' => 'x']);
        $id = (int) $this->db->one('SELECT id FROM reports')['id'];
        $this->assertSame(403, $this->client($this->fanId)->postWithToken('/report/resolve/' . $id . '/dismiss')->status);
    }
}
