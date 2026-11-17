<?php // tests/BrowseFilterRepositoryTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;
use App\Repositories\BrowseFilterRepository;
use App\Repositories\BrowseQuery;
use App\Tests\Support\QueryTap;
use App\Tests\Support\TaxonomyCase;

#[Small]
final class BrowseFilterRepositoryTest extends TaxonomyCase
{
    private BrowseFilterRepository $repo;
    private int $ratingId = 0;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new BrowseFilterRepository($this->db);
        $this->ratingId = (int) $this->row('SELECT id FROM ratings ORDER BY id LIMIT 1')['id'];
        // Start from an empty shelf so every assertion names exactly the rows it made.
        $this->db->query('DELETE FROM story_tags');
        $this->db->query('DELETE FROM story_categories');
        $this->db->query('DELETE FROM stories');
    }

    /** @param array<string,mixed> $o */
    private function story(string $title, array $o = []): int
    {
        $this->n++;
        $o += ['words' => 1000, 'completed' => 0, 'language' => 'en', 'created' => '2026-01-01T00:00:00Z', 'updated' => '2026-01-01T00:00:00Z',
               'author' => $this->authorId, 'restricted' => 0, 'validated' => 1];
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, completed, validated, word_count, language, is_restricted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$title, 'story-' . $this->n, 'A summary.', $o['author'], $this->ratingId, $o['completed'], $o['validated'], $o['words'], $o['language'], $o['restricted'], $o['created'], $o['updated']]);
        return (int) $this->db->lastInsertId();
    }

    private function tag(string $name): int
    {
        return (int) $this->row('SELECT id FROM tags WHERE name = ?', [$name])['id'];
    }

    private function tagStory(int $story, int ...$tags): void
    {
        foreach ($tags as $t) {
            $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$story, $t]);
        }
    }

    /** @return list<string> titles in result order */
    private function titles(array $get, int $viewer = 0): array
    {
        return array_column($this->repo->page(BrowseQuery::fromQuery($get), $viewer)['rows'], 'title');
    }

    public function test_include_one_tag_returns_only_tagged_stories(): void
    {
        $a = $this->story('A');
        $this->story('B');
        $this->tagStory($a, $this->tag('Fantasy'));
        $this->assertSame(['A'], $this->titles(['tag' => [$this->tag('Fantasy')]]));
    }

    public function test_include_two_tags_is_and(): void
    {
        $a = $this->story('A');
        $b = $this->story('B');
        $this->tagStory($a, $this->tag('Fantasy'), $this->tag('Adventure'));
        $this->tagStory($b, $this->tag('Fantasy'));
        $this->assertSame(['A'], $this->titles(['tag' => [$this->tag('Fantasy'), $this->tag('Adventure')]]));
    }

    public function test_boundary_name_inclusions_and_exclusions_preserve_the_normalized_query(): void
    {
        $prefix = str_repeat('x', 63);
        $type = (int) $this->row('SELECT id FROM tag_types LIMIT 1')['id'];
        foreach (['Short' => $prefix, 'Boundary' => $prefix . ' '] as $title => $name) {
            $story = $this->story($title);
            $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$type, $name]);
            $this->tagStory($story, (int) $this->db->lastInsertId());
        }
        $this->assertSame(['Boundary'], $this->titles(['tags' => $prefix . ' suffix']));
        $this->assertSame(['Short'], $this->titles(['xtags' => $prefix . ' suffix']));
    }

    public function test_exclude_removes_stories(): void
    {
        $a = $this->story('A', ['updated' => '2026-02-01T00:00:00Z']);
        $this->story('B');
        $this->tagStory($a, $this->tag('Adventure'));
        $this->assertSame(['B'], $this->titles(['xtag' => [$this->tag('Adventure')]]));
        $this->assertSame(['A', 'B'], $this->titles([]));
        $this->assertSame(['A'], $this->titles(['tag' => [$this->tag('Adventure')], 'xtag' => [$this->tag('Fantasy')]]));
    }

    public function test_a_retired_synonym_id_finds_the_canonicals_stories(): void
    {
        $a = $this->story('A');
        $this->story('B');
        $fantasy = $this->tag('Fantasy');
        $this->db->query("INSERT INTO tags (tag_type_id, name, canonical_id) VALUES ((SELECT tag_type_id FROM tags WHERE id = ?), 'Fantasyish', ?)", [$fantasy, $fantasy]);
        $syn = (int) $this->db->lastInsertId();
        $this->tagStory($a, $fantasy);
        $this->assertSame(['A'], $this->titles(['tag' => [$syn]]));
        $this->assertSame(['B'], $this->titles(['xtag' => [$syn]]));
    }

    public function test_status_complete_and_wip(): void
    {
        $this->story('Done', ['completed' => 1]);
        $this->story('Going', ['completed' => 0]);
        $this->assertSame(['Done'], $this->titles(['status' => 'complete']));
        $this->assertSame(['Going'], $this->titles(['status' => 'wip']));
        $this->assertCount(2, $this->titles(['status' => 'any']));
    }

    public function test_word_range_and_a_swapped_range(): void
    {
        $this->story('Short', ['words' => 500, 'updated' => '2026-01-03T00:00:00Z']);
        $this->story('Mid', ['words' => 5000, 'updated' => '2026-01-02T00:00:00Z']);
        $this->story('Long', ['words' => 50000, 'updated' => '2026-01-01T00:00:00Z']);
        $this->assertSame(['Mid', 'Long'], $this->titles(['wmin' => '1000']));
        $this->assertSame(['Short', 'Mid'], $this->titles(['wmax' => '5000']));
        $this->assertSame(['Mid'], $this->titles(['wmin' => '1000', 'wmax' => '10000']));
        $this->assertSame(['Mid'], $this->titles(['wmin' => '10000', 'wmax' => '1000']), 'a swapped range is read the right way round');
        $this->assertCount(3, $this->titles(['wmin' => 'abc', 'wmax' => '-4']), 'junk bounds are no bounds');
    }

    public function test_language(): void
    {
        $this->story('English', ['language' => 'en']);
        $this->story('French', ['language' => 'fr']);
        $this->assertSame(['French'], $this->titles(['language' => 'fr']));
        $this->assertCount(2, $this->titles(['language' => 'EN!!']), 'junk reads as no language filter');
    }

    public function test_category(): void
    {
        $a = $this->story('A');
        $this->story('B');
        $general = (int) $this->row("SELECT id FROM categories WHERE slug = 'general'")['id'];
        $this->db->query('INSERT INTO story_categories (story_id, category_id) VALUES (?, ?)', [$a, $general]);
        $this->assertSame(['A'], $this->titles(['cat' => 'general']));
    }

    public function test_each_sort_orders_as_documented_with_ties_broken_by_id(): void
    {
        $this->story('banana', ['words' => 100, 'created' => '2026-01-02T00:00:00Z', 'updated' => '2026-01-01T00:00:00Z']);
        $this->story('Apple', ['words' => 300, 'created' => '2026-01-01T00:00:00Z', 'updated' => '2026-01-03T00:00:00Z']);
        $this->story('cherry', ['words' => 300, 'created' => '2026-01-03T00:00:00Z', 'updated' => '2026-01-02T00:00:00Z']);
        $this->assertSame(['Apple', 'cherry', 'banana'], $this->titles(['sort' => 'updated']));
        $this->assertSame(['cherry', 'banana', 'Apple'], $this->titles(['sort' => 'created']));
        $this->assertSame(['cherry', 'Apple', 'banana'], $this->titles(['sort' => 'words']), 'equal word counts: the newer id first');
        $this->assertSame(['Apple', 'banana', 'cherry'], $this->titles(['sort' => 'title']), 'case-insensitive');
        $this->assertSame(['Apple', 'cherry', 'banana'], $this->titles(['sort' => 'hax']), 'junk sort is the default');
    }

    public function test_restricted_unvalidated_and_deleted_stories_never_appear(): void
    {
        $this->story('Visible');
        $this->story('Restricted', ['restricted' => 1]);
        $this->story('Pending', ['validated' => 0]);
        $gone = $this->story('Gone');
        $this->db->query("UPDATE stories SET deleted_at = '2026-01-05T00:00:00Z' WHERE id = ?", [$gone]);
        $this->assertSame(['Visible'], $this->titles([], $this->memberId));
        $this->assertSame(['Visible'], $this->titles([]));
    }

    public function test_a_muted_authors_stories_vanish_for_the_muting_member_only(): void
    {
        $this->story('Theirs', ['author' => $this->authorId]);
        $this->story('Mine', ['author' => $this->memberId]);
        $this->db->query('INSERT INTO muted (user_id, author_id) VALUES (?, ?)', [$this->memberId, $this->authorId]);
        $titles = $this->titles([], $this->memberId);
        $this->assertSame(['Mine'], $titles);
        $this->assertCount(2, $this->titles([], $this->adminId), 'another member still sees both');
        $this->assertCount(2, $this->titles([]), 'so does a guest');
    }

    public function test_the_probe_row_sets_has_more_and_is_sliced_off(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->story('S' . $i, ['updated' => sprintf('2026-01-%02dT00:00:00Z', $i)]);
        }
        $p1 = $this->repo->page(BrowseQuery::fromQuery([]));
        $this->assertCount(20, $p1['rows']);
        $this->assertTrue($p1['hasMore']);
        $p2 = $this->repo->page(BrowseQuery::fromQuery(['page' => '2']));
        $this->assertSame(['S1'], array_column($p2['rows'], 'title'));
        $this->assertFalse($p2['hasMore']);
    }

    public function test_the_picker_hides_retired_synonyms_caps_each_type_and_keeps_selected_names(): void
    {
        $typeId = (int) $this->row('SELECT id FROM tag_types ORDER BY id LIMIT 1')['id'];
        $fantasy = $this->tag('Fantasy');
        $this->db->query("INSERT INTO tags (tag_type_id, name, canonical_id) VALUES ((SELECT tag_type_id FROM tags WHERE id = ?), 'Fantasyish', ?)", [$fantasy, $fantasy]);
        for ($i = 0; $i < 120; $i++) {
            $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$typeId, sprintf('zzz%03d', $i)]);
        }
        $last = $this->tag('zzz119');
        $r = $this->repo->page(BrowseQuery::fromQuery(['tag' => [$last]]));
        $names = array_column($r['tags'], 'name');
        $this->assertNotContains('Fantasyish', $names);
        $perType = array_count_values(array_column($r['tags'], 'type'));
        $this->assertLessThanOrEqual(BrowseFilterRepository::TAGS_PER_TYPE, max($perType));
        $this->assertNotContains('zzz119', $names, 'past the cap');
        $this->assertSame(['zzz119'], array_column($r['selected'], 'name'), 'but a selected tag still has its name');
        $this->assertNotSame([], $r['types']);
        $this->assertNotSame([], $r['categories']);
    }

    public function test_languages_lists_each_language_in_use_once_sorted(): void
    {
        $this->story('A', ['language' => 'fr']);
        $this->story('B', ['language' => 'en']);
        $this->story('C', ['language' => 'en']);
        $this->story('D', ['language' => '']);
        $this->story('E', ['language' => 'de', 'validated' => 0]);
        $this->assertSame(['en', 'fr'], $this->repo->page(BrowseQuery::fromQuery([]))['languages']);
    }

    public function test_languages_leave_out_those_only_restricted_or_deleted_stories_use(): void
    {
        $this->story('A', ['language' => 'en']);
        $this->story('B', ['language' => 'fr', 'restricted' => 1]);
        $gone = $this->story('C', ['language' => 'de']);
        $this->db->query("UPDATE stories SET deleted_at = '2026-02-01T00:00:00Z' WHERE id = ?", [$gone]);
        $this->story('D', ['language' => 'es']);
        $this->story('E', ['language' => 'es', 'restricted' => 1]);
        $this->assertSame(['en', 'es'], $this->repo->page(BrowseQuery::fromQuery([]))['languages']);
    }

    public function test_picker_tags_come_back_alphabetical_within_each_type(): void
    {
        $typeId = (int) $this->row('SELECT id FROM tag_types ORDER BY id LIMIT 1')['id'];
        foreach (['mango', 'Apple', 'zebra', 'banana'] as $n) {
            $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$typeId, $n]);
        }
        $tags = $this->repo->page(BrowseQuery::fromQuery([]))['tags'];
        $prev = null;
        foreach ($tags as $t) {
            $key = [$t['type'], strtolower($t['name'])];
            if ($prev !== null) {
                $this->assertLessThanOrEqual(0, $prev <=> $key, 'out of order at ' . $t['name']);
            }
            $prev = $key;
        }
        $mine = array_values(array_filter(array_column(array_filter($tags, fn ($t) => $t['type'] === $typeId), 'name'), fn ($n) => in_array($n, ['mango', 'Apple', 'zebra', 'banana'], true)));
        $this->assertSame(['Apple', 'banana', 'mango', 'zebra'], $mine);
    }

    public function test_the_picker_stops_at_the_cap_per_type_without_walking_the_tags_table(): void
    {
        [$sql, $binds] = $this->repo->statement(BrowseQuery::fromQuery([]));
        $plan = implode("\n", array_column($this->db->all('EXPLAIN QUERY PLAN ' . $sql, $binds), 'detail'));
        $this->assertStringContainsString('idx_tags_type_name', $plan, $plan);
        $this->assertStringNotContainsString('SCAN tags', $plan, $plan);
    }

    public function test_one_statement_runs_the_whole_page(): void
    {
        $this->story('A');
        $query = BrowseQuery::fromQuery(['tag' => [$this->tag('Fantasy')], 'xtag' => [$this->tag('Adventure')], 'wmin' => '10', 'language' => 'en', 'cat' => 'general']);
        $queries = 0;
        QueryTap::on($this->db, function () use (&$queries): void { $queries++; });
        $this->repo->page($query, $this->memberId);
        QueryTap::off($this->db);
        $this->assertSame(1, $queries);
    }

    public function test_query_coerces_junk_to_defaults(): void
    {
        $q = BrowseQuery::fromQuery(['status' => ['x'], 'sort' => 'hax', 'language' => 'x', 'wmin' => 'abc', 'wmax' => '99999999999', 'cat' => [['a']], 'page' => '-3']);
        $this->assertSame(['any', 'updated', '', null, BrowseQuery::WORDS_CAP, '', 1], [$q->status, $q->sort, $q->language, $q->wmin, $q->wmax, $q->cat, $q->page]);
        $this->assertSame(1, BrowseQuery::fromQuery(['page' => '99999999999999999999999'])->page > 1 ? 1 : 0, 'a huge page stays an int');
    }

    /** The unary-plus rule: with a word range or a language, SQLite must still
     *  walk the sort's own index and never sort or scan stories. */
    public function test_every_sort_walks_its_own_index_with_no_sort_and_no_scan(): void
    {
        $sortIndex = ['updated' => 'idx_stories_recency', 'created' => 'idx_stories_created', 'words' => 'idx_stories_words', 'title' => 'idx_stories_title'];
        $shapes = [
            [],
            ['wmin' => '1000', 'wmax' => '90000'],
            ['language' => 'en'],
            ['tag' => [1, 2], 'xtag' => [3], 'status' => 'complete', 'cat' => 'general'],
            ['wmin' => '1000', 'language' => 'en', 'tag' => [1], 'xtag' => [3]],
        ];
        foreach ($sortIndex as $sort => $index) {
            foreach ($shapes as $shape) {
                [$sql, $binds] = $this->repo->statement(BrowseQuery::fromQuery($shape + ['sort' => $sort]), $this->memberId);
                $plan = implode("\n", array_column($this->db->all('EXPLAIN QUERY PLAN ' . $sql, $binds), 'detail'));
                $label = $sort . ' ' . json_encode($shape);
                $this->assertStringContainsString("SEARCH s USING INDEX {$index} (validated=?)", $plan, $label . "\n" . $plan);
                $this->assertStringNotContainsString('SCAN s', $plan, $label);
                $this->assertStringNotContainsString('USE TEMP B-TREE FOR ORDER BY', $plan, $label . "\n" . $plan);
            }
        }
    }
}
