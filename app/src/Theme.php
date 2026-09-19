<?php // app/src/Theme.php
namespace App;
use Kip\Http\Request;

final class Theme
{
    public const COOKIE = 'theme';

    /** null = no preference cookie: the layout omits data-theme and CSS
     *  prefers-color-scheme decides, so OS-light visitors get light. */
    public static function current(Request $request): ?string
    {
        $t = $request->cookies[self::COOKIE] ?? '';
        return $t === 'light' ? 'light' : ($t === 'dark' ? 'dark' : null);
    }
}
