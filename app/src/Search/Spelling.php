<?php
declare(strict_types=1);
namespace App\Search;

/** Bounded query probes for exact and single-edit word lookups. */
final class Spelling
{
    /**
     * Rows retain the zero-based query ordinal and edit cost. Substitution
     * probes also retain the one-based deleted position to exclude two edits.
     *
     * @return array{
     *     direct: list<array{int, string, int}>,
     *     substitutions: list<array{int, string, int, int}>,
     *     insertions: list<array{int, string}>,
     *     tokens: list<string>,
     *     count: int
     * }
     */
    public static function probes(string $query): array
    {
        $out = ['direct' => [], 'substitutions' => [], 'insertions' => [], 'tokens' => [], 'count' => 0];
        if (!mb_check_encoding($query, 'UTF-8')) return $out;
        preg_match_all('/[\p{L}\p{N}\p{Co}]+/u', mb_strtolower(mb_substr($query, 0, 200, 'UTF-8'), 'UTF-8'), $found);
        $tokens = array_slice($found[0], 0, 8);
        $out['tokens'] = $tokens;
        $out['count'] = count($tokens);
        foreach ($tokens as $i => $word) {
            $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
            $n = count($chars);
            $out['direct'][] = [$i, $word, 0];
            if ($n < 4 || $n > 64) continue;
            $out['insertions'][] = [$i, $word];
            for ($j = 0; $j < $n; $j++) {
                $deleted = $chars;
                array_splice($deleted, $j, 1);
                $key = implode('', $deleted);
                $out['direct'][] = [$i, $key, 1];
                $out['substitutions'][] = [$i, $key, $j + 1, 1];
                if ($j + 1 < $n && $chars[$j] !== $chars[$j + 1]) {
                    $swapped = $chars;
                    [$swapped[$j], $swapped[$j + 1]] = [$swapped[$j + 1], $swapped[$j]];
                    $out['direct'][] = [$i, implode('', $swapped), 1];
                }
            }
        }
        foreach (['direct', 'substitutions', 'insertions'] as $kind) {
            $unique = [];
            foreach ($out[$kind] as $row) {
                // Normalized tokens contain no NUL, so numeric-looking words stay distinct.
                $unique[implode("\0", $row)] = $row;
            }
            $out[$kind] = array_values($unique);
        }
        return $out;
    }
}
