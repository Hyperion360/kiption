<?php // app/Features/Story/Tests/StoryTest.php
namespace App\Features\Story\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class StoryTest extends TestCase
{
    private string $dsn = '';
    private App $app;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kiption-story-') . '.sqlite';
        $this->dsn = 'sqlite:' . $path;
        $db = new Database($this->dsn);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $db->query('INSERT INTO users (email, password_hash, penname, profile_slug) VALUES (?, ?, ?, ?)', ['a@x.test', 'h', 'Demo Author', 'demo-author']);
        $db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, 0, "", 1)', ['Teen']);
        $db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, 1, "Adult content ahead.", 2)', ['Explicit']);
        $db->query('INSERT INTO categories (name, slug) VALUES (?, ?)', ['General', 'general']);
        $db->query('INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, validated, completed, word_count) VALUES (?, ?, ?, ?, 1, 1, 1, 0, 300)',
            ['The Rabbit Hole', 'the-rabbit-hole', 'Falling, slowly.', 'Thanks for reading.']);
        $db->query('INSERT INTO chapters (story_id, position, title, notes_before, content, notes_after, validated, word_count) VALUES (1, 1, "Down", "A/N before.", "<p>Falling <em>down</em>.</p>", "", 1, 100)');
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, 2, "Through", "<p>Through the door.</p>", 1, 200)');
        $db->query('INSERT INTO story_categories (story_id, category_id) VALUES (1, 1)');
        $db->query("INSERT INTO series (title, slug, summary, owner_id, membership) VALUES ('Down the Rabbit Hole', 'down-the-rabbit-hole', 'The complete descent, chapter by chapter.', 1, 'open')");
        $db->query("INSERT INTO series_items (series_id, story_id, position, confirmed) VALUES (1, 1, 1, 1)");
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id, validated, completed, word_count) VALUES (?, ?, 1, 2, 1, 1, 100)',
            ['After Hours', 'after-hours']);
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (2, 1, "One", "<p>Body.</p>", 1, 100)');
        $this->app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => $this->dsn],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-story-upl'],
        ]);
    }

    protected function tearDown(): void
    {
        unset($this->app);
        @unlink(substr($this->dsn, 7));
        @unlink(substr($this->dsn, 7) . '-wal');
        @unlink(substr($this->dsn, 7) . '-shm');
    }

    /** The member-path idiom (the KudosTest shape): actingAs plants the
     *  session the controller's cookies!==[] gate needs to see $me = 1. */
    private function client(?int $as = null): \Kip\Testing\TestClient
    {
        $client = new \Kip\Testing\TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_story_landing_shows_metadata_and_toc(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('Demo Author', $res->body);
        $this->assertStringContainsString('/story/read/the-rabbit-hole/1', $res->body);
        $this->assertStringContainsString('Chapter 2', $res->body);
    }

    public function test_round_robin_story_shows_the_badge(): void
    {
        // The eFiction import preserves the rr flag as stories.round_robin;
        // the landing page is where readers see the story is co-authored in turns.
        (new Database($this->dsn))->query('UPDATE stories SET round_robin = 1 WHERE id = 1');
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Round robin', $res->body);
    }

    public function test_unflagged_story_shows_no_round_robin_badge(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('Round robin', $res->body);
    }

    public function test_toc_renders_titles_with_delimiter_characters_intact(): void
    {
        // admin-CRUD-written chapter titles are free text; the TOC transport
        // must survive the characters a delimited blob would split on
        $db = new Database($this->dsn);
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, 3, ?, ?, 1, 444)',
            ['A|B~C', '<p>x</p>']);
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Chapter 3: A|B~C', $res->body);
        $this->assertStringContainsString('444 words', $res->body);
        $this->assertStringNotContainsString('Chapter 0', $res->body);
    }

    public function test_story_view_links_its_series(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertStringContainsString('href="/series/view/down-the-rabbit-hole"', $res->body);
        $this->assertStringContainsString('Down the Rabbit Hole', $res->body);
    }

    /** Task 4's round-trip pin: writeTags stores the form's tag selection,
     *  and findStoryBySlug's blob renders the chosen tag while the unchosen
     *  sibling stays off the page. */
    public function test_tags_round_trip_onto_the_story_view(): void
    {
        $db = new Database($this->dsn);
        $db->query("INSERT INTO tag_types (name) VALUES ('genre')");
        $db->query("INSERT INTO tags (tag_type_id, name) VALUES (1, 'Fantasy')");
        $db->query("INSERT INTO tags (tag_type_id, name) VALUES (1, 'Adventure')");
        (new \App\Repositories\AuthoringRepository($db))->updateStory(
            'the-rabbit-hole', 1, 'The Rabbit Hole', 'Falling, slowly.', 'Thanks for reading.',
            1, [1], false, false, '', false, '', '', '', [1]);
        $body = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []))->body;
        $this->assertStringContainsString('Fantasy', $body);
        $this->assertStringNotContainsString('Adventure', $body);
    }

    public function test_solo_story_byline_links_author_without_coauthor_comma(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertStringContainsString('href="/user/view/demo-author">Demo Author</a>', $res->body);
        $this->assertStringNotContainsString(', <a href="/user/view/', $res->body); // no comma join without coauthors
    }

    public function test_story_view_renders_null_body_review_with_rating(): void
    {
        // eFiction imports carry rating-only reviews (the 'No Review' sentinel
        // maps to a NULL body): the view must render them, not error.
        $db = new Database($this->dsn);
        $db->query("INSERT INTO reviews (story_id, guest_name, body, rating, created_at) VALUES (1, 'Guest Reader', NULL, 7, '2026-09-01T00:00:00Z')");
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('Guest Reader', $res->body);
        $this->assertStringContainsString('7/10', $res->body, 'the rating renders');
    }

    public function test_unknown_story_404(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/nope', [], [], []));
        $this->assertSame(404, $res->status);
    }

    public function test_chapter_reads_with_nav(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/read/the-rabbit-hole/2', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Through the door.', $res->body);
        $this->assertStringContainsString('/story/read/the-rabbit-hole/1', $res->body); // prev
        $this->assertStringContainsString('Chapter 2 of 2', $res->body);
    }

    public function test_missing_chapter_404(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/read/the-rabbit-hole/9', [], [], []));
        $this->assertSame(404, $res->status);
    }

    public function test_adult_story_gated_without_cookie(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/read/after-hours/1', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Adult content ahead.', $res->body);
        $this->assertStringNotContainsString('Body.', $res->body);
    }

    public function test_adult_story_reads_with_consent_cookie(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/read/after-hours/1', [], [], ['age_ok' => '1']));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Body.', $res->body);
    }

    public function test_adult_landing_not_gated(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/after-hours', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Explicit', $res->body);
    }

    /** C4: the member render consumes the existing reading_history row (C3's
     *  removal note: furthest-read last_position semantics, never-backwards).
     *  Story word_count 300, chapter 1 = 100 words: 33% read, (300-100)/250
     *  rounds to 1 min left, and the CTA targets the furthest chapter. */
    public function test_member_view_shows_youre_here_and_continue_reading(): void
    {
        (new Database($this->dsn))->query('INSERT INTO reading_history (user_id, story_id, last_position) VALUES (1, 1, 1)');
        $body = $this->client(1)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString("You're here", $body);
        $this->assertStringContainsString('Continue reading', $body);
        $this->assertStringContainsString('href="/story/read/the-rabbit-hole/1"', $body);
        $this->assertStringContainsString('33% read', $body);
        $this->assertStringContainsString('1 min left', $body);
    }

    /** Cache neutrality: the guest render adds ZERO progress markup, so the
     *  anonymous bytes the static cache stores never vary by reader. */
    public function test_guest_view_has_no_progress_markup_at_all(): void
    {
        $body = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []))->body;
        $this->assertStringNotContainsString("You're here", $body);
        $this->assertStringNotContainsString('Continue reading', $body);
        $this->assertStringNotContainsString('% read', $body);
        $this->assertStringNotContainsString('min left', $body);
        $this->assertStringNotContainsString('progress', $body);
    }

    /** A member with no reading_history row sees no progress block either:
     *  the LEFT JOIN went NULL and the columns are absent from the envelope. */
    public function test_member_without_history_sees_no_progress_block(): void
    {
        $body = $this->client(1)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringNotContainsString("You're here", $body);
        $this->assertStringNotContainsString('Continue reading', $body);
    }

    /** Step 2b at the data layer: findStoryWithChapter carries the SAME
     *  titled TOC blob the landing page renders, plus the member-only
     *  progress columns (the guest row omits them entirely). */
    public function test_find_with_chapter_carries_titled_toc_and_member_progress(): void
    {
        $repo = new \App\Repositories\StoryRepository(new Database($this->dsn));
        $guest = $repo->findStoryWithChapter('the-rabbit-hole', 1, 0);
        $this->assertNotNull($guest);
        $this->assertArrayNotHasKey('read_pct', $guest);
        $this->assertArrayNotHasKey('minutes_left', $guest);
        $this->assertArrayNotHasKey('last_position', $guest);
        $toc = json_decode((string) $guest['chapters_blob'], true);
        $this->assertSame([['position' => 1, 'title' => 'Down', 'word_count' => 100],
                           ['position' => 2, 'title' => 'Through', 'word_count' => 200]], $toc);

        (new Database($this->dsn))->query('INSERT INTO reading_history (user_id, story_id, last_position) VALUES (1, 1, 2)');
        $member = $repo->findStoryWithChapter('the-rabbit-hole', 1, 1);
        $this->assertSame(2, (int) $member['last_position']);
        $this->assertSame(100, $member['read_pct']); // (100+200) of 300 words
        $this->assertSame(0, $member['minutes_left']); // nothing left to read
        $this->assertSame($toc, json_decode((string) $member['chapters_blob'], true));
    }
}
