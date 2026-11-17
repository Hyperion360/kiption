<?php // app/Features/Search/Tests/SearchFtsTagsTest.php
namespace App\Features\Search\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** Migration 067: stories_fts gains a tags column that triggers keep in step. */
#[Medium]
final class SearchFtsTagsTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-ftstags-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        if ($this->db->one("SELECT 1 FROM sqlite_master WHERE name = 'stories_fts'") === null) {
            $this->markTestSkipped('FTS5 is not available in this SQLite build.');
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function story(string $title = 'Plain Title'): int
    {
        $n = ++$this->seq;
        $this->db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated, summary) VALUES (?, ?, (SELECT id FROM users LIMIT 1), (SELECT id FROM ratings LIMIT 1), 1, 'Nothing here.')", [$title, "fts-tags-{$n}"]);
        return (int) $this->db->lastInsertId();
    }

    private function tag(string $name, ?int $canonical = null): int
    {
        $this->db->query('INSERT INTO tags (tag_type_id, name, canonical_id) VALUES ((SELECT id FROM tag_types LIMIT 1), ?, ?)', [$name, $canonical]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array{0:int,1:int} */
    private function storyAndTag(string $tagName): array
    {
        return [$this->story(), $this->tag($tagName)];
    }

    private function attach(int $story, int $tag): void
    {
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$story, $tag]);
    }

    /** @return list<int> */
    private function ftsHits(string $match): array
    {
        return array_map('intval', array_column($this->db->all('SELECT rowid FROM stories_fts WHERE stories_fts MATCH ? ORDER BY rowid', [$match]), 'rowid'));
    }

    public function test_story_tags_insert_makes_the_story_findable_by_tag_name(): void
    {
        [$story, $tag] = $this->storyAndTag('Slow Burn');
        $this->assertSame([], $this->ftsHits('tags:"slow burn"'));
        $this->attach($story, $tag);
        $this->assertSame([$story], $this->ftsHits('tags:"slow burn"'));
    }

    public function test_removing_the_tag_removes_the_hit(): void
    {
        [$story, $tag] = $this->storyAndTag('Slow Burn');
        $this->attach($story, $tag);
        $this->db->query('DELETE FROM story_tags WHERE story_id = ? AND tag_id = ?', [$story, $tag]);
        $this->assertSame([], $this->ftsHits('tags:"slow burn"'));
    }

    public function test_renaming_a_tag_reindexes_its_stories(): void
    {
        [$story, $tag] = $this->storyAndTag('Slow Burn');
        $this->attach($story, $tag);
        $this->db->query("UPDATE tags SET name = 'Fast Burn' WHERE id = ?", [$tag]);
        $this->assertSame([], $this->ftsHits('tags:"slow burn"'));
        $this->assertSame([$story], $this->ftsHits('tags:"fast burn"'));
    }

    public function test_a_synonym_name_finds_the_canonicals_stories(): void
    {
        [$story, $tag] = $this->storyAndTag('Slow Burn');
        $this->attach($story, $tag);
        $this->tag('Sluggish Flame', $tag);
        $this->assertSame([$story], $this->ftsHits('tags:"sluggish flame"'), 'synonym created after attach');
        $other = $this->story();
        $canon = $this->tag('Angst');
        $this->tag('Feelings', $canon);
        $this->attach($other, $canon);
        $this->assertSame([$other], $this->ftsHits('tags:feelings'), 'synonym created before attach');
    }

    public function test_turning_a_tag_into_a_synonym_reindexes(): void
    {
        [$story, $tag] = $this->storyAndTag('Slow Burn');
        $this->attach($story, $tag);
        $canon = $this->tag('Pining');
        $this->db->query('UPDATE tags SET canonical_id = ? WHERE id = ?', [$canon, $tag]);
        $this->assertSame([$story], $this->ftsHits('tags:"slow burn"'), 'story still holds the retired tag');
    }

    public function test_deleting_a_tag_removes_it_from_the_index(): void
    {
        [$story, $tag] = $this->storyAndTag('Slow Burn');
        $this->attach($story, $tag);
        $this->db->query('DELETE FROM tags WHERE id = ?', [$tag]);
        $this->assertSame([], $this->ftsHits('tags:"slow burn"'));
    }

    public function test_story_title_edit_keeps_the_tags_text(): void
    {
        [$story, $tag] = $this->storyAndTag('Slow Burn');
        $this->attach($story, $tag);
        $this->db->query("UPDATE stories SET title = 'Brand New Title' WHERE id = ?", [$story]);
        $this->assertSame([$story], $this->ftsHits('tags:"slow burn"'));
        $this->assertSame([$story], $this->ftsHits('title:brand'));
    }

    public function test_a_new_story_has_no_tags_text_yet_and_old_stories_were_backfilled(): void
    {
        $this->assertNotSame([], $this->ftsHits('title:rabbit OR summary:rabbit'), 'seeded story still indexed');
        $this->assertSame([], $this->ftsHits('tags:"zzz"'));
    }

    public function test_prefix_index_matches_partial_words(): void
    {
        [$story, $tag] = $this->storyAndTag('Zanzibar Burn');
        $this->attach($story, $tag);
        $this->assertSame([$story], $this->ftsHits('"zanz"*'));
    }

    public function test_down_restores_the_two_column_table_and_triggers(): void
    {
        $migration = require dirname(__DIR__) . '/migrations/067_search_fts_tags.php';
        $migration->down($this->db);
        $cols = array_column($this->db->all("PRAGMA table_info('stories_fts')"), 'name');
        $this->assertSame(['title', 'summary'], $cols);
        $triggers = array_column($this->db->all("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'stories_fts%'"), 'name');
        sort($triggers);
        $this->assertSame(['stories_fts_del', 'stories_fts_ins', 'stories_fts_upd'], $triggers);
        $s = $this->story('Quillfeather Saga');
        $this->assertSame([$s], $this->ftsHits('quillfeather'));
        $this->db->query("UPDATE stories SET title = 'Moonlit Saga' WHERE id = ?", [$s]);
        $this->assertSame([$s], $this->ftsHits('moonlit'));
    }

    public function test_up_again_after_down_is_safe(): void
    {
        $migration = require dirname(__DIR__) . '/migrations/067_search_fts_tags.php';
        [$story, $tag] = $this->storyAndTag('Slow Burn');
        $this->attach($story, $tag);
        $migration->down($this->db);
        $migration->up($this->db);
        $this->assertSame([$story], $this->ftsHits('tags:"slow burn"'));
    }
}
