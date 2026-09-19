<?php // app/src/Adminness.php
namespace App;

use Kip\Database;
use Kip\Http\Response;
use Kip\Session;

final class Adminness
{
    private const ROLES = ['member', 'validated_author', 'moderator', 'admin'];

    /** The ONLY write path that may set role or is_admin (phase-1 invariant:
     *  role = 'admin' iff is_admin = 1). */
    public static function setRole(Database $db, int $userId, string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("unknown role {$role}");
        }
        $db->query('UPDATE users SET role = ?, is_admin = ? WHERE id = ?', [$role, $role === 'admin' ? 1 : 0, $userId]);
    }

    /** Fail-closed moderator gate for POST actions: moderator-or-admin row, or a 403 Response. */
    public static function requireModerator(Database $db, Session $session): array|Response
    {
        $id = $session->get('user_id');
        if (!is_int($id)) return new Response('Forbidden', 403);
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
}
