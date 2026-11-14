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
        'approved' => 'admin.saved_approved', 'rejected' => 'admin.saved_rejected', 'moved' => 'admin.saved_moved',
        'turned_on' => 'admin.saved_turned_on', 'turned_off' => 'admin.saved_turned_off',
        'locked' => 'admin.saved_locked', 'unlocked' => 'admin.saved_unlocked',
        'changed_since' => 'admin.saved_changed_since', 'penname' => 'saved.penname',
        'live_soon' => 'saved.live_soon',
    ];

    /** The only actions the line may offer to take back: a member lock or
     *  unlock, and a feature switch set to a named state (on or off), so
     *  pressing Undo twice can never flip it back again. The address may end
     *  in ?from=<state>: the state the Undo expects to find, signed with the
     *  rest, so an Undo can tell that someone has changed the thing since. */
    private const UNDOABLE = '#^/(adminmembers/(lock|unlock)/[0-9]+|features/set/[a-z0-9_]+/(on|off))(\?from=(locked|unlocked|on|off))?$#D';

    /** Redirect to $url with the code attached (before any #fragment). */
    public static function to(string $url, string $code = '1'): Response
    {
        return Response::redirect(self::url($url, $code));
    }

    /** Like to(), and the line also offers an Undo button that posts to
     *  $undo. The address is signed with the owner's own form token, so a
     *  link made by someone else can never put that button on the page. */
    public static function withUndo(string $url, string $code, string $undo, string $token): Response
    {
        $to = self::url($url, $code);
        if (preg_match(self::UNDOABLE, $undo) !== 1) return Response::redirect($to);
        [$base, $fragment] = array_pad(explode('#', $to, 2), 2, null);
        return Response::redirect($base . '&undo=' . rawurlencode($undo) . '&undo_key=' . self::sign($undo, $token)
            . ($fragment === null ? '' : '#' . $fragment));
    }

    /** The address the Undo button posts to on this request, or null. */
    public static function undo(?Request $request, string $token): ?string
    {
        $undo = $request?->get['undo'] ?? null;
        $key = $request?->get['undo_key'] ?? null;
        if (!is_string($undo) || !is_string($key) || $token === '' || preg_match(self::UNDOABLE, $undo) !== 1) return null;
        return hash_equals(self::sign($undo, $token), $key) ? $undo : null;
    }

    /** True when this request is an Undo that expected the thing to be in a
     *  different state than $current (the state it is in now). A plain
     *  request, with no ?from=, is never stale. */
    public static function staleUndo(Request $request, string $current): bool
    {
        $from = $request->get['from'] ?? null;
        return is_string($from) && $from !== $current;
    }

    private static function sign(string $undo, string $token): string
    {
        return substr(hash_hmac('sha256', $undo, $token), 0, 32);
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
