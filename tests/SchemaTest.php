<?php // tests/SchemaTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private function db(): Database
    {
        return new Database('sqlite::memory:');
    }

    private function migrate(Database $db): array
    {
        return (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
    }

    /** @dataProvider tableProvider */
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
            'coauthors', 'chapters', 'series', 'series_items', 'reviews', 'favorites',
            'news', 'news_comments', 'pages', 'mail_templates', 'page_stats',
            'login_attempts', 'password_resets', 'email_verifications', 'invites',
            'import_map', 'import_runs', 'legacy_urls', 'legacy_log',
        ];
        return array_map(static fn(string $t): array => [$t], $tables);
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
        (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->rollback();
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
}
