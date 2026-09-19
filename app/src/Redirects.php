<?php // app/src/Redirects.php
namespace App;

final class Redirects
{
    /** Only same-site absolute paths may become redirect targets: must start
     *  with '/', must not start with '//' or '/\'. */
    public static function safeReturn(string $to): string
    {
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || str_starts_with($to, '/\\')) {
            return '/';
        }
        if (str_contains($to, "\r") || str_contains($to, "\n")) { // header injection
            return '/';
        }
        return $to;
    }
}
