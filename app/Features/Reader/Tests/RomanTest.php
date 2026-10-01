<?php // app/Features/Reader/Tests/RomanTest.php
namespace App\Features\Reader\Tests;
use App\Features\Reader\Roman;
use PHPUnit\Framework\TestCase;

// C7: chapter lists render Roman numerals (the comp's I, II, III rows), so
// the conversion is a named helper with its own pins, including the
// subtractive pairs a hand-rolled loop gets wrong.
final class RomanTest extends TestCase
{
    /** @return list<array{0: int, 1: string}> */
    public static function numerals(): array
    {
        return [
            [1, 'I'], [2, 'II'], [3, 'III'], [4, 'IV'], [5, 'V'], [9, 'IX'],
            [12, 'XII'], [14, 'XIV'], [40, 'XL'], [49, 'XLIX'], [50, 'L'],
            [90, 'XC'], [100, 'C'], [388, 'CCCLXXXVIII'],
        ];
    }

    /** @dataProvider numerals */
    public function test_numeral(int $n, string $expected): void
    {
        $this->assertSame($expected, Roman::numeral($n));
    }

    public function test_non_positive_positions_fall_back_to_arabic(): void
    {
        // Positions are >= 1 by construction (read() clamps, the blob orders
        // validated rows); the fallback keeps a bad row visible, not fatal.
        $this->assertSame('0', Roman::numeral(0));
        $this->assertSame('-1', Roman::numeral(-1));
    }
}
