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
}
