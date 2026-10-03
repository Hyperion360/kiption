<?php // tests/SchemaTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SchemaTest extends TestCase
{
    private function db(): Database
    {
        return new Database('sqlite::memory:');
    }

    private function migrate(Database $db): array
    {
        return (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    #[DataProvider('tableProvider')]
    public function test_migration_creates_every_table(string $table): void
    {
        $db = $this->db();
        $ran = $this->migrate($db);
        $this->assertContains('001_init', $ran);
        $n = $db->one("SELECT COUNT(*) c FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
        $this->assertSame(1, (int) $n['c'], "table {$table} missing");
    }

    public static function tableProvider(): array
    {
        $tables = [
            'users', 'user_prefs', 'categories', 'characters', 'tag_types', 'tags',
            'ratings', 'stories', 'story_categories', 'story_tags', 'story_characters',
            'coauthors', 'chapters', 'series', 'series_items', 'reading_lists', 'reading_list_items', 'reviews', 'favorites',
            'news', 'news_comments', 'pages', 'mail_templates', 'page_stats',
            'login_attempts', 'password_resets', 'email_verifications', 'invites',
            'import_map', 'import_runs', 'legacy_urls', 'legacy_log',
            'notifications', 'story_kudos', 'follows', 'reading_history', 'reports',
            'contact_log', 'nav_links', 'feature_flags',
            'challenges', 'challenge_prompts', 'challenge_items',
            'muted', 'messages',
        ];
        return array_map(static fn(string $t): array => [$t], $tables);
    }

    /** Migration 023: the muted PK pair IS the toggle idempotence contract
     *  (INSERT OR IGNORE lands at most one row per pair), and tags grows
     *  the wrangling column the M4 batch 2 merge surface writes. */
    public function test_muted_pk_pair_and_tags_canonical_column(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $cols = implode(',', array_map(static fn(array $c): string => $c['name'], $db->all('PRAGMA table_info(tags)')));
        $this->assertStringContainsString('canonical_id', $cols, 'tags.canonical_id missing');
        $db->query('INSERT INTO users (email, password_hash, penname, profile_slug) VALUES (?, ?, ?, ?)', ['a@x.test', 'h', 'Author', 'author']);
        $db->query('INSERT INTO users (email, password_hash, penname, profile_slug) VALUES (?, ?, ?, ?)', ['b@x.test', 'h', 'Muter', 'muter']);
        $db->query('INSERT INTO muted (user_id, author_id) VALUES (2, 1)');
        $this->expectException(\PDOException::class);
        $db->query('INSERT INTO muted (user_id, author_id) VALUES (2, 1)');
    }

    /** Migration 024: user_prefs.lang lands NOT NULL DEFAULT '' (empty = follow
     *  the archive default; the member-set value arrives with the prefs form),
     *  beside the dormant theme column the M4 cascade wakes up. */
    public function test_user_prefs_lang_column_lands_default_empty(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $cols = implode(',', array_map(static fn(array $c): string => $c['name'], $db->all('PRAGMA table_info(user_prefs)')));
        $this->assertStringContainsString('lang', $cols, 'user_prefs.lang missing');
        $this->assertStringContainsString('theme', $cols, 'user_prefs.theme (the dormant M4 column) missing');
        $db->query('INSERT INTO users (email, password_hash, penname, profile_slug) VALUES (?, ?, ?, ?)', ['p@x.test', 'h', 'P', 'p']);
        $db->query('INSERT INTO user_prefs (user_id) VALUES (1)');
        $this->assertSame('', $db->one('SELECT lang FROM user_prefs WHERE user_id = 1')['lang'], 'a fresh prefs row reads lang empty');
    }

    public function test_foreign_keys_are_enforced(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)', ['a@x.test', 'h', 'Author']);
        $db->query('INSERT INTO ratings (label, position) VALUES (?, ?)', ['G', 1]);
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id) VALUES (?, ?, 1, 1)', ['T', 't']);
        $this->expectException(\PDOException::class);
        $db->query('INSERT INTO story_categories (story_id, category_id) VALUES (1, 999)');
    }

    public function test_rollback_drops_the_schema(): void
    {
        $db = $this->db();
        $this->migrate($db);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->rollback();
        $n = $db->one("SELECT COUNT(*) c FROM sqlite_master WHERE type='table' AND name = 'stories'");
        $this->assertSame(0, (int) $n['c']);
    }

    public function test_story_slug_is_unique(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)', ['a@x.test', 'h', 'Author']);
        $db->query('INSERT INTO ratings (label, position) VALUES (?, ?)', ['G', 1]);
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id) VALUES (?, ?, 1, 1)', ['T', 't']);
        $this->expectException(\PDOException::class);
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id) VALUES (?, ?, 1, 1)', ['T2', 't']);
    }

    public function test_profile_slug_is_unique(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $db->query('INSERT INTO users (email, password_hash, penname, profile_slug) VALUES (?, ?, ?, ?)',
            ['a@x.test', 'h', 'Author', 'author']);
        $this->expectException(\PDOException::class);
        $db->query('INSERT INTO users (email, password_hash, penname, profile_slug) VALUES (?, ?, ?, ?)',
            ['b@x.test', 'h', 'Other', 'author']);
    }

    /** Task 9 EXPLAIN sweep pins: the story->series blob and the series count
     *  subqueries must resolve as index SEARCHes, the reason migration 013
     *  exists. Without the indexes both shapes full-scanned series_items on
     *  every /story/view and /account render. */
    public function test_series_items_query_indexes_exist_and_are_used(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $this->seedStory($db);
        $db->query('INSERT INTO series (title, slug, owner_id) VALUES (?, ?, 1)', ['S', 's']);
        $db->query('INSERT INTO series_items (series_id, story_id, position, confirmed) VALUES (1, 1, 1, 1)');
        foreach (['idx_series_items_by_story', 'idx_series_items_counts'] as $idx) {
            $n = $db->one("SELECT COUNT(*) c FROM sqlite_master WHERE type = 'index' AND name = ?", [$idx]);
            $this->assertSame(1, (int) $n['c'], "index {$idx} missing");
        }
        $blobPlan = implode("\n", array_map(
            static fn(array $r): string => $r['detail'],
            $db->all('EXPLAIN QUERY PLAN SELECT COUNT(*) c FROM series_items si JOIN series ser ON ser.id = si.series_id WHERE si.story_id = 1 AND si.confirmed = 1')
        ));
        $this->assertStringContainsString('idx_series_items_by_story', $blobPlan);
        $this->assertStringNotContainsString('SCAN si', $blobPlan);
        $countPlan = implode("\n", array_map(
            static fn(array $r): string => $r['detail'],
            $db->all('EXPLAIN QUERY PLAN SELECT COUNT(*) c FROM series_items WHERE series_id = 1 AND confirmed = 0')
        ));
        $this->assertStringContainsString('idx_series_items_counts', $countPlan);
        $this->assertStringNotContainsString('SCAN', $countPlan);
    }

    /** Both category listings (/browse and /feed/subscribe) walk the
     *  operator's order through migration 031's index: no TEMP B-TREE. */
    public function test_category_listings_are_sort_free(): void
    {
        $db = $this->db();
        $this->migrate($db);
        foreach (['SELECT slug, name FROM categories ORDER BY position, name',
                  'SELECT c.id, c.name, c.slug FROM categories c ORDER BY c.position, c.name'] as $sql) {
            $plan = implode("\n", array_column($db->all('EXPLAIN QUERY PLAN ' . $sql), 'detail'));
            $this->assertStringContainsString('idx_categories_order', $plan, $sql);
            $this->assertStringNotContainsString('TEMP B-TREE', $plan, $sql);
        }
    }

    /** Deleting a story cascades into bookmarks (030's foreign key): the
     *  cascade's lookup by story_id must be a seek, not a scan of every
     *  member's bookmarks under the write lock (migration 032). */
    public function test_bookmark_story_cascade_is_a_seek(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $plan = implode("\n", array_column($db->all('EXPLAIN QUERY PLAN DELETE FROM bookmarks WHERE story_id = 1'), 'detail'));
        $this->assertStringContainsString('idx_bookmarks_story', $plan);
        $this->assertStringNotContainsString('SCAN', $plan);
    }

    /** The two index migrations this branch added roll back and reapply
     *  cleanly: down() drops exactly its index, up() restores it. */
    public function test_index_migrations_031_and_032_roll_back_and_reapply(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $root = dirname(__DIR__);
        foreach (['idx_categories_order' => $root . '/app/migrations/031_categories_order_index.php',
                  'idx_bookmarks_story' => $root . '/app/Features/Reader/migrations/032_bookmarks_story_index.php'] as $index => $file) {
            $count = fn (): int => (int) $db->one("SELECT COUNT(*) c FROM sqlite_master WHERE type = 'index' AND name = ?", [$index])['c'];
            $m = require $file;
            $this->assertSame(1, $count(), "{$index} present after migrate");
            $m->down($db);
            $this->assertSame(0, $count(), "{$index} gone after down()");
            $m->up($db);
            $this->assertSame(1, $count(), "{$index} back after up()");
        }
    }

    private function seedStory(Database $db): void
    {
        $db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)', ['a@x.test', 'h', 'Author']);
        $db->query('INSERT INTO ratings (label, position) VALUES (?, ?)', ['G', 1]);
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id) VALUES (?, ?, 1, 1)', ['T', 't']);
    }

    public function test_series_items_check_rejects_row_with_no_target(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $this->seedStory($db);
        $db->query('INSERT INTO series (title, slug, owner_id) VALUES (?, ?, 1)', ['S', 's']);
        $this->expectException(\PDOException::class);
        $db->query('INSERT INTO series_items (series_id, story_id, subseries_id) VALUES (1, NULL, NULL)');
    }

    public function test_favorites_check_rejects_row_with_two_targets(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $this->seedStory($db);
        $db->query('INSERT INTO series (title, slug, owner_id) VALUES (?, ?, 1)', ['S', 's']);
        $this->expectException(\PDOException::class);
        $db->query('INSERT INTO favorites (user_id, story_id, series_id) VALUES (1, 1, 1)');
    }

    public function test_reviews_check_rejects_row_with_no_target(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $this->seedStory($db);
        $this->expectException(\PDOException::class);
        $db->query('INSERT INTO reviews (story_id, series_id, rating) VALUES (NULL, NULL, 5)');
    }

    public function test_reviews_check_rejects_out_of_range_rating(): void
    {
        $db = $this->db();
        $this->migrate($db);
        $this->seedStory($db);
        $this->expectException(\PDOException::class);
        $db->query('INSERT INTO reviews (story_id, rating) VALUES (1, 11)');
    }

    public function test_legacy_chapter_html_converts_to_markdown_at_rest(): void
    {
        // Phase 5 made markdown the at-rest format; stores seeded before the
        // switch still carry the old renderer's HTML. Migration 006 converts
        // that exact vocabulary so public pages render identically instead of
        // showing escaped markup.
        $db = $this->db();
        $this->migrate($db);
        $this->seedStory($db);
        $legacy = '<p>Falling <em>down</em> the <strong>hole</strong>, past shelves.</p>';
        $db->query('INSERT INTO chapters (story_id, position, title, content, notes_before, notes_after, validated, word_count)
                    SELECT id, 1, \'One\', ?, \'<p>Before.</p>\', \'\', 1, 8 FROM stories WHERE id = 1', [$legacy]);
        $db->query("UPDATE stories SET notes = '<p>Thanks.</p>' WHERE id = 1");
        (require dirname(__DIR__) . '/app/migrations/006_legacy_content_to_markdown.php')->up($db);

        $ch = $db->one('SELECT content, notes_before FROM chapters WHERE story_id = 1');
        $this->assertSame('Falling *down* the **hole**, past shelves.', $ch['content']);
        $this->assertSame('Before.', $ch['notes_before']);
        $this->assertSame('Thanks.', $db->one('SELECT notes FROM stories WHERE id = 1')['notes']);
        // The whole point: rendered output identical to the legacy HTML.
        $this->assertSame($legacy . "\n", \App\Markdown::render((string) $ch['content']));
    }
}
