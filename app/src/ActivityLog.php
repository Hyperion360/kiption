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
            if (random_int(1, 200) === 1) self::prune($db);
        } catch (\Throwable) {
            // best effort by design
        }
    }

    /** Entries are kept for one year. record() calls this now and then, so
     *  no scheduled job is needed; the activity page tells owners the rule. */
    public static function prune(Database $db): void
    {
        $db->query("DELETE FROM activity_log WHERE created_at < datetime('now', '-1 year')");
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

    /** The kinds the log can be narrowed to, as the first part of the action
     *  name. 'other' is everything no kind here claims. */
    public const KINDS = [
        'signin' => ['auth'],
        'moderation' => ['queue', 'reports'],
        'members' => ['members', 'invites', 'mailusers'],
        'setup' => ['settings', 'features', 'text', 'wordfilter', 'nav', 'templates', 'categories', 'ratings', 'characters',
            'tags', 'wrangling', 'profilefields', 'signupcheck', 'images', 'news', 'page', 'adminstories'],
        'writing' => ['story', 'chapter', 'series', 'coauthors', 'challenges'],
        'other' => [],
    ];

    /** @return list<array<string,mixed>> one extra row tells the caller whether another page exists.
     *  $who narrows to pennames that start with it; $kind to one of KINDS. */
    public static function recent(Database $db, int $limit, int $offset, string $who = '', string $kind = ''): array
    {
        $where = [];
        $args = [];
        if ($who !== '') {
            $where[] = "actor_name LIKE ? ESCAPE '\\'";
            $args[] = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $who) . '%';
        }
        if (isset(self::KINDS[$kind])) {
            // Only names from the constant reach the statement, never what was typed.
            $like = static fn (array $prefixes): string => implode(' OR ', array_map(static fn (string $p): string => "action LIKE '" . $p . ".%'", $prefixes));
            $where[] = $kind === 'other'
                ? 'NOT (' . $like(array_merge(...array_values(self::KINDS))) . ')'
                : '(' . $like(self::KINDS[$kind]) . ')';
        }
        return $db->all('SELECT id, actor_id, actor_name, action, detail, created_at FROM activity_log'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            [...$args, $limit + 1, $offset]);
    }

    /** Rows (newest first) as days of entries; the same thing done by the
     *  same person several times in a row becomes one entry holding them all.
     *  @param list<array<string,mixed>> $rows
     *  @return array<string, list<list<array<string,mixed>>>> day (Y-m-d) => entries => rows */
    public static function byDay(array $rows): array
    {
        $days = [];
        foreach ($rows as $row) {
            $t = strtotime((string) $row['created_at']);
            $day = $t === false ? '' : date('Y-m-d', $t);
            $last = isset($days[$day]) ? array_key_last($days[$day]) : null;
            if ($last !== null && $days[$day][$last][0]['action'] === $row['action'] && $days[$day][$last][0]['actor_name'] === $row['actor_name']) {
                $days[$day][$last][] = $row;
            } else {
                $days[$day][] = [$row];
            }
        }
        return $days;
    }
}
