<?php
declare(strict_types=1);
namespace App\Tests;

use App\Repositories\SearchRepository;
use App\Search\{Spelling, SpellingSql};
use App\Tests\Support\{SearchArchive, TaxonomyCase, QueryTap};

final class SearchSpellingIntegrationTest extends TaxonomyCase
{
    private SearchArchive $a;
    private SearchRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $rating = (int) $this->db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id'];
        $this->a = new SearchArchive($this->db, $this->authorId, $rating);
        $this->a->clear();
        $this->repo = new SearchRepository($this->db);
    }

    public function test_each_edit_class_reaches_the_same_title(): void
    {
        $this->a->story('Wonderland at Midnight');
        foreach (['wonderlnd', 'wonderlaand', 'wonderlend', 'wondreland', 'wonderlend midnigth'] as $query) {
            $rows = $this->repo->search($query, [], 20, 0, 0)['rows'];
            $this->assertSame(['Wonderland at Midnight'], array_column($rows, 'title'), $query);
        }
        $this->assertSame([], $this->repo->search('winter cartographer', [], 20, 0, 0)['rows']);
    }

    public function test_exact_before_fuzzy_and_stable_pages(): void
    {
        $this->a->story('Wonderland at Midnight');
        $this->a->story('Wonderlend Notebook');
        $one = $this->repo->search('wonderlend', [], 1, 0, 0);
        $two = $this->repo->search('wonderlend', [], 1, 1, 0);
        $this->assertSame('Wonderlend Notebook', $one['rows'][0]['title'] ?? null);
        $this->assertSame('Wonderland at Midnight', $two['rows'][0]['title'] ?? null);
        $this->assertTrue($one['hasMore']);
        $this->assertFalse($two['hasMore']);
    }

    public function test_cost_and_field_are_taken_from_the_same_word_hit(): void
    {
        $tag = $this->a->tag('Wonderland');
        $mixed = $this->a->story('Wonderlend', ['tags' => [$tag]]);
        $title = $this->a->story('Wonderland');
        [$cte, $binds] = SpellingSql::ctes(Spelling::probes('wonderland'));
        $rows = $this->db->all('WITH ' . $cte . ' SELECT story_id,distance,field_rank FROM spell_matches ORDER BY story_id', $binds);
        $map = array_column($rows, null, 'story_id');
        $this->assertSame(0, (int) $map[$mixed]['distance']);
        $this->assertSame(1, (int) $map[$mixed]['field_rank']);
        $this->assertSame(0, (int) $map[$title]['field_rank']);
    }

    public function test_exact_story_exclusion_preserves_other_stories_token_scores(): void
    {
        $exact = $this->a->story('Wonderlend Midnigth');
        $fuzzy = $this->a->story('Wonderland Midnight');
        [$cte, $binds] = SpellingSql::ctes(Spelling::probes('wonderlend midnigth'), true);
        $rows = $this->db->all('WITH fts_matches(story_id) AS (SELECT ' . (int) $exact . ' UNION ALL SELECT NULL),' . $cte
            . ' SELECT story_id,distance,field_rank FROM spell_matches', $binds);
        $this->assertSame([['story_id' => $fuzzy, 'distance' => 2, 'field_rank' => 0]], $rows);
    }

    public function test_trigger_rename_rollback_shared_words_and_delete(): void
    {
        $first = $this->a->story('Wonderland First');
        $second = $this->a->story('Wonderland Second');
        $this->db->begin();
        $this->db->query('UPDATE stories SET title=? WHERE id=?', ['Winter First', $first]);
        $this->assertCount(1, $this->repo->search('wonderlend', [], 20, 0, 0)['rows']);
        $this->db->rollBack();
        $this->assertCount(2, $this->repo->search('wonderlend', [], 20, 0, 0)['rows']);
        $this->db->query('DELETE FROM stories WHERE id=?', [$first]);
        $this->assertCount(1, $this->repo->search('wonderlend', [], 20, 0, 0)['rows']);
        $this->db->query('DELETE FROM stories WHERE id=?', [$second]);
        $this->assertSame([], $this->repo->search('wonderlend', [], 20, 0, 0)['rows']);
        $this->assertNull($this->db->one('SELECT word FROM search_words WHERE word=?', ['wonderland']));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM search_word_source')['n']);
    }

    public function test_missing_optional_index_retries_exact(): void
    {
        $this->a->story('Wonderland First');
        $this->db->query('ALTER TABLE search_word_posts RENAME TO absent_posts');
        $this->assertCount(1, $this->repo->search('wonderland', [], 20, 0, 0)['rows']);
        $this->assertSame([], $this->repo->search('wonderlend', [], 20, 0, 0)['rows']);
    }

    public function test_broken_columns_are_not_misreported_as_missing_optional_index(): void
    {
        $this->a->story('Wonderland First');
        $this->db->query('ALTER TABLE search_words RENAME COLUMN word TO renamed_word');
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('no such column');
        $this->repo->search('wonderlend', [], 20, 0, 0);
    }

    public function test_prefix_plus_typo_requires_every_token_and_short_words_do_not_fuzz(): void
    {
        $this->a->story('Wonderland at Midnight');
        $this->a->story('Cot');
        $this->assertSame(['Wonderland at Midnight'], array_column($this->repo->search('wonderlend mi', [], 20, 0, 0)['rows'], 'title'));
        $this->assertSame([], $this->repo->search('wonderlend zzqv', [], 20, 0, 0)['rows']);
        $this->assertSame([], $this->repo->search('cat', [], 20, 0, 0)['rows']);
        $this->assertSame([], $this->repo->search('wzzderland', [], 20, 0, 0)['rows']);
    }

    public function test_punctuation_after_a_single_spelling_token_keeps_prefix_candidates(): void
    {
        $this->a->story('Lexicon');
        foreach (['lex !!!', 'lex ...', 'lex 🦊'] as $query) {
            $this->assertSame(['Lexicon'], array_column($this->repo->search($query, [], 20, 0, 0)['rows'], 'title'), $query);
        }
    }

    public function test_fuzzy_title_precedes_tag_and_equal_scores_use_story_id(): void
    {
        $tag = $this->a->tag('Wonderland');
        $tagStory = $this->a->story('Quiet Waters', ['tags' => [$tag]]);
        $first = $this->a->story('Wonderland');
        $second = $this->a->story('Wonderland');
        $rows = $this->repo->searchFts('wonderlend', [], 20, 0, 0)['rows'];
        $this->assertSame([$first, $second, $tagStory], array_column($rows, 'id'));
    }

    public function test_updated_sort_overrides_exact_precedence_without_changing_page_order(): void
    {
        $this->a->story('Wonderlend', ['updated' => '2026-01-01']);
        $this->a->story('Wonderland', ['updated' => '2026-02-01']);
        foreach (['searchWithTaxonomies', 'searchFts'] as $method) {
            $first = $this->repo->$method('wonderlend', [], 1, 0, 0, 0, 'updated');
            $second = $this->repo->$method('wonderlend', [], 1, 1, 0, 0, 'updated');
            $this->assertSame(['Wonderland'], array_column($first['rows'], 'title'));
            $this->assertSame(['Wonderlend'], array_column($second['rows'], 'title'));
            $this->assertTrue($first['hasMore']);
            $this->assertFalse($second['hasMore']);
        }
    }

    public function test_typo_visibility_and_mute_gates_match_exact_search(): void
    {
        $visible = $this->a->story('Wonderland');
        $restricted = $this->a->story('Wonderland', ['restricted' => 1]);
        foreach ([['validated' => 0], ['deleted' => '2026-01-02'], ['locked' => 'private-hash']] as $options) {
            $this->a->story(str_repeat('Wonderland ', 20), $options);
        }
        $this->assertSame([$visible], array_column($this->repo->searchFts('wonderlend', [], 20, 0, 0)['rows'], 'id'));
        $this->assertSame([$visible, $restricted], array_column($this->repo->searchFts('wonderlend', [], 20, 0, $this->memberId)['rows'], 'id'));
        $this->db->query('INSERT INTO muted(user_id, author_id) VALUES (?, ?)', [$this->memberId, $this->authorId]);
        $this->assertSame([], $this->repo->search('wonderlend', [], 20, 0, $this->memberId, $this->memberId)['rows']);
        $exact = $this->a->story('Wonderlend Visible', ['author' => $this->adminId]);
        $this->assertSame([$exact], array_column($this->repo->searchFts('wonderlend', [], 20, 0, 0, $this->memberId)['rows'], 'id'));
    }

    public function test_private_chapter_cannot_promote_a_fuzzy_title_to_an_exact_hit(): void
    {
        $story = $this->a->story('Wonderland');
        $this->db->query('INSERT INTO chapters(story_id, position, title, content, validated) VALUES (?, 1, ?, ?, 0)',
            [$story, 'Wonderlend', 'A chapter waiting for moderation.']);
        $chapter = (int) $this->db->lastInsertId();
        $row = $this->repo->searchFts('wonderlend', [], 20, 0, 0)['rows'][0];
        $this->assertSame(1, $row['tier']);
        $this->assertSame(1, $row['distance']);
        $this->db->query('UPDATE chapters SET validated=1, access_hash=? WHERE id=?', ['private-hash', $chapter]);
        $this->assertSame(1, $this->repo->searchFts('wonderlend', [], 20, 0, 0)['rows'][0]['tier']);
        $this->db->query('UPDATE chapters SET access_hash=NULL WHERE id=?', [$chapter]);
        $this->assertSame(0, $this->repo->searchFts('wonderlend', [], 20, 0, 0)['rows'][0]['tier']);
    }

    public function test_typo_filters_retain_category_or_and_tag_exclusions(): void
    {
        $one = $this->a->category('spelling-a'); $two = $this->a->category('spelling-b');
        $include = $this->a->tag('Adventure'); $exclude = $this->a->tag('Darkness');
        $this->a->story('Wonderland One', ['categories' => [$one], 'tags' => [$include], 'completed' => 1]);
        $this->a->story('Wonderland Two', ['categories' => [$two], 'tags' => [$include, $exclude], 'completed' => 1]);
        $this->a->story('Wonderland Three', ['categories' => [$two], 'tags' => [$include], 'language' => 'fr']);
        $filters = ['category' => ['spelling-a', 'spelling-b'], 'tag' => [$include], 'xtag' => [$exclude], 'completed' => true, 'language' => 'en', 'rating_id' => $this->a->rating];
        foreach (['searchWithTaxonomies', 'searchFts'] as $method) {
            $this->assertSame(['Wonderland One'], array_column($this->repo->$method('wonderlend', $filters, 20, 0, 0)['rows'], 'title'));
        }
    }

    public function test_normal_spelling_query_is_one_statement_without_an_outer_window_sort(): void
    {
        $this->a->story('Wonderland');
        $sql = []; QueryTap::on($this->db, function (string $statement) use (&$sql) { $sql[] = $statement; });
        try { $rows = $this->repo->searchWithTaxonomies('wonderlend', [], 20, 0, 0)['rows']; }
        finally { QueryTap::off($this->db); }
        $this->assertCount(1, $rows); $this->assertCount(1, $sql);
        $this->assertStringNotContainsString('ROW_NUMBER()', $sql[0]);
        $this->assertStringContainsString('CROSS JOIN stories s', $sql[0]);
        $this->assertStringNotContainsString('stories_trigram', $sql[0]);
    }

    public function test_missing_fts_preserves_the_like_fallback(): void
    {
        $this->a->story('Wonderland');
        $this->db->query('DROP TABLE stories_fts');
        foreach (['searchWithTaxonomies', 'searchFts'] as $method) {
            $repo = new SearchRepository($this->db);
            $result = $repo->$method('wonderland', [], 20, 0, 0);
            $this->assertSame('like', $result['mode']);
            $this->assertSame(['Wonderland'], array_column($result['rows'], 'title'));
        }
    }

    public function test_each_missing_spelling_relation_retries_exact_and_memoizes_without_probes(): void
    {
        $this->a->story('Wonderland');
        foreach (['search_words', 'search_deletes', 'search_word_posts'] as $table) {
            $this->db->query('ALTER TABLE ' . $table . ' RENAME TO absent_relation');
            foreach (['searchWithTaxonomies', 'searchFts'] as $method) {
                $repo = new SearchRepository($this->db);
                $this->assertSame(['Wonderland'], array_column($repo->$method('wonderland', [], 20, 0, 0)['rows'], 'title'));
                $queries = 0; QueryTap::on($this->db, function () use (&$queries) { $queries++; });
                try { $this->assertSame([], $repo->$method('wonderlend', [], 20, 0, 0)['rows']); }
                finally { QueryTap::off($this->db); }
                $this->assertSame(1, $queries, $table . ' ' . $method);
            }
            $this->db->query('ALTER TABLE absent_relation RENAME TO ' . $table);
        }
    }

    public function test_prefix_length_uses_utf8_even_under_another_process_encoding(): void
    {
        $this->a->story('Éclair');
        $encoding = mb_internal_encoding();
        try {
            mb_internal_encoding('ISO-8859-1');
            foreach (['é' => [], 'éc' => ['Éclair']] as $query => $titles) {
                [$cte, $binds] = SpellingSql::ctes(Spelling::probes($query));
                $rows = $this->db->all('WITH ' . $cte . ' SELECT s.title FROM spell_matches m JOIN stories s ON s.id=m.story_id', $binds);
                $this->assertSame($titles, array_column($rows, 'title'));
            }
            $this->assertSame('"é"', SearchRepository::matchExpression('é'));
        } finally { mb_internal_encoding($encoding); }
        $this->assertSame(['', []], SpellingSql::ctes(Spelling::probes('!?')));
    }

}
