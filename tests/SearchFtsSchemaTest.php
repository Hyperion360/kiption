<?php // tests/SearchFtsSchemaTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class SearchFtsSchemaTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-fts-') . '.sqlite';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    public function test_fts_tables_exist_backfilled_and_trigger_synced(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $hit = $db->one("SELECT rowid FROM stories_fts WHERE stories_fts MATCH 'rabbit'");
        $this->assertNotNull($hit, 'seeded story title/summary must be backfilled');
        $chapterHit = $db->one("SELECT story_id FROM chapters_fts WHERE chapters_fts MATCH 'fall'");
        $this->assertNotNull($chapterHit, 'seeded chapter content must be backfilled');
        // trigger sync: a new story + chapter appear in FTS without any PHP write-path change
        $db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated, summary) VALUES ('Zephyr Notes', 'zephyr-notes', (SELECT id FROM users WHERE penname = 'Demo Author'), (SELECT id FROM ratings LIMIT 1), 1, 'A windy study.')");
        $db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES ((SELECT id FROM stories WHERE slug = 'zephyr-notes'), 1, 'One', 'The gale howls uniquely.', 1, 3)");
        $this->assertNotNull($db->one("SELECT rowid FROM stories_fts WHERE stories_fts MATCH 'zephyr'"));
        $this->assertNotNull($db->one("SELECT story_id FROM chapters_fts WHERE chapters_fts MATCH 'gale'"));
        // update + delete triggers keep the index honest
        $db->query("UPDATE stories SET summary = 'Rewritten breeze.' WHERE slug = 'zephyr-notes'");
        $this->assertNull($db->one("SELECT rowid FROM stories_fts WHERE stories_fts MATCH 'windy'"));
        $this->assertNotNull($db->one("SELECT rowid FROM stories_fts WHERE stories_fts MATCH 'breeze'"));
        $db->query("DELETE FROM stories WHERE slug = 'zephyr-notes'");
        $this->assertNull($db->one("SELECT rowid FROM stories_fts WHERE stories_fts MATCH 'zephyr'"));
    }

    public function test_fts_feature_detection_probe(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $repo = new \App\Repositories\SearchRepository($db);
        $this->assertTrue($repo->ftsAvailable(), 'this build has FTS5 (probed)');
    }
}
