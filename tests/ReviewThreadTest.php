<?php // tests/ReviewThreadTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ReviewThreadTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $fanId = 0;
    private int $rootId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-revth-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('rt@e.test', ?, 'threadfan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->fanId = (int) $this->db->lastInsertId();
        $storyId = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db->query('INSERT INTO reviews (story_id, user_id, body, rating, ip) VALUES (?, ?, ?, 8, \'127.0.0.1\')',
            [$storyId, $this->fanId, 'Root review.']);
        $this->rootId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(int $as): TestClient
    {
        return (new TestClient($this->guestApp()))->actingAs($as);
    }

    private function guestApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-revth-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-revth-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    public function test_author_reply_threads_and_highlights(): void
    {
        $res = $this->client(1)->postWithToken('/review/reply/' . $this->rootId, ['body' => 'Thanks for reading!']);
        $this->assertSame(302, $res->status, $res->body);
        $body = $this->client(1)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Thanks for reading!', $body);
        $this->assertStringContainsString('| author', $body);
    }

    public function test_reply_notifies_root_author_not_self(): void
    {
        $this->client(1)->postWithToken('/review/reply/' . $this->rootId, ['body' => 'Author reply.']);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE kind = 'reply' AND user_id = ?", [$this->fanId])['c']);
        $res = $this->client($this->fanId)->postWithToken('/review/reply/' . $this->rootId, ['body' => 'Self thread reply.']);
        $this->assertSame(302, $res->status); // still posts (thread continues)...
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE kind = 'reply' AND user_id = ?", [$this->fanId])['c']); // ...but no self-notify
    }

    public function test_reply_to_reply_attaches_to_root(): void
    {
        $this->client(1)->postWithToken('/review/reply/' . $this->rootId, ['body' => 'First reply.']);
        $replyId = (int) $this->db->one('SELECT id FROM reviews WHERE parent_id = ?', [$this->rootId])['id'];
        $res = $this->client($this->fanId)->postWithToken('/review/reply/' . $replyId, ['body' => 'Nested input.']);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one('SELECT parent_id FROM reviews WHERE body = ?', ['Nested input.']);
        $this->assertSame($this->rootId, (int) $row['parent_id']); // flattened to root: one level only
    }

    public function test_unknown_review_404s(): void
    {
        $this->assertSame(404, $this->client(1)->postWithToken('/review/reply/99999', ['body' => 'x'])->status);
    }

    public function test_guest_cannot_reply(): void
    {
        // gate before CSRF since kip b29e269: a tokenless guest POST to the
        // #[Auth]+#[Post] reply gets the login 302 like any gated route, and
        // the redirect precedes all controller code, so nothing is written
        $this->assertSame(302, (new TestClient($this->guestApp()))->post('/review/reply/' . $this->rootId, ['body' => 'x'])->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM reviews WHERE body = ?', ['x'])['c'], 'no guest reply row');
    }

    public function test_reply_flood_cannot_hide_the_root(): void
    {
        $storyId = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        // 1 root older than 55 replies with distinct timestamps - the closure's
        // exact failure shape under the shipped mixed-stream window
        $this->db->query('INSERT INTO reviews (story_id, user_id, body, created_at) VALUES (?, ?, ?, ?)',
            [$storyId, $this->fanId, 'The original root.', date('c', time() - 3600)]);
        $rootId = (int) $this->db->lastInsertId();
        for ($i = 0; $i < 55; $i++) {
            $this->db->query('INSERT INTO reviews (story_id, user_id, body, parent_id, created_at) VALUES (?, ?, ?, ?, ?)',
                [$storyId, $this->fanId, 'reply ' . $i, $rootId, date('c', time() - 1800 + $i)]);
        }
        $guest = (new TestClient($this->guestApp()))->get('/story/view/the-rabbit-hole');
        $this->assertStringContainsString('The original root.', $guest->body, 'the root must survive any reply flood');
        $this->assertStringContainsString('reply 54', $guest->body, 'newest replies render under it');
    }

    public function test_headline_counts_roots_only(): void
    {
        $storyId = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db->query('INSERT INTO reviews (story_id, user_id, body) VALUES (?, ?, ?)', [$storyId, $this->fanId, 'A root.']);
        $rootId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO reviews (story_id, user_id, body, parent_id) VALUES (?, ?, ?, ?)', [$storyId, $this->fanId, 'A reply.', $rootId]);
        $body = (new TestClient($this->guestApp()))->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Reviews (2)', $body); // setUp seeds one root; headline counts ROOTS
        $this->assertStringContainsString('A reply.', $body);
    }
}
