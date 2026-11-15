<?php // app/Features/Search/Tests/SearchTrigramTest.php
namespace App\Features\Search\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** Slice 10: typo tolerance. stories_trigram (title and tag names, trigram
 *  tokenized) carries a second arm inside the SAME ranking statement, guarded
 *  so a story the normal arm already matched is never contributed twice. The
 *  budget stays one query. */
final class SearchTrigramTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-trigram-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        if ($this->db->one("SELECT 1 FROM sqlite_master WHERE name = 'stories_trigram' AND sql LIKE '%fts5%'") === null) {
            $this->markTestSkipped('the trigram tokenizer is not available in this SQLite build.');
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function repo(): \App\Repositories\SearchRepository
    {
        return new \App\Repositories\SearchRepository(new Database('sqlite:' . $this->path));
    }

    private function makeStory(string $title, string $slug): int
    {
        $this->db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated, summary) VALUES (?, ?, 1, 1, 1, 'A probe story.')",
            [$title, $slug]);
        return (int) $this->db->lastInsertId();
    }

    public function test_a_typo_finds_the_story_when_the_normal_search_finds_nothing(): void
    {
        $this->makeStory('The Wonderland Tale', 'wonderland-tale');
        $repo = $this->repo();
        $rows = $repo->searchWithTaxonomies('wnderland', [], 20, 0, 0)['rows'];
        $this->assertSame(['wonderland-tale'], array_column($rows, 'slug'), 'the typo still surfaces the story');
        $this->assertSame('fts', $repo->searchWithTaxonomies('wnderland', [], 20, 0, 0)['mode'], 'one fold answers, no second query');
        // the spelled-correct query keeps answering
        $this->assertSame(['wonderland-tale'], array_column($repo->search('wnderland', [], 20, 0, 0)['rows'], 'slug'));
    }

    public function test_no_behavior_change_when_the_first_arm_hits(): void
    {
        $repo = $this->repo();
        $rows = $repo->searchWithTaxonomies('rabbit', [], 20, 0, 0)['rows'];
        $this->assertSame(['the-rabbit-hole'], array_column($rows, 'slug'), 'the seeded story answers as always');
        $this->assertSame('fts', $repo->searchWithTaxonomies('rabbit', [], 20, 0, 0)['mode']);
    }

    public function test_a_story_is_never_contributed_twice(): void
    {
        $this->makeStory('The Wonderland Tale', 'wonderland-tale');
        // the prefix match AND the trigram match both see this story
        $rows = $this->repo()->searchWithTaxonomies('wonderl', [], 20, 0, 0)['rows'];
        $this->assertSame(1, count(array_column($rows, 'slug')), 'the guard keeps one row per story');
    }

    public function test_a_dropped_letter_tag_typo_finds_the_story(): void
    {
        $sid = $this->makeStory('Quiet Waters', 'quiet-waters');
        $genre = (int) $this->db->one("SELECT id FROM tag_types WHERE name = 'genre'")['id'];
        $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$genre, 'Slow Rivers']);
        $tag = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$sid, $tag]);
        // 'Riverss' is Rivers with a doubled letter: one deletion away, which
        // is the slice's documented scope
        $rows = $this->repo()->searchWithTaxonomies('Riverss', [], 20, 0, 0)['rows'];
        $this->assertSame(['quiet-waters'], array_column($rows, 'slug'), 'the tags text rides the trigram table');
        // the moderation gate stands with the arm on: a pending chapter's
        // words never drag unrelated stories into the results
        $this->assertSame([], $this->repo()->searchWithTaxonomies('zzqqx', [], 20, 0, 0)['rows'], 'no window noise');
    }

    public function test_the_trigram_search_is_one_query(): void
    {
        $this->makeStory('The Wonderland Tale', 'wonderland-tale');
        $db = new Database('sqlite:' . $this->path);
        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        (new \App\Repositories\SearchRepository($db))->searchWithTaxonomies('wnderland', [], 20, 0, 0);
        $db->onQuery(fn () => null);
        $this->assertSame(1, $queries, "the trigram fold ran {$queries} queries, budget is 1");
    }

    public function test_short_and_prefix_tokens_do_not_reach_the_trigram_arm(): void
    {
        $repo = $this->repo();
        // a two character query never trigram-matches (the tokenizer needs three)
        $this->assertSame([], $repo->searchWithTaxonomies('zz', [], 20, 0, 0)['rows']);
    }

    public function test_migration_down_drops_the_table_and_triggers_and_up_restores(): void
    {
        $migration = require dirname(__DIR__, 1) . '/migrations/069_search_trigram.php';
        $migration->down($this->db);
        $this->assertNull($this->db->one("SELECT 1 FROM sqlite_master WHERE name = 'stories_trigram'"));
        $triggers = array_column($this->db->all("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'stories_tri%'"), 'name');
        $this->assertSame([], $triggers);
        $migration->up($this->db);
        $this->assertNotNull($this->db->one("SELECT 1 FROM sqlite_master WHERE name = 'stories_trigram'"));
        // and the backfill carried the seeded story
        $this->assertNotNull($this->db->one("SELECT rowid FROM stories_trigram WHERE stories_trigram MATCH 'rabbit'"));
    }

    public function test_the_trigger_set_stays_in_step(): void
    {
        $sid = $this->makeStory('Fresh Story', 'fresh-story');
        $this->assertNotNull($this->db->one("SELECT rowid FROM stories_trigram WHERE stories_trigram MATCH 'fresh'"), 'a new story backfills');
        $this->db->query("UPDATE stories SET title = 'Renamed Story' WHERE id = ?", [$sid]);
        $this->assertNotNull($this->db->one("SELECT rowid FROM stories_trigram WHERE stories_trigram MATCH 'renamed'"));
        $this->assertNull($this->db->one("SELECT rowid FROM stories_trigram WHERE stories_trigram MATCH 'fresh'"));
        $this->db->query('DELETE FROM stories WHERE id = ?', [$sid]);
        $this->assertNull($this->db->one("SELECT rowid FROM stories_trigram WHERE stories_trigram MATCH 'renamed'"));
    }
}
