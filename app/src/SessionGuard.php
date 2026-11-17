<?php // app/src/SessionGuard.php
namespace App;

use Kip\{Auth, Database, Session};

/** Kip's own session check compares only the password epoch. This one reads
 *  the same single users row (same query count) and also refuses a member
 *  an admin has since locked or deleted (delete scrubs the row and locks it),
 *  signing them out on the spot. Used by Viewer and the Adminness gates. */
final class SessionGuard
{
    /** The per-request hook for every signed-in member: public/index.php calls
     *  it before the app runs, so a locked or deleted member is signed out on
     *  any page, #[Auth] ones included. A guest (no user_id) costs nothing. */
    public static function enforce(Database $db, Session $session): void
    {
        if ($session->get('user_id') !== null) self::valid($db, $session);
    }

    /** The one session-check statement. Beyond the password epoch, lock flag
     *  and role it carries what the site header shows beside a signed-in name:
     *  pen name, profile slug, unread Inbox counts, and for staff the waiting
     *  count. Scalar subqueries on indexed columns keep it one statement, and
     *  tests exempt it from the content-query budget by comparing to this
     *  constant. */
    public const SQL = "SELECT password_hash, is_locked, role, penname, profile_slug,
        (SELECT COUNT(*) FROM notifications WHERE user_id = users.id AND read_at IS NULL) AS unread_notes,
        (SELECT COUNT(*) FROM messages WHERE recipient_id = users.id AND read_at IS NULL) AS unread_msgs,
        CASE WHEN role IN ('admin', 'moderator') THEN
            (SELECT COUNT(*) FROM stories s WHERE " . \App\Repositories\AuthoringRepository::WAITING_STORY . ")
          + (SELECT COUNT(*) FROM chapters ch JOIN stories s ON s.id = ch.story_id WHERE " . \App\Repositories\AuthoringRepository::WAITING_CHAPTER . ")
          + (SELECT COUNT(*) FROM reports WHERE resolved_at IS NULL)
          + CASE WHEN role = 'admin' THEN (SELECT COUNT(*) FROM users us WHERE " . \App\Repositories\AuthoringRepository::WAITING_MEMBER . ") ELSE 0 END
        ELSE 0 END AS waiting
        FROM users WHERE id = ?";

    private static string $role = '';
    private static string $penname = '';
    private static string $slug = '';
    private static int $notes = 0;
    private static int $msgs = 0;
    private static int $waiting = 0;

    public static function penname(): string { return self::$penname; }
    public static function profileSlug(): string { return self::$slug; }
    public static function unreadNotifications(): int { return self::$notes; }
    public static function unreadMessages(): int { return self::$msgs; }
    /** Staff only: stories, chapters, reports (and for admins, members) waiting. */
    public static function waiting(): int { return self::$waiting; }

    /** The role of the member the last valid() call approved, '' otherwise. It
     *  rides the row valid() already read, so pages that only show role-based
     *  buttons cost no query. */
    public static function role(): string
    {
        return self::$role;
    }

    /** The kernel does the same epoch compare, so this stands in for Auth::sessionValid(). */
    public static function valid(Database $db, Session $session): bool
    {
        self::$role = self::$penname = self::$slug = '';
        self::$notes = self::$msgs = self::$waiting = 0;
        $id = $session->get('user_id');
        $epoch = $session->get('pwd_epoch');
        if ($id === null || !is_string($epoch)) return false;
        try {
            $user = $db->one(self::SQL, [$id]);
        } catch (\PDOException) {
            return false;
        }
        if ($user === null || !hash_equals(substr((string) $user['password_hash'], 0, Auth::EPOCH_LEN), $epoch)) return false;
        if ((int) $user['is_locked'] === 1) {
            (new Auth($db, $session))->logout();
            return false;
        }
        self::$role = (string) $user['role'];
        self::$penname = (string) ($user['penname'] ?? '');
        self::$slug = (string) ($user['profile_slug'] ?? '');
        self::$notes = (int) $user['unread_notes'];
        self::$msgs = (int) $user['unread_msgs'];
        self::$waiting = (int) $user['waiting'];
        return true;
    }
}
