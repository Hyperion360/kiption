<?php
namespace App\Tests;

use App\LineDiff;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[Small]
final class LineDiffTest extends TestCase
{
    public function test_blocks_split_on_blank_lines_and_drop_empty_ones(): void
    {
        $this->assertSame(['One', "Two\nstill two", 'Three'], LineDiff::blocks("One\r\n\r\nTwo\nstill two\n  \n\n\nThree\n"));
        $this->assertSame([], LineDiff::blocks("  \n\n"));
    }

    public function test_identical_texts_mark_nothing(): void
    {
        $this->assertSame([[false, false], [false, false]], LineDiff::compare(['a', 'b'], ['a', 'b']));
        $this->assertSame([[], []], LineDiff::compare([], []));
    }

    public function test_a_changed_middle_paragraph_is_marked_on_both_sides_only(): void
    {
        [$a, $b] = LineDiff::compare(['top', 'mine', 'end'], ['top', 'theirs', 'end']);
        $this->assertSame([false, true, false], $a);
        $this->assertSame([false, true, false], $b);
    }

    public function test_an_inserted_paragraph_marks_only_the_side_that_has_it(): void
    {
        [$a, $b] = LineDiff::compare(['one', 'two', 'three'], ['one', 'three']);
        $this->assertSame([false, true, false], $a);
        $this->assertSame([false, false], $b);
    }

    public function test_a_moved_paragraph_is_found_by_the_common_subsequence(): void
    {
        [$a, $b] = LineDiff::compare(['a', 'b', 'c', 'd'], ['a', 'c', 'b', 'd']);
        $this->assertSame(1, count(array_filter($a)));
        $this->assertSame(1, count(array_filter($b)));
    }

    public function test_one_side_empty_marks_everything_on_the_other(): void
    {
        $this->assertSame([[true, true], []], LineDiff::compare(['a', 'b'], []));
        $this->assertSame([[], [true]], LineDiff::compare([], ['a']));
    }

    public function test_a_huge_middle_is_marked_whole_instead_of_aligned(): void
    {
        $a = array_map(static fn (int $i): string => 'a' . $i, range(1, 2500));
        $b = array_map(static fn (int $i): string => 'b' . $i, range(1, 2500));
        $a[0] = $b[0] = 'same head';
        [$fa, $fb] = LineDiff::compare($a, $b);
        $this->assertFalse($fa[0]);
        $this->assertSame(2499, count(array_filter($fa)));
        $this->assertSame(2499, count(array_filter($fb)));
    }
}
