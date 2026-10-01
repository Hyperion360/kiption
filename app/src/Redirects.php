<?php // app/src/Redirects.php
namespace App;

final class Redirects
{
    /** Only same-site absolute paths may become redirect targets. Delegates
     *  to the framework validator (Kip\Redirects::safeReturn, plan kiption-13
     *  B5): leading slash, no protocol-relative spelling, no backslash,
     *  no CR/LF/NUL, no dot segments. Call sites keep this name and the
     *  '/' fallback contract. */
    public static function safeReturn(string $to): string
    {
        return \Kip\Redirects::safeReturn($to);
    }
}
