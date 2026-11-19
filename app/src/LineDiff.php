<?php
namespace App;

/** Which paragraphs of two markdown texts differ, computed from the two strings
 *  already in hand (no query). Paragraphs are the lines of the archive: blocks
 *  separated by blank lines. A longest-common-subsequence pass marks the rest. */
final class LineDiff
{
    /** Past this many table cells the middle is marked whole instead of aligned. */
    private const MAX_CELLS = 4000000;

    /** @return string[] the non-empty blocks of a markdown text, trimmed */
    public static function blocks(string $md): array
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $out = [];
        foreach (preg_split('/\n[ \t]*\n+/', $md) ?: [] as $block) {
            $block = trim($block, "\n");
            if (trim($block) !== '') $out[] = $block;
        }
        return $out;
    }

    /** @param string[] $a @param string[] $b
     *  @return array{0: bool[], 1: bool[]} per block of $a and of $b, true when it has no match on the other side */
    public static function compare(array $a, array $b): array
    {
        $a = array_values($a); $b = array_values($b);
        $n = count($a); $m = count($b);
        $flagsA = array_fill(0, $n, true); $flagsB = array_fill(0, $m, true);
        $head = 0;
        while ($head < $n && $head < $m && $a[$head] === $b[$head]) { $flagsA[$head] = $flagsB[$head] = false; $head++; }
        $tailA = $n; $tailB = $m;
        while ($tailA > $head && $tailB > $head && $a[$tailA - 1] === $b[$tailB - 1]) { $tailA--; $tailB--; $flagsA[$tailA] = $flagsB[$tailB] = false; }
        $rows = $tailA - $head; $cols = $tailB - $head;
        if ($rows === 0 || $cols === 0 || $rows * $cols > self::MAX_CELLS) return [$flagsA, $flagsB];
        // lcs[i][j]: common length of the middle of $a from i and of $b from j.
        $lcs = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));
        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $cols - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$head + $i] === $b[$head + $j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        for ($i = 0, $j = 0; $i < $rows && $j < $cols;) {
            if ($a[$head + $i] === $b[$head + $j]) { $flagsA[$head + $i] = $flagsB[$head + $j] = false; $i++; $j++; }
            elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) $i++;
            else $j++;
        }
        return [$flagsA, $flagsB];
    }
}
