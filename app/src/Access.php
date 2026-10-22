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

    public static function locked(array $row, string $table, Session $session): bool
    {
        $hash = $row['access_hash'] ?? null;
        if ($hash === null || $hash === '') return false;
        // peek, never get: a plain get() starts the lazy session and emits
        // Set-Cookie for a cookieless guest, so no gate page could ever be
        // cached. A not-started session reads as "no unlocks", still locked.
        return !in_array($table . ':' . (string) $row['id'], $session->peek('access_unlocks') ?? [], true);
    }

    public static function unlock(Session $session, string $table, int $id): void
    {
        $unlocks = $session->get('access_unlocks', []);
        $unlocks[] = $table . ':' . $id;
        $session->set('access_unlocks', array_slice(array_unique($unlocks), -self::CAP));
    }
}
