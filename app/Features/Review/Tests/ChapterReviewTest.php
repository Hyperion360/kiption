<?php // app/Features/Review/Tests/ChapterReviewTest.php
namespace App\Features\Review\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ChapterReviewTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $fanId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-ccrev-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('cc@e.test', ?, 'chapterfan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->fanId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null, array $overrides = []): TestClient
    {
        $app = new App(array_merge([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-ccrev-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-ccrev-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides));
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    /** A dedicated validated story (the seeder idiom: direct inserts, ids
     *  resolved by lookup, never assumed) with two validated chapters, so
     *  chapter scoping has a position to be wrong about. Restricted stories
     *  get their own slug so the no-oracle assertions stay story-scoped. */
    private function seedStory(string $slug, int $restricted = 0): int
    {
        $author = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $rating = (int) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, is_restricted) VALUES (?, ?, ?, ?, ?, 1, 0, 200, ?)',
            ['CCTest', $slug, 'Fixture.', $author, $rating, $restricted]);
        $storyId = (int) $this->db->lastInsertId();
        foreach ([[1, 'One', 'First chapter body.'], [2, 'Two', 'Second chapter body.']] as [$pos, $title, $content]) {
            $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, ?, ?, ?, 1, 100)',
                [$storyId, $pos, $title, $content]);
        }
        return $storyId;
    }

    public function test_member_chapter_comment_scopes_to_its_chapter(): void
    {
        $storyId = $this->seedStory('cctest');
        $repo = new \App\Repositories\ReviewRepository($this->db);
        [$added, $authorId, $error] = $repo->addChapterReview('cctest', 2, $this->fanId, null, 'The garden scene lands.', '127.0.0.1');
        $this->assertTrue($added, (string) $error);
        $this->assertNull($error);
        $chapter2 = (int) $this->db->one('SELECT id FROM chapters WHERE story_id = ? AND position = 2', [$storyId])['id'];
        $row = $this->db->one("SELECT chapter_id, user_id FROM reviews WHERE body = ?", ['The garden scene lands.']);
        $this->assertNotNull($row, 'the comment stored');
        $this->assertSame($chapter2, (int) $row['chapter_id'], 'stored scoped to chapter 2');
        $this->assertSame($this->fanId, (int) $row['user_id']);
        $this->assertContains('The garden scene lands.', array_column($repo->chapterReviews($storyId, 2), 'body'));
        $this->assertNotContains('The garden scene lands.', array_column($repo->chapterReviews($storyId, 1), 'body'));
    }

    public function test_reply_to_chapter_comment_lists_in_chapter_thread(): void
    {
        $storyId = $this->seedStory('cctest');
        $repo = new \App\Repositories\ReviewRepository($this->db);
        $repo->addChapterReview('cctest', 2, $this->fanId, null, 'Root chapter comment.', '127.0.0.1');
        $rootId = (int) $this->db->one("SELECT id FROM reviews WHERE body = ? AND parent_id IS NULL", ['Root chapter comment.'])['id'];
        [$added, $notifyId, $sid, $slug, $error] = $repo->addReply($rootId, $this->fanId, 'A threaded reply.');
        $this->assertTrue($added, (string) $error);
        $this->assertContains('A threaded reply.', array_column($repo->chapterReviews($storyId, 2), 'body'), 'replies carry the root chapter');
        $this->assertNotContains('A threaded reply.', array_column($repo->chapterReviews($storyId, 1), 'body'));
    }

    public function test_guest_chapter_comments_share_the_story_daily_cap(): void
    {
        $storyId = $this->seedStory('cctest');
        $repo = new \App\Repositories\ReviewRepository($this->db);
        [$added] = $repo->addChapterReview('cctest', 1, null, 'G1', 'First guest note.', '9.9.9.9');
        $this->assertTrue($added);
        [$added2, $authorId2, $error2] = $repo->addChapterReview('cctest', 2, null, 'G1', 'Second guest note, other chapter.', '9.9.9.9');
        $this->assertFalse($added2, 'one guest comment per story per day, chapters included');
        $this->assertSame('You already reviewed this story today.', $error2);
    }

    public function test_restricted_story_guest_errors_carry_no_position_oracle(): void
    {
        $storyId = $this->seedStory('restricted-cctest', 1);
        $repo = new \App\Repositories\ReviewRepository($this->db);
        // Guest on a restricted work: 'not found' BEFORE any body validation,
        // so an empty body on a valid position and a real body on an unknown
        // position are indistinguishable (no chapter-existence oracle).
        [$added, $authorId, $error] = $repo->addChapterReview('restricted-cctest', 1, null, 'G', '', '9.9.9.9');
        $this->assertFalse($added);
        $this->assertSame('not found', $error);
        [, , $error9] = $repo->addChapterReview('restricted-cctest', 9, null, 'G', 'Some body.', '9.9.9.9');
        $this->assertSame('not found', $error9);
        // Registered readers still can: the gate is guest-only, like the story page.
        [$addedM, , $errorM] = $repo->addChapterReview('restricted-cctest', 1, $this->fanId, null, 'Member can.', '127.0.0.1');
        $this->assertTrue($addedM, (string) $errorM);
        $this->assertNull($errorM);
    }

    public function test_unknown_slug_and_unknown_position_are_not_found(): void
    {
        $storyId = $this->seedStory('cctest');
        $repo = new \App\Repositories\ReviewRepository($this->db);
        [, , $error] = $repo->addChapterReview('nope', 1, $this->fanId, null, 'x', '127.0.0.1');
        $this->assertSame('not found', $error);
        [, , $error9] = $repo->addChapterReview('cctest', 9, $this->fanId, null, 'x', '127.0.0.1');
        $this->assertSame('not found', $error9);
    }

    public function test_body_and_guest_name_validation(): void
    {
        $storyId = $this->seedStory('cctest');
        $repo = new \App\Repositories\ReviewRepository($this->db);
        [, , $e1] = $repo->addChapterReview('cctest', 1, $this->fanId, null, '   ', '127.0.0.1');
        $this->assertSame('Comment text is required (max 5000 characters).', $e1);
        [, , $e2] = $repo->addChapterReview('cctest', 1, $this->fanId, null, str_repeat('x', 5001), '127.0.0.1');
        $this->assertSame('Comment text is required (max 5000 characters).', $e2);
        [, , $e3] = $repo->addChapterReview('cctest', 1, null, '   ', 'A valid body.', '9.9.9.9');
        $this->assertSame('Guest name is required (max 40 characters).', $e3);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM reviews WHERE story_id = ?', [$storyId])['c'], 'guarded intakes must store nothing');
    }

    public function test_story_page_feed_and_count_exclude_chapter_comments(): void
    {
        $storyId = $this->seedStory('cctest');
        $repo = new \App\Repositories\ReviewRepository($this->db);
        $repo->addChapterReview('cctest', 2, $this->fanId, null, 'Chapter-only remark.', '127.0.0.1');
        $repo->addReview('cctest', $this->fanId, null, 'Whole-work remark.', 7, '127.0.0.1');
        $body = $this->client($this->fanId)->get('/story/view/cctest')->body;
        $this->assertStringContainsString('Whole-work remark.', $body, 'plain story reviews still render');
        $this->assertStringContainsString('Reviews (1)', $body, 'the headline count excludes the chapter comment');
        $this->assertStringNotContainsString('Chapter-only remark.', $body, 'chapter comments never reach the story feed');
    }
}
