<?php // app/src/Access.php
namespace App;

use Kip\Session;

/** The post-password gate: a story or chapter row may carry access_hash.
 *  The unlock marker lives in the session, so an anonymous visitor always
 *  sees the same byte-identical password form (a shape the static cache
 *  may hold), while any unlocked render carries the session cookie and is
 *  therefore never served from nor stored to the cache. Zero queries: the
 *  hash rides the row the render already loaded. */
final class Access
{
    private const CAP = 100; // one session never collects unbounded unlocks

    /** The marker carries a short fingerprint of the row's CURRENT hash: a
     *  password rotation changes the fingerprint, so every marker minted
     *  under the old hash dies with it (a bare table:id marker would keep a
     *  stale unlock alive across the rotation). */
    private static function marker(string $table, string|int $id, string $hash): string
    {
        return $table . ':' . $id . ':' . substr(md5($hash), 0, 8);
    }

    public static function locked(array $row, string $table, Session $session): bool
    {
        $hash = $row['access_hash'] ?? null;
        if ($hash === null || $hash === '') return false;
        // peek, never get: a plain get() starts the lazy session and emits
        // Set-Cookie for a cookieless guest, so no gate page could ever be
        // cached. A not-started session reads as "no unlocks", still locked.
        return !in_array(self::marker($table, (string) $row['id'], $hash), $session->peek('access_unlocks') ?? [], true);
    }

    public static function unlock(Session $session, string $table, int $id, string $hash): void
    {
        $unlocks = $session->get('access_unlocks', []);
        $unlocks[] = self::marker($table, $id, $hash);
        $session->set('access_unlocks', array_slice(array_unique($unlocks), -self::CAP));
    }
}
