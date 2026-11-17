<?php // tests/HeadlineCaseTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/** Headings, titles, labels, links and buttons follow Chicago headline case:
 *  every word is capitalized except articles, short conjunctions and
 *  prepositions (never the first word). Sentences, fragments that sit inside
 *  a sentence, and parenthetical hints are left alone. */
#[Small]
final class HeadlineCaseTest extends TestCase
{
    private const SMALL = ['a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'so', 'yet', 'as', 'at', 'by',
        'in', 'of', 'on', 'to', 'per', 'via', 'vs', 'from', 'with', 'into', 'over', 'than', 'if', 'under'];

    /** Keys whose value is a heading, label, link or button, and is not a sentence. */
    private const KEY = '/(^|\.|_)(heading|title|legend|label|link|submit|button|column_\w+)$|^text\.group\./';
    /** Not headings: a label that is not a heading, or a link that ends a sentence. */
    private const SKIP = ['auth.terms_label', 'series.membership_label',
        'settings.waiting_link', 'maintenance.banner_link', 'settings.penname_pointer_link',
        'look.sample_link', // the sample anchor's text on the Colors and Layout screen, not a link in running copy
        // "Sign In" is a phrasal verb and "On and Off" are adverbs, so they keep their capitals.
        'auth.link.title', 'auth.link.heading', 'features.heading', 'text.group.features'];

    public function test_ui_headings_use_chicago_headline_case(): void
    {
        $en = require dirname(__DIR__) . '/app/lang/en.php';
        $bad = [];
        $checked = 0;
        foreach ($en as $key => $text) {
            if (!is_string($key) || !is_string($text) || !preg_match(self::KEY, $key)) continue;
            if (in_array($key, self::SKIP, true) || str_ends_with($key, '_aria') || str_starts_with($key, 'notifications.')) continue;
            if (preg_match('/[.?!]$/', $text) || str_word_count($text) > 9) continue;
            $checked++;
            $head = explode(' (', $text, 2)[0];
            $words = preg_split('/\s+/', trim($head));
            foreach ($words as $i => $word) {
                if ($word === '-') continue;
                $start = $i === 0 || $words[$i - 1] === '-'; // the first word, or the one after a dash
                $w = trim($word, ',;:"');
                if (!$start && in_array(strtolower($w), self::SMALL, true) && $w !== strtolower($w)) { $bad[] = "$key => $text"; break; } // a small word capitalized mid-title
                if ($w === '' || !preg_match('/^[a-z]/', $w)) continue; // capitalized, a placeholder, a number or a symbol
                if (str_contains($w, '.') || str_contains($w, '/') || str_contains($w, '@')) continue;
                if (!$start && in_array($w, self::SMALL, true)) continue;
                $bad[] = "$key => $text";
                break;
            }
        }
        $this->assertGreaterThan(150, $checked, 'the key pattern must keep matching the heading strings');
        $this->assertSame([], $bad, "These strings are not in Chicago headline case:\n" . implode("\n", $bad));
    }
}
