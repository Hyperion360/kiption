<?php // tests/StoryRepositoryTest.php
namespace App\Tests;
use App\Repositories\StoryRepository;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class StoryRepositoryTest extends TestCase
{
    private string $dsn = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->dsn = 'sqlite:' . tempnam(sys_get_temp_dir(), 'kiption-repo-') . '.sqlite';
        $this->db = new Database($this->dsn);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        $this->db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)',
            ['a@x.test', 'h', 'Demo Author']);
        $this->db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, ?, ?, ?)',
            ['Teen', 0, '', 2]);
        $this->db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, ?, ?, ?)',
            ['Explicit', 1, 'Adult content', 4]);
        $this->db->query('INSERT INTO categories (name, slug) VALUES (?, ?)', ['General', 'general']);
        // story 1: teen, 2 chapters, updated earlier
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, updated_at) VALUES (?, ?, ?, 1, 1, 1, 0, 200, ?)',
            ['The Rabbit Hole', 'the-rabbit-hole', 'Falling, slowly.', '2026-09-01T10:00:00Z']);
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, ?, ?, ?, 1, 100)',
            [1, 'Down', 'Falling <em>down</em> the hole.']);
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, ?, ?, ?, 1, 100)',
            [2, 'Through', 'Through the door.']);
        $this->db->query('INSERT INTO story_categories (story_id, category_id) VALUES (1, 1)');
        // story 2: explicit, 1 chapter, updated later; NOT validated
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, updated_at) VALUES (?, ?, ?, 1, 2, 0, 1, 100, ?)',
            ['Hidden', 'hidden', 'Unvalidated.', '2026-09-05T10:00:00Z']);
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (2, 1, ?, ?, 0, 100)',
            ['One', 'Body.']);
    }

    protected function tearDown(): void
    {
        unset($this->db);
        @unlink(substr($this->dsn, 7));
        @unlink(substr($this->dsn, 7) . '-wal');
        @unlink(substr($this->dsn, 7) . '-shm');
    }

    public function test_find_by_slug_returns_story_with_author_and_rating(): void
    {
        $story = (new StoryRepository($this->db))->findStoryBySlug('the-rabbit-hole');
        $this->assertNotNull($story);
        $this->assertSame('The Rabbit Hole', $story['title']);
        $this->assertSame('Demo Author', $story['penname']);
        $this->assertSame('Teen', $story['rating_label']);
        $this->assertSame('General', $story['category_names']);
    }

    public function test_find_by_slug_rejects_unvalidated(): void
    {
        $this->assertNull((new StoryRepository($this->db))->findStoryBySlug('hidden'));
    }

    public function test_find_by_slug_unknown_is_null(): void
    {
        $this->assertNull((new StoryRepository($this->db))->findStoryBySlug('nope'));
    }

    public function test_story_landing_returns_toc_blob(): void
    {
        $story = (new StoryRepository($this->db))->findStoryBySlug('the-rabbit-hole');
        $toc = json_decode((string) $story['chapters_blob'], true);
        $this->assertSame([
            ['position' => 1, 'title' => 'Down', 'word_count' => 100],
            ['position' => 2, 'title' => 'Through', 'word_count' => 100],
        ], $toc);
    }

    public function test_toc_blob_survives_delimiter_characters_in_titles(): void
    {
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, 3, ?, ?, 1, 444)',
            ['A|B~C', '<p>x</p>']);
        $story = (new StoryRepository($this->db))->findStoryBySlug('the-rabbit-hole');
        $toc = json_decode((string) $story['chapters_blob'], true);
        $this->assertCount(3, $toc);
        $this->assertSame(['position' => 3, 'title' => 'A|B~C', 'word_count' => 444], $toc[2]);
    }

    public function test_find_story_with_chapter_pivots_target(): void
    {
        $row = (new StoryRepository($this->db))->findStoryWithChapter('the-rabbit-hole', 2);
        $this->assertNotNull($row);
        $this->assertSame('Through', $row['ch_title']);
        $this->assertSame('Through the door.', $row['ch_content']);
        $this->assertSame('1~2', $row['positions_blob']);
        $this->assertSame('Demo Author', $row['penname']);
    }

    public function test_find_story_with_missing_chapter_yields_null_pivot(): void
    {
        $row = (new StoryRepository($this->db))->findStoryWithChapter('the-rabbit-hole', 9);
        $this->assertNotNull($row); // story exists
        $this->assertNull($row['ch_title']); // chapter does not: controller 404s on this
    }

    public function test_recency_query_plan_is_index_backed(): void
    {
        $plan = $this->db->all('EXPLAIN QUERY PLAN SELECT slug FROM stories WHERE validated = 1 AND deleted_at IS NULL ORDER BY updated_at DESC, id DESC LIMIT 20');
        $text = implode(' ', array_column($plan, 'detail'));
        $this->assertStringNotContainsString('SCAN', $text);
        $this->assertStringNotContainsString('TEMP B-TREE', $text);
    }

    public function test_story_view_engagement_subqueries_are_scan_free(): void
    {
        // findStoryBySlug's favorite_count subquery resolves story-side without
        // an index (idx_favorites_story leads with user_id), so the whole
        // favorites table scanned on every story page render. The story-side
        // partial index must keep the phase-6a shape SCAN-free.
        $plan = $this->db->all(
            'EXPLAIN QUERY PLAN SELECT s.*,
                (SELECT COUNT(*) FROM story_kudos k WHERE k.story_id = s.id) AS kudos_count,
                (SELECT COUNT(*) FROM favorites f WHERE f.story_id = s.id) AS favorite_count,
                (SELECT COUNT(*) FROM story_kudos k2 WHERE k2.story_id = s.id AND k2.user_id = 2) AS kudos_by_me,
                (SELECT COUNT(*) FROM favorites f2 WHERE f2.story_id = s.id AND f2.user_id = 2) AS favorite_by_me,
                (SELECT COUNT(*) FROM follows fo WHERE fo.author_id = s.author_id AND fo.follower_id = 2) AS following_author,
                (SELECT rh.marked_at FROM reading_history rh WHERE rh.story_id = s.id AND rh.user_id = 2) AS marked_at_me
             FROM stories s WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL',
            ['the-rabbit-hole']);
        $text = implode(' ', array_column($plan, 'detail'));
        $this->assertStringNotContainsString('SCAN', $text);
    }

    public function test_story_landing_roots_window_is_index_walked(): void
    {
        // Ruling (plan review findings 12/13): the replies-of-window
        // materialization is bounded by construction (a TEMP B-TREE over at
        // most the replies of the 50 window roots, capped at 200); the roots
        // window itself is index-walked via the partial idx_reviews_roots.
        // The 6b blanket sort-free pin is superseded by this scoped pin.
        for ($i = 0; $i < 60; $i++) {
            $this->db->query('INSERT INTO reviews (story_id, user_id, guest_name, body, ip) VALUES (1, NULL, ?, ?, ?)',
                ['g' . $i, 'b', '127.0.0.1']);
        }
        $sql = null;
        $this->db->onQuery(function (string $s) use (&$sql): void { $sql = $s; });
        (new StoryRepository($this->db))->findStoryBySlug('the-rabbit-hole');
        $this->db->onQuery(fn () => null);
        $plan = $this->db->all('EXPLAIN QUERY PLAN ' . (string) $sql, [0, 0, 0, 0, 'the-rabbit-hole', 0]);
        $text = implode(' ', array_column($plan, 'detail'));
        $this->assertStringContainsString('USING INDEX idx_reviews_roots', $text,
            'the roots window must walk idx_reviews_roots (alias-tolerant: SQLite emits the table alias)');
        $this->assertStringNotContainsString('SCAN TABLE reviews', $text,
            'no review-table scan may back the story landing fold');
    }

    public function test_recent_validated_first_page(): void
    {
        $rows = (new StoryRepository($this->db))->recentStories(20, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('the-rabbit-hole', $rows[0]['slug']);
    }

    public function test_categories_with_counts_count_validated_only(): void
    {
        $rows = (new StoryRepository($this->db))->categoriesWithCounts();
        $this->assertCount(1, $rows);
        $this->assertSame('general', $rows[0]['slug']);
        $this->assertSame(1, (int) $rows[0]['story_count']);
    }

    /** A second user (id 2) who has muted Demo Author (id 1), for the
     *  member-listing clause pins below. */
    private function plantViewerMutingAuthor(): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)',
            ['viewer@x.test', 'h', 'The Viewer']);
        $viewer = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO muted (user_id, author_id) VALUES (?, ?)', [$viewer, 1]);
        return $viewer;
    }

    /** Capture one listing call's SQL (the last query the handle ran). */
    private function captureListingSql(callable $run): string
    {
        $sql = '';
        $this->db->onQuery(function (string $s) use (&$sql): void { $sql = $s; });
        $run();
        $this->db->onQuery(fn () => null);
        return $sql;
    }

    /** Finding 8's member-listing pin, shared shape: the member call's SQL
     *  carries MuteRepository's clause verbatim; the anonymous call's SQL
     *  never mentions muted (byte-identity by construction); the hand-counted
     *  member bind array explains against the captured SQL (a wrong count
     *  fails the prepare); finding 7: the muted probe is an index SEARCH,
     *  covering, over the PK autoindex (idx_muted_author stays the FK-cascade
     *  servant, never the probe's pick). @param list<int|string> $binds */
    private function assertMemberListingPin(string $memberSql, string $anonSql, array $binds): void
    {
        $this->assertStringContainsString(\App\Repositories\MuteRepository::clause('s'), $memberSql);
        $this->assertStringNotContainsString('muted', $anonSql);
        $plan = $this->db->all('EXPLAIN QUERY PLAN ' . $memberSql, $binds);
        $text = implode(' ', array_column($plan, 'detail'));
        $this->assertStringContainsString('SEARCH mu USING COVERING INDEX sqlite_autoindex_muted_1', $text,
            'the muted probe must ride the PK autoindex as a covering SEARCH');
        $this->assertStringNotContainsString('SCAN mu', $text);
        $this->assertStringNotContainsString('idx_muted_author', $text);
    }

    public function test_recent_stories_member_clause_and_binds(): void
    {
        $viewer = $this->plantViewerMutingAuthor();
        $repo = new StoryRepository($this->db);
        $memberRows = null;
        $memberSql = $this->captureListingSql(function () use ($repo, $viewer, &$memberRows): void {
            $memberRows = $repo->recentStories(20, 0, $viewer);
        });
        $anonRows = null;
        $anonSql = $this->captureListingSql(function () use ($repo, &$anonRows): void {
            $anonRows = $repo->recentStories(20, 0);
        });
        // Member binds in text order: the clause's ? sits in the WHERE ahead
        // of LIMIT/OFFSET, so [$viewer, $perPage, $offset].
        $this->assertMemberListingPin($memberSql, $anonSql, [$viewer, 20, 0]);
        $this->assertSame([], $memberRows, 'the muted author only has the one seeded story');
        $this->assertCount(1, $anonRows);
    }

    public function test_stories_in_category_member_clause_and_binds(): void
    {
        $viewer = $this->plantViewerMutingAuthor();
        $repo = new StoryRepository($this->db);
        $memberRows = null;
        $memberSql = $this->captureListingSql(function () use ($repo, $viewer, &$memberRows): void {
            $memberRows = $repo->storiesInCategory('general', 20, 0, $viewer);
        });
        $anonRows = null;
        $anonSql = $this->captureListingSql(function () use ($repo, &$anonRows): void {
            $anonRows = $repo->storiesInCategory('general', 20, 0);
        });
        // [$slug, $viewer, $perPage, $offset]: the clause follows the category
        // and visibility gates, ahead of LIMIT/OFFSET.
        $this->assertMemberListingPin($memberSql, $anonSql, ['general', $viewer, 20, 0]);
        $this->assertSame([], $memberRows);
        $this->assertCount(1, $anonRows);
    }

    public function test_stories_in_language_member_clause_and_binds(): void
    {
        $this->db->query("UPDATE stories SET language = 'en' WHERE id = 1");
        $viewer = $this->plantViewerMutingAuthor();
        $repo = new StoryRepository($this->db);
        $memberRows = null;
        $memberSql = $this->captureListingSql(function () use ($repo, $viewer, &$memberRows): void {
            $memberRows = $repo->storiesInLanguage('en', $viewer);
        });
        $anonRows = null;
        $anonSql = $this->captureListingSql(function () use ($repo, &$anonRows): void {
            $anonRows = $repo->storiesInLanguage('en');
        });
        // [$language, $viewer]: the clause trails the language gate; the
        // LIMIT 50 carries no binds.
        $this->assertMemberListingPin($memberSql, $anonSql, ['en', $viewer]);
        $this->assertSame([], $memberRows);
        $this->assertCount(1, $anonRows);
    }
}
