<?php // app/Features/Look/Tests/PaletteTest.php
namespace App\Features\Look\Tests;
use App\Look\{Color, Palette};
use PHPUnit\Framework\TestCase;

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
