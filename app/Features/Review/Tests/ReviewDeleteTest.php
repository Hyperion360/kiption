<?php // app/Features/Review/Tests/ReviewDeleteTest.php
namespace App\Features\Review\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Deleting a review: moderators, admins and the story's author. */
#[Medium]
final class ReviewDeleteTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $targetId = 0;
    private int $heirId = 0;
    private int $moderatorId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-review-');
        $this->root = sys_get_temp_dir() . '/kiption-review-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->adminId = $this->user('roster-admin', 'admin');
        $this->targetId = $this->user('leaving-member', 'member');
        $this->heirId = $this->user('heir-member', 'member');
        $this->moderatorId = $this->user('roster-mod', 'moderator');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function user(string $name, string $role): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ["$name@e.test", \App\Tests\Support\Hashes::of('password123'), $name, $role, $role === 'admin' ? 1 : 0, date('c'), date('c'), $name]);
        return (int) $this->db->lastInsertId();
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    private function story(): int
    {
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES (?, ?, ?, ?, 2, 1)', ['Owned Tale', 'owned-tale', 'S.', $this->targetId]);
        return (int) $this->db->lastInsertId();
    }

    private function review(int $storyId, ?int $parent = null): int
    {
        $this->db->query('INSERT INTO reviews (story_id, user_id, body, parent_id) VALUES (?, ?, ?, ?)', [$storyId, $this->heirId, 'Nice read, friend.', $parent]);
        return (int) $this->db->lastInsertId();
    }

    private function reviewRows(int $id): int
    {
        return (int) $this->db->one('SELECT COUNT(*) c FROM reviews WHERE id = ?', [$id])['c'];
    }

    public function test_moderator_admin_and_story_author_can_delete(): void
    {
        $sid = $this->story();
        foreach ([$this->moderatorId, $this->adminId, $this->targetId] as $who) {
            $rid = $this->review($sid);
            $page = $this->client($who)->get('/review/remove/' . $rid);
            $this->assertSame(200, $page->status);
            $this->assertStringContainsString('action="/review/delete/' . $rid . '"', $page->body);
            $this->assertSame(302, $this->client($who)->postWithToken('/review/delete/' . $rid)->status);
            $this->assertSame(0, $this->reviewRows($rid));
        }
    }

    public function test_deleting_a_review_removes_its_replies(): void
    {
        $sid = $this->story();
        $rid = $this->review($sid);
        $reply = $this->review($sid, $rid);
        $this->client($this->adminId)->postWithToken('/review/delete/' . $rid);
        $this->assertSame(0, $this->reviewRows($reply));
    }

    public function test_other_members_and_guests_are_refused(): void
    {
        $rid = $this->review($this->story());
        $this->assertSame(403, $this->client($this->heirId)->postWithToken('/review/delete/' . $rid)->status);
        $this->assertSame(403, $this->client($this->heirId)->get('/review/remove/' . $rid)->status);
        $this->assertSame(1, $this->reviewRows($rid));
        $this->assertNotSame(200, $this->client()->get('/review/remove/' . $rid)->status);
    }

    public function test_a_missing_token_is_refused_and_unknown_ids_404(): void
    {
        $rid = $this->review($this->story());
        $this->assertSame(403, $this->client($this->adminId)->post('/review/delete/' . $rid)->status);
        $this->assertSame(1, $this->reviewRows($rid));
        $this->assertSame(404, $this->client($this->adminId)->postWithToken('/review/delete/99999')->status);
    }

    public function test_story_page_shows_the_delete_link_to_the_right_people(): void
    {
        $rid = $this->review($this->story());
        $link = 'href="/review/remove/' . $rid . '"';
        $this->assertStringContainsString($link, $this->client($this->targetId)->get('/story/view/owned-tale')->body);
        $this->assertStringContainsString($link, $this->client($this->moderatorId)->get('/story/view/owned-tale')->body);
        $this->assertStringNotContainsString($link, $this->client($this->heirId)->get('/story/view/owned-tale')->body);
        $this->assertStringNotContainsString($link, $this->client()->get('/story/view/owned-tale')->body);
    }
}
