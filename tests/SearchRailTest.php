<?php
declare(strict_types=1);
namespace App\Tests;

use App\Tests\Support\{SearchArchive, TaxonomyCase};

final class SearchRailTest extends TaxonomyCase
{
    private SearchArchive $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = new SearchArchive($this->db, $this->authorId, (int) $this->db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id']);
        $this->a->clear();
    }

    private function body(array $get): string
    {
        return $this->client()->get('/search', $get)->body;
    }

    public function test_card_marks_the_term_in_the_summary_and_escapes_it(): void
    {
        $this->a->story('River One', ['summary' => 'A <i>quiet</i> river at dusk.']);
        $body = $this->body(['q' => 'river']);
        $this->assertStringContainsString('<mark>river</mark>', $body);
        $this->assertStringNotContainsString('<i>quiet', $body);
    }

    public function test_active_filters_show_as_chips_with_a_plain_link_to_drop_each(): void
    {
        $tag = $this->a->tag('Chip Tag');
        $this->a->story('River Chip', ['tags' => [$tag]]);
        $body = $this->body(['q' => 'river', 'xtag' => [$tag]]);
        $this->assertStringContainsString('Left Out:', $body);
        $this->assertStringContainsString('Remove Filter: Chip Tag', $body);
        $this->assertStringContainsString('href="/search?q=river"', $body);
    }

    public function test_no_match_names_each_filter_that_could_be_dropped(): void
    {
        $this->a->category('rail-cat');
        $this->a->story('River Plain');
        $body = $this->body(['q' => 'river', 'category' => ['rail-cat']]);
        $this->assertStringContainsString('Drop Rail-cat', $body);
        $this->assertStringContainsString('Try Dropping One Filter', $body);
    }

    public function test_bare_search_is_a_start_page_with_popular_tags_and_no_chips(): void
    {
        $tag = $this->a->tag('Start Tag');
        $this->a->story('Any Story', ['tags' => [$tag]]);
        $body = $this->body([]);
        $this->assertStringContainsString('Popular Tags', $body);
        $this->assertStringContainsString('Start Tag (1)', $body);
        $this->assertStringNotContainsString('data-search-chips', $body);
    }

    public function test_the_rail_works_without_scripts_as_one_get_form(): void
    {
        $body = $this->body([]);
        $this->assertMatchesRegularExpression('~<form[^>]*action="/search".*<aside id="search-rail".*</aside>.*</form>~s', $body);
        $this->assertStringContainsString('href="#search-rail"', $body);
    }

    public function test_status_is_a_segmented_radio_group_with_counts_under_each_label(): void
    {
        $this->a->story('River Done', ['completed' => 1]);
        $this->a->story('River Open A');
        $this->a->story('River Open B');
        $body = $this->body(['q' => 'river', 'completed' => '1']);
        $this->assertMatchesRegularExpression('~<input type="radio" name="completed" value=""[^>]*>\s*<span class="seg-label">Any</span>\s*<span class="count" data-search-count="status:any">3</span>~', $body);
        $this->assertMatchesRegularExpression('~<input type="radio" name="completed" value="1" checked[^>]*>\s*<span class="seg-label">Complete</span>\s*<span class="count" data-search-count="status:1">1</span>~', $body);
        $this->assertMatchesRegularExpression('~value="0"[^>]*>\s*<span class="seg-label">In Progress</span>\s*<span class="count" data-search-count="status:0">2</span>~', $body);
        $this->assertStringNotContainsString('<select name="completed"', $body);
    }

    public function test_rating_and_category_rows_carry_their_count_on_the_right(): void
    {
        $this->a->category('rail-cat');
        $this->a->story('River Rated', ['categories' => [$this->a->category('rail-cat')]]);
        $body = $this->body(['q' => 'river', 'category' => ['rail-cat']]);
        $this->assertMatchesRegularExpression('~<input type="radio" name="rating" value="\d+"[^>]*>\s*<span class="row-label">[^<]+</span>\s*<span class="count" data-search-count="rating:\d+">1</span>~', $body);
        $this->assertMatchesRegularExpression('~<input type="checkbox" name="category\[\]" value="rail-cat" checked>\s*<span class="row-label">Rail-cat</span>\s*<span class="count" data-search-count="category:rail-cat">1</span>~', $body);
    }

    public function test_categories_past_the_fifth_fold_into_a_show_more_disclosure(): void
    {
        $this->a->story('River Many');
        foreach (range(1, 8) as $n) $this->a->category('fold-' . $n);
        $body = $this->body(['q' => 'river']);
        $this->assertMatchesRegularExpression('~<details class="rail-more-rows">\s*<summary><span class="more-closed">Show \d+ More~', $body);
        $open = $this->body(['q' => 'river', 'category' => ['fold-8']]);
        $this->assertMatchesRegularExpression('~<details class="rail-more-rows" open>~', $open, 'a checked row never hides in the fold');
    }

    public function test_sort_is_a_pair_of_links_that_keep_the_other_filters(): void
    {
        $this->a->story('River Sorted', ['completed' => 1]);
        $body = $this->body(['q' => 'river', 'completed' => '1']);
        $this->assertMatchesRegularExpression('~<nav class="sort-toggle"[^>]*>\s*<a href="/search\?q=river&amp;completed=1" aria-current="true">Best Match</a>\s*<a href="/search\?q=river&amp;completed=1&amp;sort=updated">Recently Updated</a>~', $body);
        $updated = $this->body(['q' => 'river', 'sort' => 'updated']);
        $this->assertStringContainsString('<input type="hidden" name="sort" value="updated">', $updated);
        $this->assertMatchesRegularExpression('~href="/search\?q=river" ?>Best Match~', $updated);
        $this->assertMatchesRegularExpression('~sort=updated" aria-current="true">Recently Updated~', $updated);
        $this->assertStringNotContainsString('<select name="sort"', $body);
    }

    public function test_tags_offer_a_finder_hint_and_the_checkbox_pair_as_the_no_script_fallback(): void
    {
        $tag = $this->a->tag('Pick Me');
        $this->a->story('River Tagged', ['tags' => [$tag]]);
        $body = $this->body(['q' => 'river']);
        $this->assertStringContainsString('Click once to include, twice to leave out.', $body);
        $this->assertMatchesRegularExpression('~<p class="tag-find" hidden data-tag-find>.*?<input type="search"[^>]*placeholder="Find a Tag"~s', $body);
        $this->assertMatchesRegularExpression('~<div class="tag-fallback" data-tag-fallback>.*name="tag\[\]" value="' . $tag . '".*name="xtag\[\]" value="' . $tag . '"~s', $body);
        $this->assertStringContainsString('data-tag-chips', $body);
    }

    public function test_live_response_carries_the_status_any_count_and_the_total(): void
    {
        $this->a->story('River Live', ['completed' => 1]);
        $this->a->story('River Live Two');
        $r = $this->client()->get('/search', ['q' => 'river', 'live' => '1']);
        $data = json_decode($r->body, true);
        $this->assertSame(2, $data['counts']['status:any']);
        $this->assertSame(2, $data['total']);
    }
}
