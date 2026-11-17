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
        $this->assertStringContainsString('Try dropping one filter', $body);
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
}
