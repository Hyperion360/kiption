<?php // app/Features/Reader/Roman.php
namespace App\Features\Reader;

/** Roman numerals for chapter lists: the comp renders I, II, III rows. */
final class Roman
{
    /** @var list<array{0: int, 1: string}> value/numeral pairs, descending so
     *  the greedy walk emits the subtractive pairs (IV, IX, XL, XC, C...) */
    private const MAP = [
        [1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'],
        [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I'],
    ];

    /** The chapter position in Roman numerals. Positions are >= 1 by
     *  construction (read() clamps, the TOC blob orders validated rows), so
     *  the arabic fallback below only ever fires on corrupt data: visible,
     *  never fatal. */
    public static function numeral(int $n): string
    {
        if ($n < 1) { return (string) $n; }
        $out = '';
        foreach (self::MAP as [$value, $glyph]) {
            while ($n >= $value) {
                $out .= $glyph;
                $n -= $value;
            }
        }
        return $out;
    }
}
