<?php // app/src/Look/Presets.php
declare(strict_types=1);
namespace App\Look;

/** The only place preset names and their CSS live. The CSS strings are
 *  literals written here; nothing the owner posts is ever interpolated. The
 *  first name in each group is the built-in default and emits nothing. */
final class Presets
{
    public const WIDTH = [
        'standard' => '',
        'narrow' => ':root{--read-width:560px}',
        'wide' => ':root{--read-width:720px}',
    ];
    public const SIZE = [
        'standard' => '',
        'small' => ':root{--read-size:17px}',
        'large' => ':root{--read-size:21px}',
    ];
    public const DENSITY = [
        'standard' => '',
        'compact' => ':root{--read-lh:1.6}.site-main{padding:20px 24px 48px}',
        'roomy' => ':root{--read-lh:1.85}.site-main{padding:44px 24px 80px}',
    ];
    public const HEADER = [
        'standard' => '',
        'plain' => '.site-head{border-block-end-color:transparent}',
        'tinted' => '.site-head{background:var(--surface)}',
    ];
    public const GROUPS = ['width' => self::WIDTH, 'size' => self::SIZE, 'density' => self::DENSITY, 'header' => self::HEADER];

    /** @return list<string> */
    public static function names(string $group): array
    {
        return array_keys(self::GROUPS[$group] ?? []);
    }

    public static function css(string $group, string $name): string
    {
        return self::GROUPS[$group][$name] ?? '';
    }
}
