<?php // tests/TouchCssTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

// Rules that hang on the input device, not the viewport width: a tablet at
// desktop width still has fingers and no keyboard.
final class TouchCssTest extends TestCase
{
    /** The declarations of every rule inside @media blocks whose condition matches $condition. */
    private static function blocks(string $condition): string
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css'));
        $out = '';
        $offset = 0;
        while (preg_match('~@media\s*([^{]*)\{~', $css, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $i = $start;
            while ($depth > 0 && $i < strlen($css)) {
                if ($css[$i] === '{') $depth++;
                if ($css[$i] === '}') $depth--;
                $i++;
            }
            if (preg_match($condition, $m[1][0]) === 1) $out .= substr($css, $start, $i - $start - 1) . "\n";
            $offset = $i;
        }
        return $out;
    }

    public function test_keyboard_hints_hide_on_touch_devices_at_any_width(): void
    {
        $rules = self::blocks('~hover:\s*none|pointer:\s*coarse~');
        $this->assertMatchesRegularExpression('~\.hint-keys:not\(\[hidden\]\)\s*\{[^}]*display:\s*none~', $rules);
    }

    /** @return list<array{string}> */
    public static function targets(): array
    {
        return [
            ['.skip-link'], ['.site-head.hd2 .head-post'], ['.hd2 .nav-search input'], ['.theme-seg button'],
            ['.nav-me-logout .link-btn'], ['.story-info .byline .follow-btn'], ['.rating-pips label'],
            ['.hd2 .browse-panel a'], ['.tag-chip'], ['.site-foot.ft2 .foot-col a'], ['.action-aux a'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('targets')]
    public function test_touch_devices_get_a_44px_floor_on_the_audited_controls(string $selector): void
    {
        $found = false;
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', self::blocks('~^\s*\(pointer:\s*coarse\)\s*$~'), $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            if (in_array($selector, array_map('trim', explode(',', $r[1])), true) && preg_match('~min-height\s*:\s*44px~', $r[2])) $found = true;
        }
        $this->assertTrue($found, "$selector needs min-height: 44px under (pointer: coarse)");
    }
}
