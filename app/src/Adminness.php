<?php // app/src/Adminness.php
namespace App;

use Kip\Database;
use Kip\Http\Response;
use Kip\Session;

final class Adminness
{
    /** The role enum (finding 10: PUBLIC so app controllers validate input
     *  against it before dispatching through setRole, which stays the only
     *  writer; an uncaught InvalidArgumentException would 500, not 422). */
    public const ROLES = ['member', 'validated_author', 'moderator', 'admin'];

    /** The ONLY write path that may set role or is_admin (phase-1 invariant:
     *  role = 'admin' iff is_admin = 1). */
    public static function setRole(Database $db, int $userId, string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("unknown role {$role}");
        }
        $db->query('UPDATE users SET role = ?, is_admin = ? WHERE id = ?', [$role, $role === 'admin' ? 1 : 0, $userId]);
    }

    /** Fail-closed moderator gate for POST actions: moderator-or-admin row, or a 403 Response.
     *  The password epoch is validated here, once, so every current and future
     *  moderator surface is safe by construction instead of by attribute
     *  discipline (the pentest variant-loop finding). */
    public static function requireModerator(Database $db, Session $session): array|Response
    {
        $id = $session->get('user_id');
        if (!is_int($id)) return new Response('Forbidden', 403);
        if (!(new \Kip\Auth($db, $session))->sessionValid()) return new Response('Forbidden', 403);
        try {
            $row = $db->one('SELECT id, penname, role, is_admin FROM users WHERE id = ?', [$id]);
        } catch (\PDOException) {
            return new Response('Forbidden', 403);
        }
        if ($row === null || !in_array($row['role'], ['moderator', 'admin'], true)) {
            return new Response('Forbidden', 403);
        }
        return $row;
    }

    /** Fail-closed admin gate for POST actions: an admin row with a live
     *  session epoch, or a 403 Response. Same shape and doctrine as
     *  requireModerator: the epoch is validated here, once, so every admin
     *  surface is safe by construction instead of by copy-paste discipline
     *  (the twelve controllers that carried the raw COUNT idiom were the
     *  drift proof; HomeController's render-time isAdmin flag keeps its own
     *  check because Viewer::id has already re-validated the epoch there and
     *  the page's query budget pins it to that one users read). */
    public static function requireAdmin(Database $db, Session $session): array|Response
    {
        $id = $session->get('user_id');
        if (!is_int($id)) return new Response('Forbidden', 403);
        if (!(new \Kip\Auth($db, $session))->sessionValid()) return new Response('Forbidden', 403);
        try {
            $row = $db->one('SELECT id, penname, role, is_admin FROM users WHERE id = ?', [$id]);
        } catch (\PDOException) {
            return new Response('Forbidden', 403);
        }
        if ($row === null || $row['role'] !== 'admin') {
            return new Response('Forbidden', 403);
        }
        return $row;
    }
}
