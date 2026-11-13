<?php // app/src/Saved.php
namespace App;

use Kip\Http\Request;
use Kip\Http\Response;

/** The answer every admin save gives: the redirect after a write carries a
 *  short code (?saved=removed), and the layout prints the matching line
 *  ("Removed.") above the page the owner lands on. The code is one of a
 *  fixed list, so the address can never put its own words on the page. */
final class Saved
{
    /** code => language key of the line shown. "1" is the plain "Saved." */
    public const CODES = [
        '1' => 'admin.saved_saved', 'added' => 'admin.saved_added', 'removed' => 'admin.saved_removed',
        'sent' => 'admin.saved_sent', 'approved' => 'admin.saved_approved', 'rejected' => 'admin.saved_rejected', 'moved' => 'admin.saved_moved',
        'turned_on' => 'admin.saved_turned_on', 'turned_off' => 'admin.saved_turned_off',
    ];

    /** Redirect to $url with the code attached (before any #fragment). */
    public static function to(string $url, string $code = '1'): Response
    {
        return Response::redirect(self::url($url, $code));
    }

    public static function url(string $url, string $code = '1'): string
    {
        if (!isset(self::CODES[$code])) $code = '1';
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        return $base . (str_contains($base, '?') ? '&' : '?') . 'saved=' . $code . ($fragment === null ? '' : '#' . $fragment);
    }

    /** The language key of the line to show for this request, or null. */
    public static function message(?Request $request): ?string
    {
        $code = $request?->get['saved'] ?? null;
        return is_string($code) ? (self::CODES[$code] ?? null) : null;
    }
}
