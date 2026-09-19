<?php // app/src/Repositories/UserRepository.php
namespace App\Repositories;

use Kip\Database;

final class UserRepository
{
    public function __construct(private Database $db) {}

    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public function pennameTaken(string $penname): bool
    {
        return $this->db->one('SELECT 1 AS x FROM users WHERE penname = ? COLLATE NOCASE', [$penname]) !== null;
    }

    /** @return string raw verify token (hex), stored hashed */
    public function createVerification(string $email): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->db->query('DELETE FROM email_verifications WHERE email = ?', [$email]);
        $this->db->query('INSERT INTO email_verifications (email, token_hash, expires_at) VALUES (?, ?, ?)',
            [$email, hash('sha256', $raw), date('c', time() + 86400)]);
        return $raw;
    }

    /** Consume a verification token. True when the account became verified.
     *  An EXPIRED token deletes the never-activated account so the email can re-register. */
    public function consumeVerification(string $rawToken): bool
    {
        $row = $this->db->one('SELECT * FROM email_verifications WHERE token_hash = ?', [hash('sha256', $rawToken)]);
        if ($row === null) return false;
        $this->db->begin();
        try {
            if (strtotime((string) $row['expires_at']) < time()) {
                $this->db->query('DELETE FROM users WHERE email = ? AND email_verified_at IS NULL', [$row['email']]);
                $this->db->query('DELETE FROM email_verifications WHERE email = ?', [$row['email']]);
                $this->db->commit();
                return false;
            }
            $this->db->query('DELETE FROM email_verifications WHERE email = ?', [$row['email']]);
            $this->db->query('UPDATE users SET email_verified_at = ? WHERE email = ? AND email_verified_at IS NULL',
                [date('c'), $row['email']]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Register under a mode. Returns [ok, error]. */
    public function register(string $mode, string $penname, string $email, string $password, string $inviteCode): array
    {
        // A taken penname belongs to someone regardless of charset: NOCASE-collision wins
        // over format validation (grandfathered and future-imported pennames can be exotic).
        if ($this->pennameTaken($penname)) {
            return [false, 'Penname is already taken.'];
        }
        if (!preg_match('/^[a-z0-9_-]{3,30}$/', $penname)) {
            return [false, 'Penname must be 3-30 characters: lowercase letters, digits, dash, underscore.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'That email address is not valid.'];
        }
        if (strlen($password) < 8) {
            return [false, 'Password must be at least 8 characters.'];
        }
        if ($this->findByEmail($email) !== null) {
            return [false, 'Email is already registered.'];
        }
        $invite = null;
        if ($mode === 'invite') {
            $invite = $this->db->one('SELECT * FROM invites WHERE code = ? AND used_by IS NULL', [$inviteCode]);
            if ($invite === null) return [false, 'Invalid invite code.'];
        }
        $now = date('c');
        $verified = $mode === 'verify' ? null : $now;
        $approved = $mode === 'approval' ? null : $now;
        $this->db->begin();
        try {
            $this->db->query(
                'INSERT INTO users (email, password_hash, penname, role, email_verified_at, approved_at) VALUES (?, ?, ?, \'member\', ?, ?)',
                [$email, password_hash($password, PASSWORD_DEFAULT), $penname, $verified, $approved]);
            $userId = (int) $this->db->lastInsertId();
            $this->db->query('INSERT INTO user_prefs (user_id) VALUES (?)', [$userId]);
            if ($invite !== null) {
                $this->db->query('UPDATE invites SET used_by = ?, used_at = ? WHERE id = ? AND used_by IS NULL',
                    [$userId, $now, $invite['id']]);
                $check = $this->db->one('SELECT used_by FROM invites WHERE id = ?', [$invite['id']]);
                if ($check === null || (int) $check['used_by'] !== $userId) {
                    throw new \RuntimeException('invite consumed concurrently');
                }
            }
            $this->db->commit();
            return [true, ''];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
