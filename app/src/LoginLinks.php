<?php // app/src/LoginLinks.php
namespace App;

use Kip\Auth;
use Kip\Database;

/** One-time sign-in links. The emailed token is 32 random bytes in hex; only
 *  its sha256 is stored. A link is single use, expires, and dies with a
 *  password change (the stored hash prefix stops matching). One live link per
 *  account: minting deletes the rest. */
final class LoginLinks
{
    public const REQUEST_TTL = 1800;          // self-requested links: 30 minutes
    public const OPERATOR_TTL = 86400;        // bin/kip auth:link default: 24 hours
    public const MAX_TTL = 7 * 86400;
    private const WINDOW_MINUTES = 15;
    private const MAX_PER_ACCOUNT = 3;
    private const MAX_PER_IP = 10;

    public function __construct(private Database $db) {}

    public function mint(int $userId, int $ttl): string
    {
        $ttl = max(60, min($ttl, self::MAX_TTL));
        $user = $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if ($user === null) throw new \InvalidArgumentException("No user {$userId}");
        $token = bin2hex(random_bytes(32));
        $this->db->begin();
        try {
            $this->db->query('DELETE FROM login_links WHERE user_id = ?', [$userId]);
            $this->db->query('INSERT INTO login_links (user_id, token_hash, pwd_epoch, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
                [$userId, hash('sha256', $token), substr((string) $user['password_hash'], 0, Auth::EPOCH_LEN), date('c', time() + $ttl), date('c')]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $token;
    }

    /** Whether the link would sign someone in right now. Read only. */
    public function peek(string $token): bool
    {
        if (!self::wellFormed($token)) return false;
        $row = $this->row(hash('sha256', $token));
        return $row !== null && self::live($row);
    }

    /** Burn the link and return its user row, or null when it is unknown,
     *  used, expired, or older than the current password. */
    public function consume(string $token): ?array
    {
        if (!self::wellFormed($token)) return null;
        $hash = hash('sha256', $token);
        $this->db->begin();
        try {
            $row = $this->row($hash);
            // The deleted row count is the single-use proof: of two parallel
            // submits only one deletes the row.
            $burned = $row === null ? 0 : $this->db->query('DELETE FROM login_links WHERE token_hash = ?', [$hash])->rowCount();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if ($burned !== 1 || !self::live($row)) return null;
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
    }

    /** A rate-limited self-service request. Claims a throttle slot whether or
     *  not the address has an account; returns the token only when it does. */
    public function request(string $email, string $ip): ?string
    {
        $email = mb_strtolower(trim($email));
        // No real address is longer (RFC 5321); refuse before it reaches the attempts table.
        if ($email === '' || strlen($email) > 254) return null;
        if (!$this->claim($email, $ip)) return null;
        $user = $this->db->one('SELECT id FROM users WHERE lower(email) = ?', [$email]);
        return $user === null ? null : $this->mint((int) $user['id'], self::REQUEST_TTL);
    }

    public static function url(string $baseUrl, string $token): string
    {
        return rtrim($baseUrl, '/') . '/auth/magic?t=' . $token;
    }

    private function row(string $hash): ?array
    {
        return $this->db->one('SELECT l.user_id, l.expires_at, l.pwd_epoch, u.password_hash
            FROM login_links l JOIN users u ON u.id = l.user_id WHERE l.token_hash = ?', [$hash]);
    }

    /** Kip\Auth::claim()'s atomic shape with kind = 'link': caps inlined as
     *  literals inside the subqueries (the PDO sqlite driver ignores a bound
     *  parameter in the outer WHERE of an INSERT...SELECT). */
    private function claim(string $email, string $ip): bool
    {
        $since = date('c', time() - self::WINDOW_MINUTES * 60);
        $this->db->query("DELETE FROM login_attempts WHERE kind = 'link' AND julianday(attempted_at) <= julianday(?)", [$since]);
        return $this->db->query(
            "INSERT INTO login_attempts (kind, email, ip, attempted_at)
             SELECT 'link', ?, ?, ?
             WHERE (SELECT COUNT(*) < " . self::MAX_PER_ACCOUNT . " FROM login_attempts
                    WHERE kind = 'link' AND email = ? AND julianday(attempted_at) > julianday(?))
               AND (SELECT COUNT(*) < " . self::MAX_PER_IP . " FROM login_attempts
                    WHERE kind = 'link' AND ip = ? AND julianday(attempted_at) > julianday(?))",
            [$email, $ip, date('c'), $email, $since, $ip, $since]
        )->rowCount() === 1;
    }

    private static function wellFormed(string $token): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $token) === 1;
    }

    private static function live(array $row): bool
    {
        return strtotime((string) $row['expires_at']) >= time()
            && hash_equals((string) $row['pwd_epoch'], substr((string) $row['password_hash'], 0, Auth::EPOCH_LEN));
    }
}
