<?php // app/Features/Story/Tests/AuthoringTest.php
namespace App\Features\Story\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class AuthoringTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-auth2-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-authoring-test.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-authoring-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides);
    }

    private function clientAs(int $userId, array $overrides = []): TestClient
    {
        return (new TestClient(new App($this->config($overrides))))->actingAs($userId);
    }

    /** A second, plain member (goes through the queue). */
    private function memberId(): int
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('m2@e.test', ?, 'plainmember', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        return (int) $this->db->lastInsertId();
    }

    private function ratingId(): int
    {
        return (int) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
    }

    private function categoryId(): int
    {
        return (int) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id'];
    }

    public function test_story_form_renders_for_author(): void
    {
        $res = $this->clientAs(1)->get('/story/new');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<form', $res->body);
        $this->assertStringContainsString('name="title"', $res->body);
        $this->assertStringContainsString('name="rating_id"', $res->body); // ratings reach the form
        $this->assertStringContainsString('name="categories[]"', $res->body);
    }

    public function test_story_requires_login(): void
    {
        $res = (new TestClient(new App($this->config())))->get('/story/new');
        $this->assertSame(302, $res->status);
    }

    public function test_validated_author_bypasses_queue(): void
    {
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'Fresh Work', 'summary' => 'A new tale.', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringEndsWith('/story/edit/fresh-work', $res->headers['Location']);
        $row = $this->db->one("SELECT * FROM stories WHERE title = 'Fresh Work'");
        $this->assertSame(1, (int) $row['validated']);
        $this->assertSame('fresh-work', $row['slug']);
        $this->assertSame(1, (int) $this->db->one(
            'SELECT COUNT(*) c FROM story_categories WHERE story_id = ?', [$row['id']])['c']);
    }

    public function test_member_goes_through_queue(): void
    {
        $res = $this->clientAs($this->memberId())->postWithToken('/story/create',
            ['title' => 'Queued Work', 'summary' => 'S.', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT * FROM stories WHERE title = 'Queued Work'");
        $this->assertSame(0, (int) $row['validated']);
        $res = (new TestClient(new App($this->config())))->get('/story/view/queued-work'); // anonymous
        $this->assertSame(404, $res->status);
    }

    public function test_slug_deduplication(): void
    {
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'The Rabbit Hole', 'summary' => 'x', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringEndsWith('/story/edit/the-rabbit-hole-2', $res->headers['Location']);
    }

    public function test_edit_requires_ownership(): void
    {
        $other = $this->memberId();
        $client = $this->clientAs($other);
        $this->assertSame(404, $client->get('/story/edit/the-rabbit-hole')->status);
        $this->assertSame(404, $client->postWithToken('/story/delete/the-rabbit-hole')->status);
        $this->assertNull($this->db->one("SELECT deleted_at FROM stories WHERE slug = 'the-rabbit-hole'")['deleted_at']);
    }

    public function test_update_swaps_categories_and_touches_updated_at(): void
    {
        $before = $this->db->one("SELECT updated_at FROM stories WHERE slug = 'the-rabbit-hole'")['updated_at'];
        sleep(1); // distinct seconds make the timestamp assertion legible
        $res = $this->clientAs(1)->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.',
             'rating_id' => (string) $this->ratingId(), 'categories' => [], 'completed' => '1']);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT completed, updated_at FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(1, (int) $row['completed']);
        $this->assertNotSame($before, $row['updated_at']);
        $this->assertSame(0, (int) $this->db->one(
            'SELECT COUNT(*) c FROM story_categories WHERE story_id = (SELECT id FROM stories WHERE slug = \'the-rabbit-hole\')')['c']);
        // restore the category for later tasks/tests
        $this->db->query('INSERT INTO story_categories (story_id, category_id)
                          SELECT id, ? FROM stories WHERE slug = \'the-rabbit-hole\'', [$this->categoryId()]);
    }

    public function test_delete_is_soft_and_purges(): void
    {
        $cacheDir = dirname(__DIR__, 4) . '/public/cache';
        @mkdir($cacheDir . '/story/view/the-rabbit-hole', 0775, true);
        file_put_contents($cacheDir . '/story/view/the-rabbit-hole/index.html', 'stale');
        $res = $this->clientAs(1)->postWithToken('/story/delete/the-rabbit-hole');
        $this->assertSame(302, $res->status);
        $this->assertNotNull($this->db->one("SELECT deleted_at FROM stories WHERE slug = 'the-rabbit-hole'")['deleted_at']);
        $this->assertFileDoesNotExist($cacheDir . '/story/view/the-rabbit-hole/index.html');
        // restore for other tests
        $this->db->query("UPDATE stories SET deleted_at = NULL WHERE slug = 'the-rabbit-hole'");
    }

    /** Throwaway story for chapter tests. */
    private function chapterFixture(string $slug): void
    {
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, word_count) VALUES (?, ?, ?, 1, ?, 1, 200)',
            ['Fixture ' . $slug, $slug, 's.', $this->ratingId()]);
        $storyId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 1, \'One\', \'One hundred words pretend.\', 1, 100)', [$storyId]);
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 2, \'Two\', \'Also pretend words here.\', 1, 100)', [$storyId]);
    }

    public function test_chapter_create_appends_and_counts_words(): void
    {
        $this->chapterFixture('ch-create');
        $res = $this->clientAs(1)->postWithToken('/chapter/create/ch-create',
            ['title' => 'Sideways', 'content' => 'Four *short* words.', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $ch = $this->db->one("SELECT * FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-create') AND position = 3");
        $this->assertNotNull($ch);
        $this->assertSame(3, (int) $ch['word_count']); // markers excluded
        $story = $this->db->one("SELECT word_count FROM stories WHERE slug = 'ch-create'");
        $this->assertSame(203, (int) $story['word_count']);
        $this->assertSame(1, (int) $ch['validated']); // validated_author bypass
    }

    public function test_chapter_requires_ownership(): void
    {
        $this->chapterFixture('ch-own');
        $member = $this->clientAs($this->memberId());
        $this->assertSame(404, $member->get('/chapter/new/ch-own')->status);
        // Write actions must fail like the story ones (404), never as unhandled 500s:
        // ownStory() throws RuntimeException on a non-owned or unknown slug.
        $this->assertSame(404, $member->postWithToken('/chapter/create/ch-own',
            ['title' => 'X', 'content' => 'stolen words', 'notes_before' => '', 'notes_after' => ''])->status);
        $this->assertSame(404, $member->postWithToken('/chapter/update/ch-own/1',
            ['title' => 'X', 'content' => 'stolen words', 'notes_before' => '', 'notes_after' => ''])->status);
        $this->assertSame(404, $member->postWithToken('/chapter/delete/ch-own/1')->status);
        $this->assertSame(404, $this->clientAs(1)->postWithToken('/chapter/delete/no-such-story/1')->status);
        $ch = $this->db->one("SELECT title FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-own') AND position = 1");
        $this->assertSame('One', $ch['title']); // untouched
        $this->assertSame(2, (int) $this->db->one(
            "SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-own')")['c']);
    }

    public function test_chapter_update_recounts(): void
    {
        $this->chapterFixture('ch-upd');
        $res = $this->clientAs(1)->postWithToken('/chapter/update/ch-upd/1',
            ['title' => 'One', 'content' => 'Now five whole words here.', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $ch = $this->db->one("SELECT word_count FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-upd') AND position = 1");
        $this->assertSame(5, (int) $ch['word_count']);
        $story = $this->db->one("SELECT word_count FROM stories WHERE slug = 'ch-upd'");
        $this->assertSame(105, (int) $story['word_count']);
    }

    public function test_chapter_delete_resequences(): void
    {
        $this->chapterFixture('ch-del');
        $res = $this->clientAs(1)->postWithToken('/chapter/delete/ch-del/1');
        $this->assertSame(302, $res->status, $res->body);
        $positions = array_map('intval', array_column($this->db->all(
            "SELECT position FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-del') ORDER BY position"), 'position'));
        $this->assertSame([1], $positions);
        $story = $this->db->one("SELECT word_count FROM stories WHERE slug = 'ch-del'");
        $this->assertSame(100, (int) $story['word_count']);
    }

    public function test_chapter_empty_content_rejected(): void
    {
        $this->chapterFixture('ch-empty');
        $res = $this->clientAs(1)->postWithToken('/chapter/create/ch-empty',
            ['title' => 'X', 'content' => '   ', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(422, $res->status);
        $this->assertSame(2, (int) $this->db->one(
            "SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-empty')")['c']);
    }

    public function test_story_rejects_unknown_rating(): void
    {
        // A forged or stale rating_id must be a 422 form error, never an
        // uncaught FK violation (generic 500).
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'Bad Rating', 'summary' => 'x', 'rating_id' => '9999', 'categories' => []]);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('rating', $res->body);
        $this->assertNull($this->db->one("SELECT id FROM stories WHERE title = 'Bad Rating'"));
        $res = $this->clientAs(1)->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.',
             'rating_id' => '9999', 'categories' => []]);
        $this->assertSame(422, $res->status);
        $rating = (int) $this->db->one("SELECT rating_id FROM stories WHERE slug = 'the-rabbit-hole'")['rating_id'];
        $this->assertSame($this->ratingId(), $rating); // row untouched, still the seeded Teen rating
    }

    public function test_story_drops_unknown_categories(): void
    {
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'Odd Categories', 'summary' => 'x', 'rating_id' => (string) $this->ratingId(), 'categories' => ['8888', (string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT * FROM stories WHERE title = 'Odd Categories'");
        $this->assertSame(1, (int) $this->db->one(
            'SELECT COUNT(*) c FROM story_categories WHERE story_id = ?', [$row['id']])['c']); // only the real id stored
    }
}
