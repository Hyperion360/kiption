<?php // tests/PhoneTapTargetCssTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// A design review at 390px wide measured these links and controls below the
// 44px tap-target floor the stylesheet promises: the footer links (15 and 27
// tall), the admin home's activity log link (19), a member's Approve link
// (17), story card titles (28), the search rail's language select and pen
// name box and tag finder (40) and the tag include and exclude toggles (32). Each must reach
// 44px inside a phone-width media block.
final class PhoneTapTargetCssTest extends TestCase
{
    private static function phoneRules(): string
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $out = '';
        $offset = 0;
        while (preg_match('~@media\s*\(max-width:\s*(\d+)px\)\s*\{~', $css, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $i = $start;
            while ($depth > 0 && $i < strlen($css)) {
                if ($css[$i] === '{') $depth++;
                if ($css[$i] === '}') $depth--;
                $i++;
            }
            if ((int) $m[1][0] >= 600) $out .= substr($css, $start, $i - $start - 1) . "\n";
            $offset = $i;
        }
        return $out;
    }

    /** @return list<array{string}> */
    public static function targets(): array
    {
        return [
            ['.site-foot.ft2 .foot-brand > a'],
            ['.site-foot.ft2 .poweredby > a'],
            ['.home-recent p > a[href="/activity"]'],
            ['.member-approve'],
            ['.story-card .story-title'],
            ['.site-main .search-form .search-rail .rail-group select'],
            ['.site-main .search-form .search-rail .rail-by-name input:not([type="checkbox"]):not([type="radio"])'],
            ['.site-main .search-form .search-rail .tag-find input[type="search"]'],
            ['.search-refine summary'],
        ];
    }

    #[DataProvider('targets')]
    public function test_phone_target_reaches_44px(string $selector): void
    {
        $rules = self::phoneRules();
        $found = false;
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $rules, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $sels = array_map('trim', explode(',', $r[1]));
            if (!in_array($selector, $sels, true)) continue;
            if (preg_match('~min-height\s*:\s*44px~', $r[2])) $found = true;
        }
        $this->assertTrue($found, "$selector needs min-height: 44px in a phone media block");
    }
}
