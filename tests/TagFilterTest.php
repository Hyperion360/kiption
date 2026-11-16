<?php // tests/TagFilterTest.php
namespace App\Tests;
use App\Repositories\TagFilter;
use PHPUnit\Framework\TestCase;

final class TagFilterTest extends TestCase
{
    public function test_parses_scalar_and_array_forms_and_drops_junk(): void
    {
        $f = TagFilter::fromQuery(['tag' => '5', 'xtag' => ['7', 'abc', '-3', '0', ['x']]]);
        $this->assertSame([5], $f->include);
        $this->assertSame([7], $f->exclude);
        $f = TagFilter::fromQuery(['tag' => [3, '3', 4]]);
        $this->assertSame([3, 4], $f->include, 'deduplicated, order kept');
    }

    public function test_caps_every_list(): void
    {
        $f = TagFilter::fromQuery(['tag' => range(1, 40), 'xtag' => range(1, 40)]);
        $this->assertCount(TagFilter::MAX, $f->include);
        $this->assertCount(TagFilter::MAX, $f->exclude);
    }

    public function test_an_id_cannot_be_both_included_and_excluded(): void
    {
        $f = TagFilter::fromQuery(['tag' => [1, 2], 'xtag' => [2, 3]]);
        $this->assertSame([1, 2], $f->include);
        $this->assertSame([3], $f->exclude, 'include wins');
    }

    public function test_names_are_trimmed_capped_and_length_limited(): void
    {
        $f = TagFilter::fromQuery(['tags' => " slow burn , ,enemies to lovers," . str_repeat('x', 500)]);
        $this->assertSame(['slow burn', 'enemies to lovers'], array_slice($f->names, 0, 2));
        $this->assertLessThanOrEqual(64, strlen($f->names[2] ?? ''));
    }

    public function test_sql_is_bound_never_concatenated(): void
    {
        $f = TagFilter::fromQuery(['tag' => [5], 'xtag' => [9], 'tags' => "x') OR 1=1 --"]);
        $binds = [];
        $sql = $f->sql($binds, 's');
        $this->assertStringNotContainsString('OR 1=1', $sql);
        $this->assertSame([5, 9, "x') OR 1=1 --"], $binds);
        $this->assertSame(3, substr_count($sql, '?'));
    }

    public function test_empty_filter_emits_nothing(): void
    {
        $b = [];
        $this->assertSame('', TagFilter::fromQuery([])->sql($b, 's'));
        $this->assertSame([], $b);
    }

    public function test_xtags_names_are_parsed_and_emit_not_exists_with_a_bound_name(): void
    {
        $f = TagFilter::fromQuery(['xtags' => 'gore, angst']);
        $this->assertSame(['gore', 'angst'], $f->excludeNames);
        $b = [];
        $sql = $f->sql($b, 's');
        $this->assertSame(['gore', 'angst'], $b);
        $this->assertSame(2, substr_count($sql, 'NOT EXISTS'));
        $this->assertFalse($f->isEmpty());
    }

    public function test_a_name_cannot_be_both_included_and_left_out(): void
    {
        $f = TagFilter::fromQuery(['tags' => 'Angst', 'xtags' => 'angst, gore']);
        $this->assertSame(['gore'], $f->excludeNames, 'case-insensitive; include wins');
    }
}
