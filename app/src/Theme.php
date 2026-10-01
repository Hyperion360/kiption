<?php // app/src/Theme.php
namespace App;
use Kip\Http\Request;

final class Theme
{
    public const COOKIE = 'theme';
    public const VALUES = ['paper', 'sepia', 'night', 'auto'];
    /** Pre-0.5 archives stored light/dark; they map once, at read time. */
    private const LEGACY = ['light' => 'paper', 'dark' => 'night'];

    /** paper|sepia|night, or null when the choice is auto (or the cookie is
     *  absent/garbage): the layout omits data-theme and prefers-color-scheme
     *  decides, so the cookieless pages the static cache stores stay
     *  theme-neutral bytes (StaticCacheTest pins this contract). */
    public static function current(Request $request): ?string
    {
        $t = $request->cookies[self::COOKIE] ?? '';
        $t = self::LEGACY[$t] ?? $t;
        return in_array($t, self::VALUES, true) && $t !== 'auto' ? $t : null;
    }
}
