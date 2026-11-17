<?php
namespace App\Tests;
use App\Repositories\{BrowseFilterRepository,BrowseQuery,SearchRepository};
use App\Tests\Support\{QueryTap,SearchArchive,TaxonomyCase};
final class DiscoveryCountsTest extends TaxonomyCase
{
    private SearchArchive $a;
    private array $ids=[];
    private SearchRepository $repo;
    protected function setUp(): void
    {
        parent::setUp();
        $rating=(int)$this->db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id'];
        $this->a=new SearchArchive($this->db,$this->authorId,$rating); $this->a->clear();
        $this->repo=new SearchRepository($this->db);
        foreach (['a','b'] as $v) $this->ids[$v]=$this->a->category('facet-'.$v);
        foreach (['Fantasy','Adventure','Gore'] as $v) $this->ids[$v]=$this->a->tag($v);
        $this->a->story('River One',['completed'=>1,'tags'=>[$this->ids['Fantasy'],$this->ids['Adventure']],'categories'=>[$this->ids['a']]]);
        $this->a->story('River Two',['tags'=>[$this->ids['Fantasy'],$this->ids['Gore']],'categories'=>[$this->ids['b']]]);
        $this->a->story('River Three',['completed'=>1,'language'=>'fr','tags'=>[$this->ids['Adventure']],'categories'=>[$this->ids['a'],$this->ids['b']]]);
        $this->a->story('River Four',['completed'=>1,'tags'=>[$this->ids['Gore']],'categories'=>[$this->ids['b']]]);
    }
    private function searchResult(array $f=[],int $limit=20,int $offset=0,int $me=0,int $viewer=0): array
    {
        $queries=0; QueryTap::on($this->db,static function()use(&$queries){$queries++;});
        try { $r=$this->repo->searchWithTaxonomies('river',$f,$limit,$offset,$me,$viewer); }
        finally { QueryTap::off($this->db); }
        $this->assertSame(1,$queries);
        return $r;
    }
    private function counts(array $r,string $kind,string $key,string $value='count'): array
    { return array_column($r[$kind],$value,$key); }
    public function test_category_or_and_disjunctive_counts_are_exact(): void
    {
        $r=$this->searchResult(['category'=>['facet-a']]);
        $this->assertSame(2,$r['total']);
        $c=$this->counts($r,'categories','slug');
        $this->assertSame(2,$c['facet-a']); $this->assertSame(3,$c['facet-b']);
        $this->assertSame([0=>0,1=>2],$r['counts']['status']);
        $this->assertSame(['en'=>1,'fr'=>1],$r['counts']['language']);
        $both=$this->searchResult(['category'=>['facet-a','facet-b']]);
        $this->assertSame(4,$both['total']);
        $this->assertCount(4,array_unique(array_column($both['rows'],'id')));
        $b=(new BrowseFilterRepository($this->db))->page(BrowseQuery::fromQuery(['cat'=>['facet-a','facet-b']]));
        $this->assertSame(4,$b['total']);
    }
    public function test_include_counts_and_exclusion_counts_have_distinct_semantics(): void
    {
        $r=$this->searchResult(['category'=>['facet-a','facet-b'],'tag'=>[$this->ids['Fantasy']],'xtag'=>[$this->ids['Gore']]]);
        $this->assertSame(1,$r['total']);
        $counts=$this->counts($r,'tags','id');
        $excluded=$this->counts($r,'tags','id','excludedCount');
        $this->assertSame(1,$counts[$this->ids['Fantasy']]);
        $this->assertSame(1,$counts[$this->ids['Adventure']]);
        $this->assertSame(0,$counts[$this->ids['Gore']]);
        $this->assertSame(1,$excluded[$this->ids['Gore']]);
        $this->assertSame(2,$excluded[$this->ids['Fantasy']]);
        $includeWins=$this->searchResult(['tag'=>[$this->ids['Fantasy']],'xtag'=>[$this->ids['Fantasy'],$this->ids['Gore']]]);
        $this->assertSame(1,$includeWins['total']);
        $zero=$this->searchResult(['tag'=>[$this->ids['Fantasy']],'xtag'=>[$this->ids['Adventure'],$this->ids['Gore']]]);
        $this->assertSame(0,$zero['total']);
        $this->assertSame(2,$this->counts($zero,'tags','id','excludedCount')[$this->ids['Fantasy']]);
        $this->assertSame(0,$this->counts($zero,'tags','id')[$this->ids['Fantasy']]);
    }
    public function test_named_tag_membership_preserves_aliases_overlap_and_distinct_counts(): void
    {
        $alias=$this->a->tag('Fantasy Alias','Search Fixture',$this->ids['Fantasy']);
        $this->a->story('River Alias',['tags'=>[$alias,$this->ids['Fantasy']],'categories'=>[$this->ids['a']]]);
        $r=$this->searchResult(['tags'=>'Fantasy Alias','xtags'=>'Gore']);
        $this->assertSame(2,$r['total']);
        $this->assertSame(2,$this->counts($r,'tags','id')[$this->ids['Fantasy']]);
        $this->assertSame(2,$this->searchResult(['tags'=>'Fantasy','xtags'=>'fantasy, Gore'])['total']);
        $this->assertSame(1,$this->searchResult(['tag'=>[$this->ids['Fantasy'],$this->ids['Adventure']]])['total']);
        $this->assertSame(0,$this->searchResult(['tags'=>'Unknown Tag'])['total']);
        $this->assertSame(5,$this->searchResult(['xtags'=>'Unknown Tag'])['total']);
        $this->assertSame(0,$this->searchResult(['tag'=>[999999]])['total']);
        $this->assertSame(5,$this->searchResult(['xtag'=>[999999]])['total']);
    }
    public function test_status_and_language_counts_omit_only_their_own_filter(): void
    {
        $r=$this->searchResult(['category'=>['facet-a','facet-b'],'completed'=>true,'language'=>'en']);
        $this->assertSame(2,$r['total']);
        $this->assertSame([0=>1,1=>2],$r['counts']['status']);
        $this->assertSame(['en'=>2,'fr'=>1],$r['counts']['language']);
        $this->assertSame(1,$this->searchResult(['completed'=>false])['total']);
        $this->assertSame(0,$this->searchResult(['category'=>['not-a-real-category']])['total']);
    }
    public function test_private_rows_do_not_change_results_or_counts(): void
    {
        $before=$this->searchResult();
        foreach ([['validated'=>0],['restricted'=>1],['deleted'=>'2026-01-02'],['locked'=>'hash']] as $o)
            $this->a->story('River Secret',$o+['tags'=>[$this->ids['Fantasy']],'categories'=>[$this->ids['a']]]);
        $after=$this->searchResult();
        $this->assertSame($before,$after);
        $this->a->story('River Muted',['author'=>$this->adminId,'tags'=>[$this->ids['Fantasy']],'categories'=>[$this->ids['a']]]);
        $this->db->query('INSERT INTO muted(user_id,author_id) VALUES (?,?)',[$this->memberId,$this->adminId]);
        $this->assertSame(4,$this->searchResult([],20,0,0,$this->memberId)['total']);
        $this->assertSame(6,$this->searchResult([],20,0,$this->memberId,0)['total']);
    }
    public function test_count_is_independent_of_page_and_selected_synonym(): void
    {
        for($i=0;$i<21;$i++) $this->a->story('River Page '.$i,['tags'=>[$this->ids['Fantasy']],'categories'=>[$this->ids['a']]]);
        $alias=$this->a->tag('Fantasy Alias','Search Fixture',$this->ids['Fantasy']);
        $one=$this->searchResult(['tag'=>[$alias]],20,0);
        $two=$this->searchResult(['tag'=>[$alias]],20,20);
        $this->assertSame(23,$one['total']); $this->assertSame(23,$two['total']);
        $this->assertTrue($one['hasMore']); $this->assertFalse($two['hasMore']);
        $this->assertCount(3,$two['rows']);
        $this->assertSame([$alias],array_column($one['selected'],'id'));
        $this->assertSame(23,$one['selected'][0]['count']);
    }
    public function test_queryless_search_returns_counts_without_listing_until_a_filter_is_set(): void
    {
        $queries = [];
        QueryTap::on($this->db, function (string $sql) use (&$queries): void { $queries[] = $sql; });
        try { $r = $this->repo->searchWithTaxonomies('', [], 20, 0, 0); }
        finally { QueryTap::off($this->db); }
        $this->assertCount(1, $queries);
        $this->assertSame('none', $r['mode']);
        $this->assertSame([], $r['rows']);
        $this->assertSame(4, $r['total']);
        $this->assertSame([0 => 1, 1 => 3], $r['counts']['status']);
        $filtered = $this->repo->searchWithTaxonomies('', ['completed' => false], 20, 0, 0);
        $this->assertSame('filter', $filtered['mode']);
        $this->assertSame(['River Two'], array_column($filtered['rows'], 'title'));
        $this->assertSame(1, $filtered['total']);
    }

    public function test_unfiltered_browse_counts_alias_once_and_omits_hidden_stories(): void
    {
        $alias = $this->a->tag('Fantasy Alias', 'Search Fixture', $this->ids['Fantasy']);
        $zero = $this->a->tag('Unused', 'Search Fixture');
        $this->a->story('River Alias', ['tags' => [$this->ids['Fantasy'], $alias]]);
        $this->a->story('River Hidden', ['validated' => 0, 'tags' => [$this->ids['Fantasy']]]);
        $r = (new BrowseFilterRepository($this->db))->page(BrowseQuery::fromQuery([]));
        $counts = $this->counts($r, 'tags', 'id');
        $this->assertSame(5, $r['total']);
        $this->assertSame(3, $counts[$this->ids['Fantasy']]);
        $this->assertSame(0, $counts[$zero]);
    }

    public function test_rating_omission_keeps_other_facets_and_zero_options(): void
    {
        $rating = (int) $this->db->one('SELECT id FROM ratings WHERE id<>? LIMIT 1', [$this->a->rating])['id'];
        $this->db->query("UPDATE stories SET rating_id=? WHERE title='River One'", [$rating]);
        $r = $this->searchResult(['rating_id' => $rating, 'language' => 'en']);
        $this->assertSame(1, $r['total']);
        $counts = $this->counts($r, 'ratings', 'id');
        $this->assertSame(1, $counts[$rating]);
        $this->assertSame(2, $counts[$this->a->rating]);
        $this->assertSame(['en' => 1, 'fr' => 0], $r['counts']['language']);
    }

    public function test_selected_tags_beyond_the_cap_keep_zero_counts_and_names_in_both_repositories(): void
    {
        for ($i = 0; $i < 105; $i++) $last = $this->a->tag(sprintf('Overflow %03d', $i), 'Overflow');
        $search = $this->searchResult(['tag' => [$last]]);
        $browse = (new BrowseFilterRepository($this->db))->page(BrowseQuery::fromQuery(['tag' => [$last]]));
        foreach ([$search, $browse] as $r) {
            $this->assertSame(0, $r['total']);
            $this->assertNotContains($last, array_column($r['tags'], 'id'));
            $this->assertSame([$last], array_column($r['selected'], 'id'));
            $this->assertSame('Overflow 104', $r['selected'][0]['name']);
            $this->assertSame(0, $r['selected'][0]['count']);
        }
    }

    public function test_browse_counts_include_the_twenty_first_match_on_an_empty_later_page(): void
    {
        $this->a->clear();
        for ($i = 0; $i < 21; $i++) $this->a->story('River Page ' . $i,
            ['tags' => [$this->ids['Fantasy']], 'categories' => [$this->ids['a']]]);
        $repo = new BrowseFilterRepository($this->db);
        $first = $repo->page(BrowseQuery::fromQuery([]));
        $empty = $repo->page(BrowseQuery::fromQuery(['page' => '3']));
        $this->assertCount(20, $first['rows']);
        $this->assertTrue($first['hasMore']);
        $this->assertSame([], $empty['rows']);
        $this->assertSame(21, $empty['total']);
        $this->assertSame(21, $this->counts($empty, 'tags', 'id')[$this->ids['Fantasy']]);
        $this->assertSame($first['counts'], $empty['counts']);
        $this->assertSame($first['categories'], $empty['categories']);
    }

    public function test_browse_and_like_counts_ignore_ineligible_stories_and_their_languages(): void
    {
        $browse = new BrowseFilterRepository($this->db);
        $q = BrowseQuery::fromQuery(['tag' => [$this->ids['Fantasy']]]);
        $before = $browse->page($q, $this->memberId);
        (new \ReflectionProperty(SearchRepository::class, 'fts'))->setValue($this->repo, false);
        $like = $this->searchResult(['tag' => [$this->ids['Fantasy']]]);
        $this->assertSame('like', $like['mode']);
        foreach ([['validated' => 0], ['restricted' => 1], ['deleted' => '2026-01-02'], ['locked' => 'hash']] as $options) {
            $this->a->story('River Secret', $options + ['language' => 'zz',
                'tags' => [$this->ids['Fantasy']], 'categories' => [$this->ids['a']]]);
        }
        $this->assertSame($before, $browse->page($q, $this->memberId));
        $this->assertSame($like, $this->searchResult(['tag' => [$this->ids['Fantasy']]]));
        $this->a->story('River Muted', ['author' => $this->adminId, 'language' => 'zz',
            'tags' => [$this->ids['Fantasy']], 'categories' => [$this->ids['a']]]);
        $this->db->query('INSERT INTO muted(user_id,author_id) VALUES (?,?)', [$this->memberId, $this->adminId]);
        $this->assertSame($before, $browse->page($q, $this->memberId));
        $this->assertSame($like, $this->searchResult(['tag' => [$this->ids['Fantasy']]], 20, 0, 0, $this->memberId));
    }

    public function test_bound_plan_seeks_spelling_and_reverse_facet_indexes(): void
    {
        $query = 'rivsr';
        [$spell, $binds] = \App\Search\SpellingSql::ctes(\App\Search\Spelling::probes($query), true);
        $match = SearchRepository::matchExpression($query);
        array_push($binds, $match, $match, 0);
        $source = (new \ReflectionMethod(SearchRepository::class, 'ftsSource'))->invoke($this->repo, '', $spell);
        [$sql, $binds] = \App\Repositories\DiscoveryFold::statement($source, $binds,
            ['category' => ['facet-a'], 'tag' => [$this->ids['Fantasy']]], 'relevance', 20, 0, true);
        $plan = implode("\n", array_column($this->db->all('EXPLAIN QUERY PLAN ' . $sql, $binds), 'detail'));
        foreach (['SEARCH w USING PRIMARY KEY', 'SEARCH d USING PRIMARY KEY', 'idx_search_word_posts_story',
            'idx_story_tags_rev', 'SEARCH sc USING COVERING INDEX sqlite_autoindex_story_categories_1 (story_id=? AND category_id=?)'] as $seek) $this->assertStringContainsString($seek, $plan);
        $this->assertDoesNotMatchRegularExpression('/SCAN (?:search_words|search_deletes|search_word_posts|stories|story_tags|story_categories)(?: |$)/m', $plan);
        $this->assertDoesNotMatchRegularExpression('/SCAN (?:x|sc|st)(?: |$)/m', $plan);
    }

    public function test_fold_rejects_unknown_order_before_building_sql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \App\Repositories\DiscoveryFold::statement('', [], [], 'arbitrary SQL', 20, 0, false);
    }

    public function test_page_size_bounds_keep_probe_and_partition_consistent(): void
    {
        $small = $this->searchResult([], 0, -5);
        $this->assertCount(1, $small['rows']);
        $this->assertTrue($small['hasMore']);
        for ($i = 0; $i < 100; $i++) $this->a->story('River Bound ' . $i);
        $large = $this->searchResult([], 200);
        $this->assertCount(100, $large['rows']);
        $this->assertTrue($large['hasMore']);
        $this->assertSame(104, $large['total']);
    }

    public function test_direct_search_wrappers_retain_the_author_profile_slug(): void
    {
        foreach (['searchFts', 'searchLike'] as $method) {
            $result = $this->repo->$method('river', [], 20, 0, 0);
            $this->assertSame('demo-author', $result['rows'][0]['profile_slug'] ?? null, $method);
        }
    }

}
