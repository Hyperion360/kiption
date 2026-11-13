<?php // app/src/ActivityLog.php
namespace App;
use Kip\Database;

/** The owner's activity log: one line per admin action. record() never
 *  throws, so a logging problem cannot undo or block the action itself.
 *  The action is a short dotted key (e.g. "features.toggle"); the detail is
 *  plain text and is escaped on output. */
final class ActivityLog
{
    private const MAX_DETAIL = 300;

    public static function record(Database $db, ?int $actorId, string $action, string $detail = ''): void
    {
        try {
            $name = $actorId === null ? '' : (string) ($db->one('SELECT penname FROM users WHERE id = ?', [$actorId])['penname'] ?? '');
            $db->query('INSERT INTO activity_log (actor_id, actor_name, action, detail) VALUES (?, ?, ?, ?)',
                [$actorId, $name, mb_substr($action, 0, 60), mb_substr($detail, 0, self::MAX_DETAIL)]);
        } catch (\Throwable) {
            // best effort by design
        }
    }

    /** Like record(), but at most one line per action every $minutes, for
     *  events an attacker can repeat at will (a bot hitting a locked sign-in).
     *  Looks only at the newest rows, so the check stays cheap on a big log. */
    public static function recordOnce(Database $db, ?int $actorId, string $action, string $detail = '', int $minutes = 15): void
    {
        try {
            $seen = $db->one("SELECT 1 AS hit FROM (SELECT action, created_at FROM activity_log ORDER BY id DESC LIMIT 50) WHERE action = ? AND created_at > datetime('now', ?)",
                [$action, '-' . $minutes . ' minutes']);
            if ($seen !== null) return;
        } catch (\Throwable) {
            // fall through and record
        }
        self::record($db, $actorId, $action, $detail);
    }

    /** The common case: the signed-in member in $session did it. */
    public static function by(Database $db, \Kip\Session $session, string $action, string $detail = ''): void
    {
        $id = (int) $session->get('user_id', 0);
        self::record($db, $id > 0 ? $id : null, $action, $detail);
    }

    /** @return list<array<string,mixed>> one extra row tells the caller whether another page exists */
    public static function recent(Database $db, int $limit, int $offset): array
    {
        return $db->all('SELECT id, actor_id, actor_name, action, detail, created_at FROM activity_log ORDER BY id DESC LIMIT ? OFFSET ?',
            [$limit + 1, $offset]);
    }
}
