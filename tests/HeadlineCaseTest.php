<?php // tests/HeadlineCaseTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

/** Headings, titles, labels, links and buttons follow Chicago headline case:
 *  every word is capitalized except articles, short conjunctions and
 *  prepositions (never the first word). Sentences, fragments that sit inside
 *  a sentence, and parenthetical hints are left alone. */
final class HeadlineCaseTest extends TestCase
{
    private const SMALL = ['a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'so', 'yet', 'as', 'at', 'by',
        'in', 'of', 'on', 'to', 'per', 'via', 'vs', 'from', 'with', 'into', 'over', 'than', 'if'];

    /** Keys whose value is a heading, label, link or button, and is not a sentence. */
    private const KEY = '/(^|\.|_)(heading|title|legend|label|link|submit|button|column_\w+)$/';
    private const SKIP = ['auth.terms_label', 'series.membership_label'];

    public function test_ui_headings_use_chicago_headline_case(): void
    {
        $en = require dirname(__DIR__) . '/app/lang/en.php';
        $bad = [];
        foreach ($en as $key => $text) {
            if (!is_string($key) || !is_string($text) || !preg_match(self::KEY, $key)) continue;
            if (in_array($key, self::SKIP, true) || str_ends_with($key, '_aria') || str_starts_with($key, 'notifications.')) continue;
            if (preg_match('/[.?!]$/', $text) || str_word_count($text) > 9) continue;
            $head = explode(' (', $text, 2)[0];
            foreach (preg_split('/\s+/', trim($head)) as $i => $word) {
                $w = trim($word, ',;:"');
                if ($w === '' || !preg_match('/^[a-z]/', $w)) continue; // capitalized, a placeholder, a number or a symbol
                if (str_contains($w, '.') || str_contains($w, '/') || str_contains($w, '@')) continue;
                if ($i > 0 && in_array($w, self::SMALL, true)) continue;
                $bad[] = "$key => $text";
                break;
            }
        }
        $this->assertSame([], $bad, "These strings are not in Chicago headline case:\n" . implode("\n", $bad));
    }
}
