<?php
declare(strict_types=1);
namespace App\Tests;

use App\Repositories\BrowseQuery;
use PHPUnit\Framework\TestCase;

final class BrowseQueryTest extends TestCase
{
    public function test_multiple_categories_are_parsed_and_carried_without_duplicates(): void
    {
        $query = BrowseQuery::fromQuery(['cat' => ['general', 'fantasy', 'general', ['bad']]]);
        self::assertSame(['general', 'fantasy'], $query->categories ?? []);
        self::assertSame('general', $query->cat);
        self::assertTrue($query->filtered());
        self::assertSame(['cat' => ['general', 'fantasy']], $query->carry());
    }

    public function test_legacy_scalar_category_has_the_same_normalized_state(): void
    {
        $scalar = BrowseQuery::fromQuery(['cat' => 'general']);
        $array = BrowseQuery::fromQuery(['cat' => ['general']]);
        self::assertEquals($scalar, $array);
        self::assertSame(['general'], $scalar->carry()['cat'] ?? null);
    }

    public function test_pager_query_round_trip_retains_all_filters_and_sort(): void
    {
        $query = BrowseQuery::fromQuery([
            'cat' => ['general', 'fantasy'], 'xtags' => 'Gore,Unhappy Ending',
            'tags' => 'Adventure', 'tag' => ['2'], 'xtag' => ['3'], 'status' => 'wip',
            'language' => 'en', 'wmin' => '100', 'wmax' => '2000', 'sort' => 'title', 'page' => '3',
        ]);
        $carry = $query->carry();
        self::assertSame('Gore,Unhappy Ending', $carry['xtags'] ?? null);
        self::assertSame(['general', 'fantasy'], $carry['cat'] ?? null);
        self::assertArrayNotHasKey('page', $carry);
        parse_str(http_build_query($carry + ['page' => '4']), $roundTrip);
        $next = BrowseQuery::fromQuery($roundTrip);
        self::assertSame($carry, $next->carry());
        self::assertSame(4, $next->page);
        self::assertSame(60, $next->offset());
    }

    public function test_default_or_invalid_categories_do_not_turn_on_filtering(): void
    {
        foreach ([[], ['cat' => [['bad'], '<x>']], ['sort' => 'title', 'page' => '2']] as $raw) {
            $query = BrowseQuery::fromQuery($raw);
            self::assertSame([], $query->categories ?? []);
            self::assertFalse($query->filtered());
            self::assertArrayNotHasKey('cat', $query->carry());
        }
    }
}
