<?php // app/src/SessionGuard.php
namespace App;

use Kip\{Auth, Database, Session};

/** Kip's own session check compares only the password epoch. This one reads
 *  the same single users row (same query count) and also refuses a member
 *  an admin has since locked or deleted (delete scrubs the row and locks it),
 *  signing them out on the spot. Used by Viewer and the Adminness gates. */
final class SessionGuard
{
    /** The kernel does the same epoch compare, so this stands in for Auth::sessionValid(). */
    public static function valid(Database $db, Session $session): bool
    {
        $id = $session->get('user_id');
        $epoch = $session->get('pwd_epoch');
        if ($id === null || !is_string($epoch)) return false;
        try {
            $user = $db->one('SELECT password_hash, is_locked FROM users WHERE id = ?', [$id]);
        } catch (\PDOException) {
            return false;
        }
        if ($user === null || !hash_equals(substr((string) $user['password_hash'], 0, Auth::EPOCH_LEN), $epoch)) return false;
        if ((int) $user['is_locked'] === 1) {
            (new Auth($db, $session))->logout();
            return false;
        }
        return true;
    }
}
