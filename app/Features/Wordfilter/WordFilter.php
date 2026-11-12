<?php // app/Features/Wordfilter/WordFilter.php
namespace App\Features\Wordfilter;
use Kip\Database;

/** The spam word list and the check run on member submissions. Matching is
 *  case-insensitive and whole-word, so "class" never trips on "ass". A phrase
 *  matches when its words appear in order with any spacing between them. */
final class WordFilter
{
    public const MAX_ENTRIES = 1000;
    public const MAX_WORD_LENGTH = 100;

    /** @var array<string, list<string>> memo per request, keyed by database object id */
    private static array $memo = [];

    /** @return list<string> */
    public static function words(Database $db): array
    {
        $key = spl_object_id($db);
        return self::$memo[$key] ??= array_map(
            static fn (array $r): string => (string) $r['word'],
            $db->all('SELECT word FROM word_filter ORDER BY word'));
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    /** The first listed word found in the text, or null when it is clean. */
    public static function find(Database $db, string ...$texts): ?string
    {
        $words = self::words($db);
        if ($words === []) return null;
        foreach ($texts as $text) {
            if (trim($text) === '') continue;
            foreach ($words as $word) {
                if (self::matches($word, $text)) return $word;
            }
        }
        return null;
    }

    public static function matches(string $word, string $text): bool
    {
        $parts = preg_split('/\s+/u', trim($word), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) return false;
        $pattern = '/(?<![\p{L}\p{N}])' . implode('\s+', array_map(static fn (string $p): string => preg_quote($p, '/'), $parts)) . '(?![\p{L}\p{N}])/iu';
        return preg_match($pattern, $text) === 1;
    }

    /** The friendly refusal for a blocked submission, or null when it is clean. */
    public static function refusal(Database $db, string ...$texts): ?string
    {
        $hit = self::find($db, ...$texts);
        return $hit === null ? null : \App\Lang::t('wordfilter.blocked', ['word' => $hit]);
    }

    /** @return list<string> normalised, de-duplicated, from the owner's textarea */
    public static function parse(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $w = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $line)));
            if ($w !== '') $out[$w] = true;
        }
        return array_map('strval', array_keys($out));
    }
}
