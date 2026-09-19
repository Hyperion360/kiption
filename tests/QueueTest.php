<?php // tests/QueueTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class QueueTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;
    private int $pendingStoryId = 0;
    private int $pendingChapterId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-queue-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        \App\Adminness::setRole($this->db, 1, 'moderator'); // Demo Author moderates
        // one pending story with one pending chapter by a plain member
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('p@e.test', ?, 'pendingauthor', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->memberId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES (?, ?, ?, ?, ?, 0)',
            ['Pending Work', 'pending-work', 'S.', $this->memberId, 2]);
        $this->pendingStoryId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 1, \'One\', \'Body text.\', 0, 2)',
            [$this->pendingStoryId]);
        $this->pendingChapterId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function clientAs(int $userId): TestClient
    {
        return (new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->actingAs($userId);
    }

    public function test_member_cannot_see_queue(): void
    {
        $res = $this->clientAs($this->memberId)->get('/queue');
        $this->assertSame(403, $res->status);
    }

    public function test_anonymous_is_redirected(): void
    {
        $app = new App(['env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views', 'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:']]);
        $res = (new TestClient($app))->get('/queue');
        $this->assertSame(302, $res->status);
    }

    public function test_queue_lists_pending(): void
    {
        $res = $this->clientAs(1)->get('/queue');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Pending Work', $res->body);
        $this->assertStringContainsString('name="robots" content="noindex"', $res->body);
    }

    public function test_approving_story_validates_it_and_its_chapters(): void
    {
        $res = $this->clientAs(1)->postWithToken('/queue/story/' . $this->pendingStoryId . '/approve');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one("SELECT validated FROM stories WHERE slug = 'pending-work'")['validated']);
        $this->assertSame(1, (int) $this->db->one(
            'SELECT validated FROM chapters WHERE story_id = ?', [$this->pendingStoryId])['validated']);
    }

    public function test_approving_chapter_only_touches_the_chapter(): void
    {
        $res = $this->clientAs(1)->postWithToken('/queue/chapter/' . $this->pendingChapterId . '/approve');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one('SELECT validated FROM chapters WHERE id = ?', [$this->pendingChapterId])['validated']);
        $this->assertSame(0, (int) $this->db->one("SELECT validated FROM stories WHERE slug = 'pending-work'")['validated']);
    }

    public function test_member_approval_flow(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, approved_at) VALUES ('w@e.test', ?, 'waitingmember', NULL)",
            [password_hash('password123', PASSWORD_DEFAULT)]);
        $id = (int) $this->db->lastInsertId();
        $res = $this->clientAs(1)->postWithToken('/queue/member/' . $id . '/approve');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertNotNull($this->db->one('SELECT approved_at FROM users WHERE id = ?', [$id])['approved_at']);
    }
}
