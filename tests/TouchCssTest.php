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
}
