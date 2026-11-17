<?php // app/src/Look/Palette.php
declare(strict_types=1);
namespace App\Look;

/** Three owner colors in, the whole variable set out, for the light slot and
 *  a night slot worked out from the same inputs. One function feeds the
 *  compiler and the live preview, so they cannot disagree. Every value is
 *  re-serialized from integers: a hex color or the fixed scrim rgba. */
final class Palette
{
    /** The only custom properties the owner file may set, in print order. */
    public const VARS = [
        '--bg', '--ink', '--ink-2', '--muted', '--line', '--line-strong', '--field-line', '--hover', '--accent',
        '--accent-hover', '--on-accent', '--visited', '--track', '--surface', '--scrim', '--pill-bg',
        '--btn', '--btn-ink',
    ];
    /** Declarations only the night map carries (a literal, not a color). */
    public const NIGHT_EXTRA = ['color-scheme'];

    /** @return array{light: array<string,string>, night: array<string,string>} */
    public static function derive(Color $accent, Color $bg, Color $ink): array
    {
        return [
            'light' => self::build($accent, $bg, $ink, false),
            'night' => self::night($accent, $bg, $ink),
        ];
    }

    /** @return array<string,string> */
    private static function night(Color $accent, Color $bg, Color $ink): array
    {
        [$bh, $bs] = $bg->toHsl();
        [$ih, $is] = $ink->toHsl();
        $nbg = Color::fromHsl($bh, min($bs, 0.14), 0.08);
        $nink = Color::fromHsl($ih, min($is, 0.10), 0.86);
        [$ah, $as, $al] = $accent->toHsl();
        $nacc = $accent;
        // Lift the accent until it reads on the night background and on the
        // tinted pill (the pill mixes 12 percent of the accent into the bg).
        for ($i = 0; $i < 40; $i++) {
            $pill = $nbg->mix($nacc, 0.12);
            if ((Color::contrast($nacc, $nbg) >= 5.0 && Color::contrast($nacc, $pill) >= 4.5) || $al >= 0.85) break;
            $al = min(0.85, $al + 0.04);
            $nacc = Color::fromHsl($ah, $as, $al);
        }
        return self::build($nacc, $nbg, $nink, true) + ['color-scheme' => 'dark'];
    }

    /** @return array<string,string> */
    private static function build(Color $accent, Color $bg, Color $ink, bool $dark): array
    {
        // Nudge text toward the ink side of the background, whichever side that is.
        $darker = $bg->luminance() > $ink->luminance();
        $line = $bg->mix($ink, 0.10);
        $hover = $accent->mix($ink, 0.20);
        $onAccent = Color::contrast(Color::white(), $accent) >= Color::contrast(Color::black(), $accent) ? Color::white() : Color::black();
        $surface = $bg->luminance() > 0.5 ? $bg->mix(Color::white(), 0.55) : $bg->mix(Color::white(), 0.05);
        // Keep tinted surfaces within reach of readable text on the ink side.
        // Otherwise a boundary palette can have no color that reads on all three.
        $inkLimit = $darker ? Color::black() : Color::white();
        $surface = $surface->ensureContrast($inkLimit, Rules::TEXT_MIN, !$darker);
        // Muted ink is also the placeholder color, and fields sit on the
        // surface, so it has to read on both. The field edge needs the WCAG
        // non-text floor on both too: a fixed mix of ink into the page gives
        // only about 2:1 when the text itself sits at the 4.5 floor. Both
        // step toward the ink side, which the surface clamp above keeps open.
        $muted = $ink->mix($bg, 0.42);
        $fieldLine = $bg->mix($ink, 0.55);
        foreach ([$bg, $surface] as $background) {
            $muted = $muted->ensureContrast($background, Rules::TEXT_MIN, $darker);
            $fieldLine = $fieldLine->ensureContrast($background, Rules::FIELD_MIN, $darker);
        }
        $pill = $bg->mix($accent, 0.12)->ensureContrast($inkLimit, Rules::TEXT_MIN, !$darker);
        $secondary = $ink->mix($bg, 0.25);
        foreach ([$bg, $surface, $pill] as $background) {
            $secondary = $secondary->ensureContrast($background, Rules::TEXT_MIN, $darker);
        }
        $visited = $accent->mix($ink, 0.50)->ensureContrast($bg, 4.5, $darker);
        return [
            '--bg' => $bg->hex(),
            '--ink' => $ink->hex(),
            '--ink-2' => $secondary->hex(),
            '--muted' => $muted->hex(),
            '--line' => $line->hex(),
            '--line-strong' => $bg->mix($ink, 0.18)->hex(),
            '--field-line' => $fieldLine->hex(),
            '--hover' => $bg->mix($ink, 0.05)->hex(),
            '--accent' => $accent->hex(),
            '--accent-hover' => (Color::contrast($hover, $bg) >= 4.5 ? $hover : $accent)->hex(),
            '--on-accent' => $onAccent->hex(),
            '--visited' => $visited->hex(),
            '--track' => $line->hex(),
            '--surface' => $surface->hex(),
            '--scrim' => sprintf('rgba(%d,%d,%d,.45)', $ink->r, $ink->g, $ink->b),
            '--pill-bg' => $pill->hex(),
            '--btn' => $accent->hex(),
            '--btn-ink' => $onAccent->hex(),
        ];
    }
}
