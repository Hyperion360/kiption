<?php // app/Features/Look/Tests/PaletteTest.php
namespace App\Features\Look\Tests;
use PHPUnit\Framework\Attributes\Small;
use App\Look\{Color, Compiler, Palette, Rules};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Small]
final class PaletteTest extends TestCase
{
    /** Inputs that pass the Rules gates (text 4.5 on background, accent 5.0). */
    private const GRID = [
        ['#1f4f42', '#f8f7f3', '#1c2320'],   // the built-in set
        ['#b3261e', '#ffffff', '#111111'],
        ['#2255aa', '#fdf6e3', '#073642'],
        ['#7a3e9d', '#eeeeee', '#222222'],
        ['#7fd1b9', '#101820', '#e6edf3'],   // a dark background in the light slot
        ['#0b3d91', '#ffffff', '#000000'],
    ];

    private function p(array $row): array
    {
        return Palette::derive(Color::parse($row[0]), Color::parse($row[1]), Color::parse($row[2]));
    }

    public static function secondaryInkPalettes(): iterable
    {
        foreach (self::GRID as $i => $row) yield 'existing palette ' . $i => [$row];
        yield 'minimum ink on white' => [['#004400', '#ffffff', '#767676']];
        yield 'light ink with a bright pill' => [['#ffffff', '#6d6d6d', '#f0f0f0']];
        yield 'dark ink with a dark pill' => [['#000000', '#7c7c7c', '#000000']];
    }

    #[DataProvider('secondaryInkPalettes')]
    public function test_secondary_ink_is_readable_on_every_text_surface(array $row): void
    {
        $values = array_combine(Rules::COLORS, $row) + Compiler::defaults();
        $this->assertSame([], Rules::check($values)['errors'], 'the owner colors are accepted');
        foreach ($this->p($row) as $slot => $set) {
            foreach (['--bg', '--surface', '--pill-bg'] as $surface) {
                $this->assertGreaterThanOrEqual(4.5,
                    Color::contrast(Color::parse($set['--ink-2']), Color::parse($set[$surface])),
                    $slot . ' secondary ink on ' . $surface);
            }
        }
    }

    /** Text at exactly the 4.5 floor on light, mid and dark backgrounds, in
     *  grays and in hues: the pairs where a fixed mix of ink into the page
     *  measured only 1.8 to 2.7:1. Found by sweeping accepted palettes. */
    public static function floorPalettes(): iterable
    {
        yield 'red ink on black, white accent' => [['#ffffff', '#000000', '#d04141']];
        yield 'green ink on light gray' => [['#000000', '#e0e0e0', '#1d721d']];
        yield 'gray ink at the floor on white' => [['#004400', '#ffffff', '#767676']];
        yield 'white ink on mid gray' => [['#ffffff', '#6d6d6d', '#f0f0f0']];
        yield 'black ink on mid gray' => [['#000000', '#7c7c7c', '#000000']];
        yield 'pale ink on a dark olive' => [['#44dc7e', '#242c0a', '#9ee1c8']];
        yield 'dark ink on a pale blue' => [['#970f3c', '#83def8', '#930f57']];
        yield 'dark background in the light slot' => [['#7fd1b9', '#101820', '#e6edf3']];
    }

    #[DataProvider('floorPalettes')]
    public function test_field_edge_and_placeholder_ink_clear_their_floors_at_the_text_floor(array $row): void
    {
        $values = array_combine(Rules::COLORS, $row) + Compiler::defaults();
        $this->assertSame([], Rules::check($values)['errors'], 'the owner colors are accepted');
        $bad = [];
        foreach ($this->p($row) as $slot => $set) {
            foreach (['--bg', '--surface'] as $on) {
                $back = Color::parse($set[$on]);
                $edge = Color::contrast(Color::parse($set['--field-line']), $back);
                $ink = Color::contrast(Color::parse($set['--muted']), $back);
                if ($edge < Rules::FIELD_MIN) $bad[] = sprintf('%s field edge on %s = %.2f', $slot, $on, $edge);
                if ($ink < Rules::TEXT_MIN) $bad[] = sprintf('%s muted ink on %s = %.2f', $slot, $on, $ink);
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_every_accepted_palette_gets_a_field_edge_and_placeholder_ink_that_read(): void
    {
        // A fixed seed so a failure names a reproducible palette: random
        // triples kept only when the editor's own gates accept them, plus a
        // grid of inks walked down to exactly 4.5:1 on assorted backgrounds.
        mt_srand(20261117);
        $rows = [];
        for ($i = 0; $i < 4000; $i++) $rows[] = [sprintf('#%06x', mt_rand(0, 0xffffff)), sprintf('#%06x', mt_rand(0, 0xffffff)), sprintf('#%06x', mt_rand(0, 0xffffff))];
        foreach (['#ffffff', '#f8f7f3', '#cccccc', '#808080', '#777777', '#333333', '#101820', '#000000'] as $bg) {
            $b = Color::parse($bg);
            $toward = Color::contrast(Color::black(), $b) >= Color::contrast(Color::white(), $b) ? 1.0 : 0.0;
            foreach ([0, 40, 120, 220, 300] as $hue) foreach ([0.0, 0.7] as $sat) {
                for ($step = 0; $step <= 200; $step++) {
                    $c = Color::fromHsl($hue, $sat, $toward === 1.0 ? 1 - $step / 200 : $step / 200);
                    if (Color::contrast($c, $b) >= Rules::TEXT_MIN) break;
                }
                foreach (['#000000', '#ffffff', '#1f4f42'] as $accent) $rows[] = [$accent, $bg, $c->hex()];
            }
        }
        $accepted = 0;
        $bad = [];
        foreach ($rows as $row) {
            if (Rules::check(array_combine(Rules::COLORS, $row) + Compiler::defaults())['errors'] !== []) continue;
            $accepted++;
            foreach ($this->p($row) as $slot => $set) {
                foreach (['--bg', '--surface'] as $on) {
                    $back = Color::parse($set[$on]);
                    if (Color::contrast(Color::parse($set['--field-line']), $back) < Rules::FIELD_MIN) $bad[] = $slot . ' edge ' . implode(',', $row) . ' on ' . $on;
                    if (Color::contrast(Color::parse($set['--muted']), $back) < Rules::TEXT_MIN) $bad[] = $slot . ' muted ' . implode(',', $row) . ' on ' . $on;
                }
            }
        }
        $this->assertGreaterThan(150, $accepted, 'the sweep exercises enough accepted palettes');
        $this->assertSame([], $bad);
    }

    public function test_readable_default_secondary_colors_stay_unchanged(): void
    {
        $p = $this->p(self::GRID[0]);
        $this->assertSame('#535855', $p['light']['--ink-2']);
        $this->assertSame('#a8adaa', $p['night']['--ink-2']);
        $this->assertSame('#fcfbfa', $p['light']['--surface']);
        $this->assertSame('#23221e', $p['night']['--surface']);
        $this->assertSame('#dee3de', $p['light']['--pill-bg']);
        $this->assertSame('#1c2721', $p['night']['--pill-bg']);
    }

    public function test_derive_returns_exactly_the_allowed_variables(): void
    {
        foreach (self::GRID as $row) {
            $p = $this->p($row);
            $this->assertSame(Palette::VARS, array_keys($p['light']));
            $this->assertSame([...Palette::VARS, ...Palette::NIGHT_EXTRA], array_keys($p['night']));
        }
    }

    public function test_every_value_is_a_hex_or_the_scrim_rgba(): void
    {
        foreach (self::GRID as $row) {
            $p = $this->p($row);
            foreach (['light', 'night'] as $slot) {
                foreach ($p[$slot] as $k => $v) {
                    if ($k === 'color-scheme') { $this->assertSame('dark', $v); continue; }
                    $this->assertMatchesRegularExpression('/^(#[0-9a-f]{6}|rgba\(\d{1,3},\d{1,3},\d{1,3},\.45\))$/D', $v);
                }
            }
        }
    }

    public function test_night_set_passes_the_same_contrast_gates(): void
    {
        foreach (self::GRID as $row) {
            $n = $this->p($row)['night'];
            $bg = Color::parse($n['--bg']);
            $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::parse($n['--ink']), $bg), implode(',', $row));
            $this->assertGreaterThanOrEqual(5.0, Color::contrast(Color::parse($n['--accent']), $bg), implode(',', $row));
            $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::parse($n['--on-accent']), Color::parse($n['--accent'])));
            $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::parse($n['--muted']), $bg));
        }
    }

    public function test_accent_stays_readable_on_the_tinted_pill_in_both_sets(): void
    {
        foreach (self::GRID as $row) {
            foreach ($this->p($row) as $slot => $set) {
                $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::parse($set['--accent']), Color::parse($set['--pill-bg'])), $slot . ' ' . implode(',', $row));
                $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::parse($set['--muted']), Color::parse($set['--bg'])), $slot . ' muted');
            }
        }
    }

    public function test_surface_follows_the_background_lightness(): void
    {
        $dark = $this->p(self::GRID[4])['light'];
        $this->assertGreaterThan(2.0, Color::contrast(Color::parse($dark['--muted']), Color::parse($dark['--surface'])));
        $light = $this->p(self::GRID[1])['light'];
        $this->assertSame('#ffffff', $light['--surface']);
    }

    public function test_on_accent_picks_the_readable_side(): void
    {
        $p = Palette::derive(Color::parse('#ffd54f'), Color::parse('#ffffff'), Color::parse('#111111'));
        $this->assertSame('#000000', $p['light']['--on-accent']);
    }

    public function test_button_tokens_follow_the_accent_in_both_sets(): void
    {
        foreach (self::GRID as $row) {
            foreach ($this->p($row) as $set) {
                $this->assertSame($set['--accent'], $set['--btn']);
                $this->assertSame($set['--on-accent'], $set['--btn-ink']);
            }
        }
    }

    public function test_dark_set_carries_color_scheme_and_light_does_not(): void
    {
        $p = $this->p(self::GRID[0]);
        $this->assertSame('dark', $p['night']['color-scheme'] ?? null);
        $this->assertArrayNotHasKey('color-scheme', $p['light']);
    }
}
