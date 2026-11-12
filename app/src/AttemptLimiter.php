<?php // app/src/AttemptLimiter.php
namespace App;

use Kip\Database;

/** A small shared throttle for the actions that are not a login: re-checking
 *  a password before a sensitive change, and the public contact form. It uses
 *  the same login_attempts table and the same one-statement claim as the
 *  sign-in link (App\LoginLinks), under its own `kind`, so one kind's flood
 *  never blocks another. The window is 15 minutes: Kip\Auth prunes every row
 *  older than that on each login, so a longer window would not hold. */
final class AttemptLimiter
{
    private const WINDOW_SECONDS = 900;

    public function __construct(private Database $db) {}

    /** Claim one slot; false when the key or the IP is already at its cap. The
     *  caps are inlined as integers inside the subqueries: the PDO sqlite
     *  driver ignores a bound parameter in the outer WHERE of INSERT...SELECT. */
    public function claim(string $kind, string $key, string $ip, int $maxPerKey, int $maxPerIp): bool
    {
        $since = date('c', time() - self::WINDOW_SECONDS);
        $this->db->query('DELETE FROM login_attempts WHERE kind = ? AND julianday(attempted_at) <= julianday(?)', [$kind, $since]);
        return $this->db->query(
            "INSERT INTO login_attempts (kind, email, ip, attempted_at)
             SELECT ?, ?, ?, ?
             WHERE (SELECT COUNT(*) < " . max(1, $maxPerKey) . " FROM login_attempts
                    WHERE kind = ? AND email = ? AND julianday(attempted_at) > julianday(?))
               AND (SELECT COUNT(*) < " . max(1, $maxPerIp) . " FROM login_attempts
                    WHERE kind = ? AND ip = ? AND julianday(attempted_at) > julianday(?))",
            [$kind, $key, $ip, date('c'), $kind, $key, $since, $kind, $ip, $since]
        )->rowCount() === 1;
    }

    /** Forget a key's slots after a success. */
    public function clear(string $kind, string $key): void
    {
        $this->db->query('DELETE FROM login_attempts WHERE kind = ? AND email = ?', [$kind, $key]);
    }
}
