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
