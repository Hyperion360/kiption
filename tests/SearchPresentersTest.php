<?php
declare(strict_types=1);
namespace App\Tests;

use App\Search\{ActiveFilters, Passage};
use PHPUnit\Framework\TestCase;

final class SearchPresentersTest extends TestCase
{
    public function test_passage_marks_the_term_and_trims_around_it(): void
    {
        $text = str_repeat('Long opening words. ', 12) . 'The lighthouse keeper waited. ' . str_repeat('Trailing words here. ', 12);
        $p = Passage::around($text, 'lighthouse', 120);
        $this->assertNotNull($p);
        $this->assertSame('…', $p[0]['text']);
        $hits = array_values(array_filter($p, fn ($s) => $s['hit']));
        $this->assertSame('lighthouse', mb_strtolower($hits[0]['text']));
        $this->assertLessThan(200, mb_strlen(implode('', array_column($p, 'text'))));
    }

    public function test_passage_is_null_without_a_match_or_query(): void
    {
        $this->assertNull(Passage::around('Nothing relevant', 'zebra', 100));
        $this->assertNull(Passage::around('Anything', '', 100));
        $this->assertNull(Passage::around('Anything', '"', 100));
    }

    public function test_passage_keeps_markup_as_plain_text_segments(): void
    {
        $p = Passage::around('A <b>bold</b> claim', 'bold', 100);
        $this->assertSame('A <b>', $p[0]['text']);
        $this->assertTrue($p[1]['hit']);
    }

    public function test_chips_name_each_filter_and_drop_only_that_one(): void
    {
        $filters = ['category' => ['a', 'b'], 'rating_id' => 2, 'completed' => false, 'language' => 'en',
            'tag' => [5], 'xtag' => [6], 'tags' => '', 'xtags' => '', 'character' => null];
        $names = ['category' => ['a' => 'Alpha', 'b' => 'Beta'], 'rating' => [2 => 'Teen'], 'tag' => [5 => 'Fluff', 6 => 'Gore']];
        $chips = ActiveFilters::chips('river', $filters, 'relevance', $names);
        $labels = array_column($chips, 'label');
        $this->assertSame(['Alpha', 'Beta', 'Teen', 'In Progress', 'en', 'Fluff', 'Gore'], $labels);
        $this->assertSame('exclude', $chips[6]['kind']);
        parse_str(parse_url($chips[0]['remove'], PHP_URL_QUERY), $q);
        $this->assertSame(['b'], $q['category']);
        $this->assertSame('river', $q['q']);
        $this->assertSame([5], array_map('intval', $q['tag']));
        parse_str(parse_url($chips[5]['remove'], PHP_URL_QUERY), $q);
        $this->assertArrayNotHasKey('tag', $q);
        $this->assertSame([6], array_map('intval', $q['xtag']));
    }

    public function test_each_chip_has_a_type_and_variants_drop_one_at_a_time(): void
    {
        $filters = ['category' => ['a'], 'rating_id' => 2, 'completed' => false, 'language' => null,
            'tag' => [5], 'xtag' => [], 'tags' => '', 'xtags' => '', 'character' => null];
        $names = ['category' => ['a' => 'Alpha'], 'rating' => [2 => 'Mature'], 'tag' => [5 => 'Fantasy']];
        $chips = ActiveFilters::chips('lantern', $filters, 'relevance', $names);
        $this->assertSame(['category', 'rating', 'status', 'tag'], array_column($chips, 'type'));
        $variants = ActiveFilters::variants($filters);
        $this->assertCount(count($chips) + 1, $variants, 'one per chip, then everything dropped');
        $this->assertSame([], $variants[0]['category']);
        $this->assertSame(2, $variants[0]['rating_id']);
        $this->assertNull(end($variants)['rating_id']);
        $this->assertSame([], ActiveFilters::variants(['category' => [], 'rating_id' => null, 'completed' => null, 'language' => null, 'tag' => null, 'xtag' => null, 'tags' => '', 'xtags' => '', 'character' => null]));
        $this->assertStringContainsString('Mature', ActiveFilters::phrase($chips));
    }

    public function test_no_chips_when_nothing_is_filtered(): void
    {
        $f = ['category' => [], 'rating_id' => null, 'completed' => null, 'language' => null, 'tag' => null, 'xtag' => null, 'tags' => '', 'xtags' => '', 'character' => null];
        $this->assertSame([], ActiveFilters::chips('x', $f, 'relevance', []));
    }
}
