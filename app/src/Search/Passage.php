<?php
declare(strict_types=1);
namespace App\Search;

/** A short excerpt of a story summary with the searched terms picked out.
 *  Returns plain-text segments; the view escapes each one, so no markup
 *  from a story ever reaches the page unescaped. */
final class Passage
{
    /** @return list<array{text: string, hit: bool}>|null */
    public static function around(string $text, string $query, int $width = 160): ?array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $query, $m);
        $terms = array_values(array_unique(array_filter($m[0], static fn (string $t): bool => mb_strlen($t) >= 2)));
        if ($terms === [] || $text === '') return null;
        $alt = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $terms));
        if (!preg_match('/' . $alt . '/iu', $text, $first, PREG_OFFSET_CAPTURE)) return null;
        $pos = mb_strlen(substr($text, 0, $first[0][1]), 'UTF-8');
        $start = max(0, $pos - intdiv($width, 2));
        $slice = mb_substr($text, $start, $width, 'UTF-8');
        $parts = preg_split('/(' . $alt . ')/iu', $slice, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $part) {
            $out[] = ['text' => $part, 'hit' => preg_match('/^(?:' . $alt . ')$/iu', $part) === 1];
        }
        if ($start > 0) array_unshift($out, ['text' => '…', 'hit' => false]);
        if ($start + $width < mb_strlen($text, 'UTF-8')) $out[] = ['text' => '…', 'hit' => false];
        return $out;
    }
}
