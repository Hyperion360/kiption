<?php
declare(strict_types=1);
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;

use App\Repositories\CategoryFilter;
use PHPUnit\Framework\TestCase;

#[Small]
final class CategoryFilterTest extends TestCase
{
    public function test_scalar_and_array_slugs_keep_their_first_occurrence(): void
    {
        self::assertSame(['general'], CategoryFilter::slugs('general'));
        self::assertSame(['general', 'fantasy'], CategoryFilter::slugs(['general', 'fantasy', 'general', ['bad'], '<x>']));
        self::assertSame(['0'], CategoryFilter::slugs('0'));
    }

    public function test_invalid_values_and_oversized_slugs_are_ignored(): void
    {
        foreach ([null, false, 2, new \stdClass(), '', 'Two Words', "general\n", str_repeat('a', 101)] as $raw) {
            self::assertSame([], CategoryFilter::slugs($raw));
        }
        self::assertSame([str_repeat('a', 100)], CategoryFilter::slugs(str_repeat('a', 100)));
    }

    public function test_duplicates_and_invalid_values_do_not_use_the_eight_category_budget(): void
    {
        $wanted = array_map(fn (int $n): string => 'c-' . $n, range(1, 8));
        $raw = ['bad value', ['c-1'], 'c-1', 'c-1', ...array_map(fn (int $n): string => 'c-' . $n, range(2, 20))];
        self::assertSame($wanted, CategoryFilter::slugs($raw));
    }

    public function test_category_sql_uses_parameters_and_appends_them_in_order(): void
    {
        $binds = ['already-bound'];
        $sql = CategoryFilter::sql(['general', 'fantasy'], $binds);
        self::assertSame(['already-bound', 'general', 'fantasy'], $binds);
        self::assertStringContainsString('cc.slug IN (?, ?)', $sql);
        self::assertStringContainsString('sc.story_id = s.id', $sql);
        self::assertStringNotContainsString('general', $sql);
    }

    public function test_empty_categories_add_no_constraint_or_parameters(): void
    {
        $binds = ['already-bound'];
        self::assertSame('', CategoryFilter::sql([], $binds));
        self::assertSame(['already-bound'], $binds);
    }
}
