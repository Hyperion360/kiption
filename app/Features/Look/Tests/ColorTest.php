<?php // app/Features/Look/Tests/ColorTest.php
namespace App\Features\Look\Tests;
use PHPUnit\Framework\Attributes\Small;
use App\Look\Color;
use PHPUnit\Framework\TestCase;

#[Small]
final class ColorTest extends TestCase
{
    public function test_parse_accepts_only_strict_six_digit_hex(): void
    {
        $this->assertSame('#1f4f42', Color::parse('#1F4F42')?->hex());
        foreach (['', '#fff', '1f4f42', '#1f4f4', '#1f4f42a', "#1f4f42\n", ' #1f4f42', '#1f4f4g',
                  'red', 'rgb(0,0,0)', '#1f4f42;}body{display:none', 'url(x)', "#1f4f42\0"] as $bad) {
            $this->assertNull(Color::parse($bad), var_export($bad, true));
        }
    }

    public function test_contrast_matches_wcag_reference_values(): void
    {
        $black = Color::parse('#000000'); $white = Color::parse('#ffffff');
        $this->assertEqualsWithDelta(21.0, Color::contrast($black, $white), 0.01);
        $this->assertEqualsWithDelta(1.0, Color::contrast($white, $white), 0.001);
        // #777777 on white is the classic 4.48 case
        $this->assertEqualsWithDelta(4.48, Color::contrast(Color::parse('#777777'), $white), 0.01);
    }

    public function test_mix_endpoints_and_midpoint(): void
    {
        $a = Color::parse('#000000'); $b = Color::parse('#ffffff');
        $this->assertSame('#000000', $a->mix($b, 0.0)->hex());
        $this->assertSame('#ffffff', $a->mix($b, 1.0)->hex());
        $this->assertSame('#808080', $a->mix($b, 0.5)->hex());
    }

    public function test_hsl_round_trip_is_stable_within_one_step(): void
    {
        foreach (['#1f4f42', '#f8f7f3', '#b3261e', '#808080', '#ff0000', '#00ff00', '#0000ff', '#123456'] as $hex) {
            $c = Color::parse($hex); [$h, $s, $l] = $c->toHsl();
            $back = Color::fromHsl($h, $s, $l);
            $this->assertLessThanOrEqual(1, abs($c->r - $back->r), $hex);
            $this->assertLessThanOrEqual(1, abs($c->g - $back->g), $hex);
            $this->assertLessThanOrEqual(1, abs($c->b - $back->b), $hex);
        }
    }

    public function test_ensure_contrast_steps_lightness_until_the_floor_is_met(): void
    {
        $bg = Color::parse('#ffffff');
        $out = Color::parse('#cccccc')->ensureContrast($bg, 4.5, darker: true);
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($out, $bg));
        $dark = Color::parse('#222222');
        $lighter = Color::parse('#333333')->ensureContrast($dark, 4.5, darker: false);
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($lighter, $dark));
    }

    public function test_white_and_black_are_the_extremes(): void
    {
        $this->assertSame('#ffffff', Color::white()->hex());
        $this->assertSame('#000000', Color::black()->hex());
    }
}
