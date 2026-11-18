<?php // tests/ThemeContrastTokensTest.php
namespace App\Tests;
use App\Look\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// A design review measured form fields at 1.4:1 against the page (the
// decorative --line-strong hairline), browser-gray placeholders under 4.5:1
// on sepia and night, and the vendored Epigraph editor drawing paper ink on
// the Night page (1.15:1). These checks read the theme token blocks in
// reader.css and each skin, then hold the WCAG floors: 4.5:1 for text
// tokens and 3:1 for the field edge, against both the page and the surface.
final class ThemeContrastTokensTest extends TestCase
{
    private const SHEETS = ['public/assets/reader.css', 'public/assets/skins/classic.css', 'public/assets/skins/manuscript.css'];

    private static function css(string $file): string
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
        return (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }

    /** @return array<string,string> custom properties declared in the first block for $selector */
    private static function block(string $css, string $selector): array
    {
        if (!preg_match('~' . preg_quote($selector, '~') . '\s*\{([^{}]*)\}~', $css, $m)) return [];
        preg_match_all('~(--[\w-]+)\s*:\s*([^;]+);~', $m[1], $d, PREG_SET_ORDER);
        $out = [];
        foreach ($d as [, $k, $v]) {
            $v = trim($v);   // "#fff" shorthand reads as "#ffffff"
            $out[$k] = preg_match('~^#([0-9a-f])([0-9a-f])([0-9a-f])$~i', $v, $h) ? "#$h[1]$h[1]$h[2]$h[2]$h[3]$h[3]" : $v;
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,array<string,string>> theme name => resolved hex tokens */
    private static function themes(string $file): array
    {
        $css = self::css($file);
        $base = self::block(self::css('public/assets/reader.css'), ':root');
        $paper = self::block($css, ':root') + $base;
        ksort($paper);
        return [
            'paper' => $paper,
            'sepia' => self::merged(self::block($css, 'html[data-theme="sepia"]'), $paper),
            'night' => self::merged(self::block($css, 'html[data-theme="night"]'), $paper),
            'auto-dark' => self::merged(self::block($css, 'html:not([data-theme="paper"]):not([data-theme="sepia"])'), $paper),
        ];
    }

    /** @param array<string,string> $own @param array<string,string> $inherit @return array<string,string> */
    private static function merged(array $own, array $inherit): array
    {
        $all = $own + $inherit;
        ksort($all);
        return $all;
    }

    /** @return iterable<string,array{string}> */
    public static function sheets(): iterable
    {
        foreach (self::SHEETS as $s) yield basename($s) => [$s];
    }

    #[DataProvider('sheets')]
    public function test_text_tokens_read_on_page_and_surface(string $file): void
    {
        $bad = [];
        foreach (self::themes($file) as $theme => $t) {
            foreach (['--ink', '--muted', '--accent'] as $fg) foreach (['--bg', '--surface'] as $bg) {
                $r = Color::contrast(Color::parse($t[$fg]), Color::parse($t[$bg]));
                if ($r < 4.5) $bad[] = sprintf('%s %s on %s = %.2f', $theme, $fg, $bg, $r);
            }
        }
        $this->assertSame([], $bad);
    }

    #[DataProvider('sheets')]
    public function test_field_edge_clears_three_to_one(string $file): void
    {
        // Sepia restates the mix: an owner Look's :root edge is worked out
        // for the owner page and must not reach the sepia page.
        $this->assertMatchesRegularExpression('~:root, html\[data-theme="sepia"\]\{ --field-line:color-mix\(in srgb, var\(--ink\) 55%, var\(--bg\)\); \}~', self::css('public/assets/reader.css'));
        $bad = [];
        foreach (self::themes($file) as $theme => $t) {
            $edge = Color::parse($t['--ink'])->mix(Color::parse($t['--bg']), 0.45);
            foreach (['--bg', '--surface'] as $bg) {
                $r = Color::contrast($edge, Color::parse($t[$bg]));
                if ($r < 3.0) $bad[] = sprintf('%s field edge on %s = %.2f', $theme, $bg, $r);
            }
        }
        $this->assertSame([], $bad);
    }

    #[DataProvider('sheets')]
    public function test_auto_dark_matches_night(string $file): void
    {
        $t = self::themes($file);
        $this->assertSame($t['night'], $t['auto-dark']);
    }

    public function test_field_rules_use_the_field_edge(): void
    {
        $css = self::css('public/assets/reader.css');
        foreach (['input, select, textarea', '.nav-search input', '.admin-nav-filter', '#menu .sheet-search', '.hd2 .nav-search', '.queue-remove-panel textarea'] as $sel) {
            $this->assertMatchesRegularExpression('~(^|[{}])\s*' . preg_quote($sel, '~') . '\s*\{[^}]*border:\s*1px solid var\(--field-line\)~', $css, $sel);
        }
        $this->assertMatchesRegularExpression('~::placeholder\s*\{[^}]*color:\s*var\(--muted\)~', $css);
    }

    public function test_epigraph_follows_the_site_theme(): void
    {
        // The bundle declares its own paper tokens on .epigraph; the override
        // must outrank that selector and pass the site values through.
        $css = self::css('public/assets/reader.css');
        $this->assertMatchesRegularExpression('~:root \.epigraph\s*\{[^}]*--ink:\s*inherit[^}]*--bg:\s*inherit[^}]*--muted:\s*inherit[^}]*--accent:\s*inherit~', $css);
    }

    // A locked member is a warning state, so the chip is red (the design's
    // Locked color) instead of the brand green that reads as "fine".
    public function test_locked_member_chip_is_danger_red_and_readable(): void
    {
        $css = self::css('public/assets/reader.css');
        $this->assertMatchesRegularExpression('~\.member-status\.is-locked\s*\{[^}]*color:\s*var\(--danger\)[^}]*border-color:\s*var\(--danger\)~', $css);
        foreach (self::themes('public/assets/reader.css') as $theme => $t) {
            $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::parse($t['--danger']), Color::parse($t['--bg'])), "$theme red text on page");
            $this->assertGreaterThanOrEqual(3.0, Color::contrast(Color::parse($t['--danger']), Color::parse($t['--bg'])), "$theme red chip edge on page");
        }
    }
}
