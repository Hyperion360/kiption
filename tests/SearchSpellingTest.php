<?php
declare(strict_types=1);
namespace App\Tests;

use App\Search\Spelling;
use PHPUnit\Framework\TestCase;

final class SearchSpellingTest extends TestCase
{
    public function test_probes_cover_all_single_edit_classes(): void
    {
        $p = Spelling::probes('wonderlend riverss wondreland wnderland');
        self::assertContains([0, 'wonderlnd', 8, 1], $p['substitutions']);
        self::assertContains([1, 'rivers', 1], $p['direct']);
        self::assertContains([2, 'wonderland', 1], $p['direct']);
        self::assertContains([3, 'wnderland'], $p['insertions']);
        self::assertSame(4, $p['count']);
    }

    public function test_deletions_substitutions_and_swaps_include_both_word_boundaries(): void
    {
        $p = Spelling::probes('planet');
        foreach (['lanet', 'plane', 'lpanet', 'plante'] as $word) {
            self::assertContains([0, $word, 1], $p['direct']);
        }
        self::assertContains([0, 'lanet', 1, 1], $p['substitutions']);
        self::assertContains([0, 'plane', 6, 1], $p['substitutions']);
        self::assertNotContains([0, 'tlanep', 1], $p['direct']);
        self::assertNotContains([0, 'plane', 5, 1], $p['substitutions']);
    }

    public function test_missing_first_or_last_letter_keeps_the_query_for_indexed_deletion_lookup(): void
    {
        self::assertSame([[0, 'lanet'], [1, 'plane']], Spelling::probes('lanet plane')['insertions']);
    }

    public function test_exact_candidate_precedes_single_edit_candidates(): void
    {
        $p = Spelling::probes('PLANET');
        self::assertSame([0, 'planet', 0], $p['direct'][0]);
        foreach (array_slice($p['direct'], 1) as $row) {
            self::assertSame(1, $row[2]);
            self::assertNotSame('planet', $row[1]);
        }
    }

    public function test_duplicate_deletions_retain_each_substitution_position(): void
    {
        $p = Spelling::probes('boook');
        $direct = array_values(array_filter($p['direct'], fn (array $row): bool => $row[1] === 'book'));
        self::assertSame([[0, 'book', 1]], $direct);
        $substitutions = array_values(array_filter($p['substitutions'], fn (array $row): bool => $row[1] === 'book'));
        self::assertSame([[0, 'book', 2, 1], [0, 'book', 3, 1], [0, 'book', 4, 1]], $substitutions);
    }

    public function test_equal_adjacent_letters_do_not_generate_an_edited_exact_match(): void
    {
        $p = Spelling::probes('aaaa');
        self::assertSame([[0, 'aaaa', 0], [0, 'aaa', 1]], $p['direct']);
        self::assertSame([[0, 'aaa', 1, 1], [0, 'aaa', 2, 1], [0, 'aaa', 3, 1], [0, 'aaa', 4, 1]], $p['substitutions']);
        self::assertSame([[0, 'aaaa']], $p['insertions']);
    }

    public function test_numeric_looking_words_remain_distinct_probe_keys(): void
    {
        $p = Spelling::probes('0e12');
        self::assertContains([0, '0e2', 1], $p['direct']);
        self::assertContains([0, '0e1', 1], $p['direct']);
        self::assertContains([0, '0e21', 1], $p['direct']);
        self::assertCount(8, $p['direct']);
    }

    public function test_duplicate_words_keep_separate_stable_query_ordinals(): void
    {
        $p = Spelling::probes('! PLANET cat planet');
        self::assertSame(['planet', 'cat', 'planet'], $p['tokens']);
        self::assertSame(3, $p['count']);
        self::assertSame([[0, 'planet', 0], [1, 'cat', 0], [2, 'planet', 0]],
            array_values(array_filter($p['direct'], fn (array $row): bool => $row[2] === 0)));
        self::assertSame([[0, 'planet'], [2, 'planet']], $p['insertions']);
        self::assertContains([0, 'plane', 6, 1], $p['substitutions']);
        self::assertContains([2, 'plane', 6, 1], $p['substitutions']);
    }

    public function test_only_the_first_eight_tokens_receive_probes(): void
    {
        $p = Spelling::probes(implode(' ', array_fill(0, 20, 'planet')));
        self::assertSame(array_fill(0, 8, 'planet'), $p['tokens']);
        self::assertSame(8, $p['count']);
        self::assertSame(range(0, 7), array_column($p['insertions'], 0));
        foreach (['direct', 'substitutions'] as $kind) {
            self::assertSame(range(0, 7), array_values(array_unique(array_column($p[$kind], 0))));
        }
    }

    public function test_fuzzy_length_limits_use_codepoints_and_include_four_and_sixty_four(): void
    {
        foreach ([3, 4, 64, 65] as $length) {
            $word = str_repeat('界', $length);
            $p = Spelling::probes($word);
            self::assertSame([$word], $p['tokens']);
            self::assertSame([0, $word, 0], $p['direct'][0]);
            if ($length === 3 || $length === 65) {
                self::assertSame([[0, $word, 0]], $p['direct']);
                self::assertSame([], $p['insertions']);
                self::assertSame([], $p['substitutions']);
            } else {
                self::assertSame([[0, $word]], $p['insertions']);
                self::assertCount($length, $p['substitutions']);
                self::assertSame([0, str_repeat('界', $length - 1), $length, 1], $p['substitutions'][$length - 1]);
            }
        }
    }

    public function test_query_limit_is_two_hundred_codepoints_before_tokenization(): void
    {
        $p = Spelling::probes(str_repeat(' ', 196) . 'CAFÉtrail');
        self::assertSame(['café'], $p['tokens']);
        self::assertSame([[0, 'café']], $p['insertions']);
        self::assertSame(self::emptyProbes(), Spelling::probes(str_repeat(' ', 200) . 'planet'));
    }

    public function test_unicode_letters_numbers_and_private_use_characters_are_tokens(): void
    {
        $p = Spelling::probes("CAFÉ ПРИВЕТ 山川草木 1234 \u{E000}abc");
        self::assertSame(['café', 'привет', '山川草木', '1234', "\u{E000}abc"], $p['tokens']);
        self::assertContains([0, 'caf', 4, 1], $p['substitutions']);
        self::assertContains([1, 'првет', 3, 1], $p['substitutions']);
        self::assertContains([2, '川草木', 1, 1], $p['substitutions']);
        self::assertContains([2, '山川草', 4, 1], $p['substitutions']);
        self::assertContains([2, '川山草木', 1], $p['direct']);
        self::assertContains([4, 'abc', 1, 1], $p['substitutions']);
    }

    public function test_normalization_uses_utf8_regardless_of_the_process_encoding(): void
    {
        $encoding = mb_internal_encoding();
        try {
            mb_internal_encoding('ISO-8859-1');
            self::assertSame(['café'], Spelling::probes(str_repeat(' ', 196) . 'CAFÉtrail')['tokens']);
            self::assertSame(['привет'], Spelling::probes('ПРИВЕТ')['tokens']);
        } finally {
            mb_internal_encoding($encoding);
        }
    }

    public function test_punctuation_and_combining_marks_split_without_accent_folding(): void
    {
        self::assertSame(['café', 'cafe', 'co', 'operate'],
            Spelling::probes("CAFÉ CAFE\u{0301} co\u{0308}operate")['tokens']);
        self::assertSame(['can', 't', 'river', 'bank', '123', '4', 'planet'],
            Spelling::probes("can't river-bank 123.4\0planet")['tokens']);
    }

    public function test_empty_or_punctuation_only_input_has_no_probes(): void
    {
        foreach (['', '   ', "!?-_'\"\n\t\0", "\u{0301}", '🙂'] as $query) {
            self::assertSame(self::emptyProbes(), Spelling::probes($query));
        }
    }

    public function test_invalid_utf8_has_no_partial_tokens_or_probes(): void
    {
        foreach (["\xff", "\xc3", "planet \xc3\x28", "river\xed\xa0\x80", str_repeat(' ', 200) . "\xff"] as $query) {
            self::assertSame(self::emptyProbes(), Spelling::probes($query));
        }
    }

    private static function emptyProbes(): array
    {
        return ['direct' => [], 'substitutions' => [], 'insertions' => [], 'tokens' => [], 'count' => 0];
    }
}
