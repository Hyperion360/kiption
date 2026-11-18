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
     *  count, plus the two numbers the account menu shows: stories in
     *  progress in the Library (the same rows the account page lists) and the
     *  member's own stories. Scalar subqueries on indexed columns keep it one statement, and
     *  tests exempt it from the content-query budget by comparing to this
     *  constant. */
    public const SQL = "SELECT password_hash, is_locked, role, penname, profile_slug, email,
        (SELECT COUNT(*) FROM notifications WHERE user_id = users.id AND read_at IS NULL) AS unread_notes,
        (SELECT COUNT(*) FROM messages WHERE recipient_id = users.id AND read_at IS NULL) AS unread_msgs,
        CASE WHEN role IN ('admin', 'moderator') THEN
            (SELECT COUNT(*) FROM stories s WHERE " . \App\Repositories\AuthoringRepository::WAITING_STORY . ")
          + (SELECT COUNT(*) FROM chapters ch JOIN stories s ON s.id = ch.story_id WHERE " . \App\Repositories\AuthoringRepository::WAITING_CHAPTER . ")
          + (SELECT COUNT(*) FROM reports WHERE resolved_at IS NULL)
          + CASE WHEN role = 'admin' THEN (SELECT COUNT(*) FROM users us WHERE " . \App\Repositories\AuthoringRepository::WAITING_MEMBER . ") ELSE 0 END
        ELSE 0 END AS waiting,
        (SELECT COUNT(*) FROM reading_history rh JOIN stories rs ON rs.id = rh.story_id
          WHERE rh.user_id = users.id AND rh.marked_at IS NULL AND rs.deleted_at IS NULL) AS in_progress,
        (SELECT COUNT(*) FROM stories ms WHERE ms.author_id = users.id AND ms.deleted_at IS NULL) AS my_stories
        FROM users WHERE id = ?";

    private static string $role = '';
    private static string $penname = '';
    private static string $slug = '';
    private static string $email = '';
    private static int $notes = 0;
    private static int $msgs = 0;
    private static int $waiting = 0;
    private static int $progress = 0;
    private static int $mine = 0;

    public static function penname(): string { return self::$penname; }
    public static function profileSlug(): string { return self::$slug; }
    /** The capital letter the avatar chip shows: the pen name's first letter,
     *  or the sign-in email's when the member has no pen name yet. Empty when signed out. */
    public static function initial(): string
    {
        $name = self::$penname !== '' ? self::$penname : self::$email;
        return $name === '' ? '' : mb_strtoupper(mb_substr($name, 0, 1));
    }
    public static function unreadNotifications(): int { return self::$notes; }
    public static function unreadMessages(): int { return self::$msgs; }
    /** Library stories the member is part-way through (account menu note). */
    public static function inProgress(): int { return self::$progress; }
    /** Stories the member has written, not counting deleted ones. */
    public static function ownStories(): int { return self::$mine; }
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
        self::$role = self::$penname = self::$slug = self::$email = '';
        self::$notes = self::$msgs = self::$waiting = self::$progress = self::$mine = 0;
        $id = $session->get('user_id');
        $epoch = $session->get('pwd_epoch');
        if ($id === null || !is_string($epoch)) return false;
        try {
            $user = $db->one(self::SQL, [$id]);
        } catch (\PDOException $e) {
            // A schema still catching up mid-deploy reads as signed out. Anything else (a busy or locked
            // file) fails the request instead, so a passing hiccup never ends a member's session.
            if (preg_match('/no such (?:table|column)/', $e->getMessage()) === 1) return false;
            throw $e;
        }
        if ($user === null || !hash_equals(substr((string) $user['password_hash'], 0, Auth::EPOCH_LEN), $epoch)) return false;
        if ((int) $user['is_locked'] === 1) {
            (new Auth($db, $session))->logout();
            return false;
        }
        self::$role = (string) $user['role'];
        self::$penname = (string) ($user['penname'] ?? '');
        self::$slug = (string) ($user['profile_slug'] ?? '');
        self::$email = (string) ($user['email'] ?? '');
        self::$notes = (int) $user['unread_notes'];
        self::$msgs = (int) $user['unread_msgs'];
        self::$waiting = (int) $user['waiting'];
        self::$progress = (int) $user['in_progress'];
        self::$mine = (int) $user['my_stories'];
        return true;
    }
}
