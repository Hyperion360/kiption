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
        $this->assertStringContainsString('<span class="ch-num">II</span>', $res->body, 'C7: chapter rows carry Roman numerals');
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
        $this->assertStringContainsString('<span class="ch-title">A|B~C</span>', $res->body);
        $this->assertStringContainsString('<span class="ch-meta">444</span>', $res->body);
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
     *  rounds to 1 min left, and the CTA targets the furthest chapter. C7
     *  renders the percent in the You're-here marker and the CTA sub-line. */
    public function test_member_view_shows_youre_here_and_continue_reading(): void
    {
        (new Database($this->dsn))->query('INSERT INTO reading_history (user_id, story_id, last_position) VALUES (1, 1, 1)');
        $body = $this->client(1)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString("You're here · 33%", $body);
        $this->assertStringContainsString('Continue reading', $body);
        $this->assertStringContainsString('href="/story/read/the-rabbit-hole/1"', $body);
        $this->assertStringContainsString('Chapter I · Down · 33%', $body, 'the CTA sub-line: roman, title, percent');
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
        // read_pct/minutes_left no longer ride the SQL (the chapter-range SUM
        // ran twice per render); StoryController::progressOf derives both
        // from this row plus the TOC above, clamped. The derivation itself is
        // pinned through the rendered member page below and in PerUserTest.
        $this->assertArrayNotHasKey('read_pct', $member);
        $this->assertArrayNotHasKey('minutes_left', $member);
        $this->assertSame($toc, json_decode((string) $member['chapters_blob'], true));
    }

    /** Review follow-up: the derived progress is clamped on both axes. A
     *  stale stories.word_count (chapters edited after the cached total) can
     *  push pct past 100 and minutes negative; the member page must render
     *  100% and 0 minutes, never "101%" or "about -1 min left". */
    public function test_member_progress_renders_clamped_when_word_count_is_stale(): void
    {
        (new Database($this->dsn))->query('INSERT INTO reading_history (user_id, story_id, last_position) VALUES (1, 1, 2)');
        (new Database($this->dsn))->query("UPDATE stories SET word_count = 150 WHERE slug = 'the-rabbit-hole'");
        $body = $this->client(1)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('100%', $body);
        $this->assertStringNotContainsString('101%', $body);
        $this->assertStringNotContainsString('about -', $body);
    }

    /** Review follow-up (red team): Continue never links a position that no
     *  longer validates. The member read chapter 3; the author then deleted
     *  it; the landing CTA must fall back to the highest surviving position
     *  (or vanish when nothing at or below survives), never 404. */
    public function test_continue_clamps_to_surviving_positions(): void
    {
        $db = new Database($this->dsn);
        $db->query('INSERT INTO reading_history (user_id, story_id, last_position) VALUES (1, 1, 3)');
        $db->query('DELETE FROM chapters WHERE story_id = 1 AND position = 3');
        $body = $this->client(1)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringNotContainsString('/story/read/the-rabbit-hole/3', $body,
            'the dead position is never linked');
    }

    /** C7 (frames M1/T2/D3): the hero grid pairs a cover column (uploaded
     *  image or the typographic card) with the info column. */
    public function test_story_page_renders_the_hero_grid_and_typographic_cover(): void
    {
        $body = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []))->body;
        $this->assertStringContainsString('<div class="story-hero">', $body);
        $this->assertStringContainsString('<aside class="story-cover">', $body);
        // no cover_path: the CSS typographic card (2:3 brand green, T2/D3)
        $this->assertStringContainsString('<div class="cover-card">', $body);
        $this->assertStringContainsString('<span class="cover-kicker">A Kiption Story</span>', $body);
        $this->assertStringContainsString('<span class="cover-title">The Rabbit Hole</span>', $body);
        $this->assertStringContainsString('<span class="cover-author">Demo Author</span>', $body);
        $this->assertStringContainsString('<div class="story-info">', $body);
        // the summary stays server-escaped text
        $this->assertStringContainsString('class="summary">Falling, slowly.</p>', $body);
    }

    public function test_uploaded_cover_image_replaces_the_typographic_card(): void
    {
        (new Database($this->dsn))->query("UPDATE stories SET cover_path = '/uploads/cover.png' WHERE id = 1");
        $body = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []))->body;
        $this->assertStringContainsString('<img class="cover" src="/uploads/cover.png"', $body);
        $this->assertStringNotContainsString('<div class="cover-card">', $body, 'a real cover displaces the typographic card');
    }

    /** category_names is a nullable ", "-joined GROUP_CONCAT string; the
     *  eyebrow re-separates it with middle dots and never leads with a dot. */
    public function test_eyebrow_joins_categories_and_status_with_middle_dots(): void
    {
        $body = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []))->body;
        $this->assertStringContainsString('<p class="eyebrow">General · WIP</p>', $body);
        // after-hours: completed and uncategorized (nullable GROUP_CONCAT)
        $body = $this->app->handle(new Request('GET', '/story/view/after-hours', [], [], []))->body;
        $this->assertStringContainsString('<p class="eyebrow">Complete</p>', $body);
    }

    public function test_stats_definition_list_carries_the_public_counters(): void
    {
        $body = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []))->body;
        $this->assertStringContainsString('<dl class="story-stats">', $body);
        $this->assertStringContainsString('<dt>Rating</dt><dd>Teen</dd>', $body);
        $this->assertStringContainsString('<dt>Words</dt><dd>300</dd>', $body);
        $this->assertStringContainsString('<dt>Kudos</dt><dd>0</dd>', $body);
        $this->assertStringContainsString('<dt>Reviews</dt><dd>0</dd>', $body);
        // the one markup / two presentations pair: the mobile meta line rides
        // along (CSS picks one per breakpoint)
        $this->assertStringContainsString('Teen · 300 words · 2 chapters', $body);
    }

    public function test_chapter_list_carries_read_current_states_and_counts(): void
    {
        (new Database($this->dsn))->query('INSERT INTO reading_history (user_id, story_id, last_position) VALUES (1, 1, 2)');
        $body = $this->client(1)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('<ol class="chapter-list">', $body);
        $this->assertStringContainsString('<li class="is-read">', $body, 'chapter 1 sits behind the furthest read');
        $this->assertStringContainsString('<li class="is-current">', $body);
        $this->assertStringContainsString('aria-current="page"', $body);
        $this->assertStringContainsString('<span class="ch-meta">100</span>', $body, 'bare tabular word count per row');
        $this->assertStringContainsString('<span class="ch-meta">200</span>', $body);
        $this->assertStringContainsString('<span class="ch-title">Down</span>', $body);
    }

    /** The engagement row and reviews section keep their structure; only the
     *  wrapper classes change (every pinned microformat/id survives). */
    public function test_engagement_and_reviews_structure_survive_the_redesign(): void
    {
        $body = $this->client(1)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('<div class="engagement-bar', $body);
        $this->assertStringContainsString('action="/kudos/add/the-rabbit-hole"', $body);
        $this->assertStringContainsString('action="/favorites/toggle/the-rabbit-hole"', $body);
        $this->assertStringContainsString('action="/follow/author/1"', $body);
        $this->assertStringContainsString('action="/story/mark/the-rabbit-hole"', $body);
        $this->assertStringContainsString('action="/report/story/the-rabbit-hole"', $body);
        $this->assertStringContainsString('action="/review/add/the-rabbit-hole"', $body, 'the guest review form survives');
        $this->assertStringContainsString('<h2 id="reviews">', $body);
        $this->assertStringContainsString('</article>', $body);
    }
}
