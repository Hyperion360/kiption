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

    private static string $role = '';

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
        self::$role = '';
        $id = $session->get('user_id');
        $epoch = $session->get('pwd_epoch');
        if ($id === null || !is_string($epoch)) return false;
        try {
            $user = $db->one('SELECT password_hash, is_locked, role FROM users WHERE id = ?', [$id]);
        } catch (\PDOException) {
            return false;
        }
        if ($user === null || !hash_equals(substr((string) $user['password_hash'], 0, Auth::EPOCH_LEN), $epoch)) return false;
        if ((int) $user['is_locked'] === 1) {
            (new Auth($db, $session))->logout();
            return false;
        }
        self::$role = (string) $user['role'];
        return true;
    }
}
