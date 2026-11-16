<?php // app/src/Look/Rules.php
declare(strict_types=1);
namespace App\Look;

/** What an owner may submit: three #RRGGBB colors and one name per preset
 *  group, with two contrast gates that block (an unreadable site cannot be
 *  fixed by the visitor). The raw input is never returned. */
final class Rules
{
    public const COLORS = ['accent', 'bg', 'ink'];
    public const TEXT_MIN = 4.5;
    public const ACCENT_MIN = 5.0;

    /** @return array{values: array<string,string>, errors: array<string, array{0:string, 1:array<string,string>}>} */
    public static function check(array $post): array
    {
        $errors = [];
        $values = [];
        $colors = [];
        foreach (self::COLORS as $field) {
            $raw = $post[$field] ?? null;
            if (!is_string($raw)) { $errors[$field] = ['look.err_missing', []]; continue; }
            $c = Color::parse($raw);
            if ($c === null) { $errors[$field] = ['look.err_color', []]; continue; }
            $colors[$field] = $c;
            $values[$field] = $c->hex();
        }
        foreach (array_keys(Presets::GROUPS) as $group) {
            $raw = $post[$group] ?? null;
            if (!is_string($raw)) { $errors[$group] = ['look.err_missing', []]; continue; }
            if (!in_array($raw, Presets::names($group), true)) { $errors[$group] = ['look.err_preset', []]; continue; }
            $values[$group] = $raw;
        }
        if ($errors !== []) return ['values' => [], 'errors' => $errors];

        $text = Color::contrast($colors['ink'], $colors['bg']);
        if ($text < self::TEXT_MIN) {
            $errors['ink'] = ['look.err_contrast_text', ['ratio' => self::ratio($text)]];
        }
        $accent = Color::contrast($colors['accent'], $colors['bg']);
        if ($accent < self::ACCENT_MIN) {
            $errors['accent'] = ['look.err_contrast_accent', ['ratio' => self::ratio($accent)]];
        }
        return $errors === [] ? ['values' => $values, 'errors' => []] : ['values' => [], 'errors' => $errors];
    }

    /** One decimal, a dot, whatever the locale. Rounded down so "4.5" is never shown for 4.46. */
    private static function ratio(float $r): string
    {
        return number_format(floor($r * 10) / 10, 1, '.', '');
    }
}
