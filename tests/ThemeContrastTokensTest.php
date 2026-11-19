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

    // Night keeps the Paper button fill (dark forest green, the brand look),
    // which sits on the near black page at about 2:1. A thin muted sage edge
    // carries the 3:1 non-text contrast (WCAG 1.4.11) against the page and
    // the card surfaces, and the label stays 4.5:1 on the fill.
    public function test_night_primary_button_keeps_forest_fill_with_a_sage_edge(): void
    {
        $css = self::css('public/assets/reader.css');
        $t = self::themes('public/assets/reader.css');
        foreach (['night', 'auto-dark'] as $theme) {
            $this->assertSame('#1f4f42', $t[$theme]['--btn'], "$theme fill is the forest green");
            $this->assertSame('#ffffff', $t[$theme]['--btn-ink'], "$theme label");
            $edge = Color::parse($t[$theme]['--btn-edge']);
            foreach (['--bg', '--surface', '--hover'] as $ground) {
                $this->assertGreaterThanOrEqual(3.0, Color::contrast($edge, Color::parse($t[$theme][$ground])), "$theme edge on $ground");
            }
            $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::parse($t[$theme]['--btn-ink']), Color::parse($t[$theme]['--btn'])), "$theme label on fill");
        }
        $this->assertArrayNotHasKey('--btn-edge', $t['paper']);
        $this->assertArrayNotHasKey('--btn-edge', $t['sepia']);
        $this->assertMatchesRegularExpression('~\.btn-primary,[^{]*\{[^}]*border-color:\s*var\(--btn-edge,\s*var\(--btn\)\)~', $css);
        $this->assertMatchesRegularExpression('~\.home-todo \.btn\s*\{[^}]*border-color:\s*var\(--btn-edge,\s*var\(--btn\)\)~', $css);
        $this->assertMatchesRegularExpression('~\.cta-primary\s*\{[^}]*border:\s*1px solid var\(--btn-edge,\s*var\(--btn\)\)~', $css);
        $this->assertMatchesRegularExpression('~\.continue-cta\s*\{[^}]*box-shadow:\s*inset 0 0 0 1px var\(--btn-edge,\s*var\(--btn\)\)~', $css);
    }

    // The Cloud offer band: Paper keeps the drawn dark slab, Night gets a dark
    // green band with a sage rule, so every band text token reads on the band
    // and the rule holds 3:1 against the page.
    public function test_offer_band_tokens_read_in_every_theme(): void
    {
        $bad = [];
        foreach (self::themes('public/assets/reader.css') as $theme => $t) {
            foreach (['--band-ink', '--band-soft'] as $fg) {
                $r = Color::contrast(Color::parse($t[$fg]), Color::parse($t['--band-bg']));
                if ($r < 4.5) $bad[] = sprintf('%s %s on band = %.2f', $theme, $fg, $r);
            }
            $r = Color::contrast(Color::parse($t['--band-cta-ink']), Color::parse($t['--band-cta-bg']));
            if ($r < 4.5) $bad[] = sprintf('%s button label = %.2f', $theme, $r);
            $r = Color::contrast(Color::parse($t['--band-edge']), Color::parse($t['--bg']));
            if ($r < 3.0 && Color::contrast(Color::parse($t['--band-bg']), Color::parse($t['--bg'])) < 3.0) $bad[] = sprintf('%s band edge on page = %.2f', $theme, $r);
        }
        $this->assertSame([], $bad);
    }
    // The chapter editor and the new-story popover restate a few tokens per
    // theme (sepia surfaces, the danger red). Resolve them the way the page
    // does and hold every text token to 4.5:1 on the page and the surface,
    // with the field edge at 3:1. A computed pass over every text node in the
    // browser (paper, sepia, night, 1440 and 390 wide) backs this up.
    public function test_editor_scoped_tokens_read_in_every_theme(): void
    {
        $css = self::css('public/assets/reader.css');
        $themes = self::themes('public/assets/reader.css');
        $bad = [];
        foreach (['paper', 'sepia', 'night'] as $theme) {
            $t = self::block($css, 'html[data-theme="' . $theme . '"] #chapter-editor-form.ed') + $themes[$theme];
            $this->assertNotSame([], self::block($css, 'html[data-theme="' . $theme . '"] #chapter-editor-form.ed'), "$theme editor block");
            foreach (['--ink', '--ink-2', '--muted', '--accent', '--danger'] as $fg) foreach (['--bg', '--surface'] as $bg) {
                if (!isset($t[$fg], $t[$bg])) continue;
                $r = Color::contrast(Color::parse($t[$fg]), Color::parse($t[$bg]));
                if ($r < 4.5) $bad[] = sprintf('%s editor %s on %s = %.2f', $theme, $fg, $bg, $r);
            }
            $edge = Color::parse($t['--ink'])->mix(Color::parse($t['--bg']), 0.45);
            $r = Color::contrast($edge, Color::parse($t['--surface']));
            if ($r < 3.0) $bad[] = sprintf('%s editor field edge on surface = %.2f', $theme, $r);
        }
        // Auto follows the OS: its dark danger red is the Night one.
        $this->assertMatchesRegularExpression('~@media \(prefers-color-scheme: dark\) \{ html:not\(\[data-theme="paper"\]\):not\(\[data-theme="sepia"\]\) #chapter-editor-form\.ed \{ --danger: ' . preg_quote(self::block($css, 'html[data-theme="night"] #chapter-editor-form.ed')['--danger'], '~') . '~', $css);
        $this->assertSame([], $bad);
    }

    // The design has no accent strip above the editor header; a 2px green
    // border-top there was an unexplained line across the top of the page.
    public function test_editor_header_has_no_top_strip(): void
    {
        $css = self::css('public/assets/reader.css');
        $this->assertMatchesRegularExpression('~\.ed-head\s*\{[^}]*position:\s*sticky~', $css);
        $this->assertDoesNotMatchRegularExpression('~\.ed-head\s*\{[^}]*(border-block-start|border-top)[^}]*\}~', $css);
    }
}
