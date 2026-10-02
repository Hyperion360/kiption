<?php // tests/DemoTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** `bin/kip db:demo`: the manual-testing archive rides on top of the Seeder
 *  fixture without changing it, refuses a second load, rebuilds exactly its
 *  own rows under --force, and stores word counts the pages can trust. */
final class DemoTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-demo-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function rows(string $sql): int
    {
        return (int) $this->db->one($sql)['c'];
    }

    public function test_demo_loads_every_surface_and_keeps_the_fixture(): void
    {
        $made = \App\Demo::run($this->db);
        $this->assertSame(14, $made['users']);
        $this->assertSame(15, $made['stories']);
        // the Seeder fixture the suite pins is untouched
        $this->assertSame(600, (int) $this->db->one("SELECT word_count FROM stories WHERE slug = 'the-rabbit-hole'")['word_count']);
        // every engagement and moderation surface has rows
        foreach (['reviews', 'story_kudos', 'page_stats', 'favorites', 'bookmarks', 'follows', 'messages', 'notifications',
                  'reports', 'reading_lists', 'reading_list_items', 'news_comments', 'challenge_items', 'series_items', 'coauthors'] as $table) {
            $this->assertGreaterThan(0, $this->rows("SELECT COUNT(*) c FROM {$table}"), "{$table} has demo rows");
        }
        // the queue has work: an unvalidated story, an unvalidated chapter, a pending member, open reports
        $this->assertSame(1, $this->rows("SELECT COUNT(*) c FROM stories WHERE slug = 'low-tide' AND validated = 0"));
        $this->assertGreaterThan(0, $this->rows("SELECT COUNT(*) c FROM users WHERE approved_at IS NULL AND email LIKE '%@demo.kiption.test'"));
        $this->assertSame(2, $this->rows('SELECT COUNT(*) c FROM reports WHERE resolved_at IS NULL'));
        // a scheduled chapter is stored unreleased, the way release:due expects
        $this->assertSame(1, $this->rows("SELECT COUNT(*) c FROM chapters WHERE publish_at IS NOT NULL AND validated = 0"));
        // stored story word counts equal their released chapters
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM stories s WHERE s.word_count <>
            (SELECT COALESCE(SUM(c.word_count), 0) FROM chapters c WHERE c.story_id = s.id AND c.validated = 1)'));
        // the canon passages from the comps are the Lantern text
        $this->assertStringContainsString('By the third morning the road had given up pretending to be a road.',
            (string) $this->db->one("SELECT content FROM chapters WHERE title = 'The Salt Road'")['content']);
        // demo prose is searchable through the FTS triggers
        $this->assertGreaterThan(0, $this->rows("SELECT COUNT(*) c FROM chapters_fts WHERE chapters_fts MATCH 'barometer'"));
        // no em dashes in generated text (the project's writing rule)
        $this->assertSame(0, $this->rows("SELECT COUNT(*) c FROM chapters WHERE content LIKE '%—%'"));
    }

    public function test_second_load_refuses_and_force_rebuilds_without_orphans(): void
    {
        \App\Demo::run($this->db);
        $before = [$this->rows('SELECT COUNT(*) c FROM stories'), $this->rows('SELECT COUNT(*) c FROM reviews'), $this->rows('SELECT COUNT(*) c FROM tags')];
        try {
            \App\Demo::run($this->db);
            $this->fail('a second load must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }
        \App\Demo::run($this->db, true);
        $this->assertSame($before, [$this->rows('SELECT COUNT(*) c FROM stories'), $this->rows('SELECT COUNT(*) c FROM reviews'), $this->rows('SELECT COUNT(*) c FROM tags')],
            'a forced rebuild lands on the same archive, no duplicates');
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM reviews WHERE user_id IS NULL AND guest_name IS NULL'), 'no orphaned reviews');
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM story_kudos WHERE user_id IS NULL AND ip = \'\''), 'no orphaned kudos');
    }

    /** --force removes only what the demo created: an operator's category
     *  that shares a demo slug, a warning tag type the demo never makes (and
     *  its link to a real story), and a page at a demo slug all survive. */
    public function test_force_rebuild_never_deletes_operator_rows(): void
    {
        $this->db->query("INSERT INTO categories (name, slug, description) VALUES ('Mystery', 'mystery', 'The operator wrote this.')");
        $this->db->query("INSERT INTO tag_types (name) VALUES ('warning')");
        $this->db->query("INSERT INTO tags (tag_type_id, name) VALUES ((SELECT id FROM tag_types WHERE name = 'warning'), 'Major character death')");
        $this->db->query("INSERT INTO story_tags (story_id, tag_id) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), (SELECT id FROM tags WHERE name = 'Major character death'))");
        $this->db->query("INSERT INTO pages (slug, title, body) VALUES ('guidelines', 'Rules', 'Operator rules.')");
        \App\Demo::run($this->db);
        \App\Demo::run($this->db, true);
        $this->assertSame('The operator wrote this.', (string) $this->db->one("SELECT description FROM categories WHERE slug = 'mystery'")['description']);
        $this->assertSame(1, $this->rows("SELECT COUNT(*) c FROM tag_types WHERE name = 'warning'"), 'a tag type the demo never created survives');
        $this->assertSame(1, $this->rows("SELECT COUNT(*) c FROM story_tags st JOIN tags t ON t.id = st.tag_id WHERE t.name = 'Major character death'"),
            'and so does its link to a real story');
        $this->assertSame('Operator rules.', (string) $this->db->one("SELECT body FROM pages WHERE slug = 'guidelines'")['body']);
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM bookmarks b WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = b.user_id)'),
            'no bookmarks orphaned by the rebuild');
    }

    /** A database holding stories by anyone other than the seed fixture or
     *  demo accounts is a real archive: the demo (and its published admin
     *  password) refuses to load there. */
    public function test_refuses_to_load_into_a_real_archive(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname) VALUES ('owner@archive.example', 'x', 'realwriter')");
        $this->db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated) VALUES ('Real work', 'real-work', (SELECT id FROM users WHERE penname = 'realwriter'), (SELECT id FROM ratings LIMIT 1), 1)");
        try {
            \App\Demo::run($this->db);
            $this->fail('the demo must refuse a real archive');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('real archive', $e->getMessage());
        }
        $this->assertSame(0, $this->rows("SELECT COUNT(*) c FROM users WHERE email LIKE '%@demo.kiption.test'"), 'nothing was written');
    }

    /** A fresh production install has an operator account and no stories
     *  yet. Outside KIP_ENV=dev the demo (and its admin with a published
     *  password) refuses any database holding accounts beyond the demo and
     *  seed fixtures; in dev it loads beside the developer's own account. */
    public function test_refuses_existing_accounts_outside_dev(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, is_admin) VALUES ('owner@archive.example', 'x', 'operator', 1)");
        try {
            \App\Demo::run($this->db);
            $this->fail('the demo must refuse an install with real accounts');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('KIP_ENV=dev', $e->getMessage());
        }
        $this->assertSame(0, $this->rows("SELECT COUNT(*) c FROM users WHERE email LIKE '%@demo.kiption.test'"));
        \App\Demo::run($this->db, dev: true);
        $this->assertGreaterThan(0, $this->rows("SELECT COUNT(*) c FROM users WHERE email LIKE '%@demo.kiption.test'"), 'dev loads beside it');
    }
}
