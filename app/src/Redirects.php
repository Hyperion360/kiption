<?php // app/src/Redirects.php
namespace App;

final class Redirects
{
    /** Only same-site absolute paths may become redirect targets. Delegates
     *  to the framework validator (Kip\Redirects::safeReturn, plan kiption-13
     *  B5): leading slash, no protocol-relative spelling, no backslash,
     *  no CR/LF/NUL, no dot segments. Call sites keep this name and the
     *  '/' fallback contract.
     *
     *  Two guards ride on top of the framework check (qa-full, /review):
     *  a non-string (?return_to[]=x) falls back instead of raising a
     *  TypeError 500, and any control character, space or DEL is refused.
     *  Browsers strip tab and newline from a Location URL, so /<TAB>/evil
     *  passed the framework's leading-slash rules and landed on //evil.
     *  The same character guard belongs in Kip\Redirects upstream. */
    public static function safeReturn(mixed $to): string
    {
        if (!is_string($to) || preg_match('/[\x00-\x20\x7f]/', $to) === 1) {
            return '/';
        }
        return \Kip\Redirects::safeReturn($to);
    }
}
