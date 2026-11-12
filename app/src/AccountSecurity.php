<?php // app/src/AccountSecurity.php
namespace App;

use Kip\Database;

/** The sensitive self-service account changes: password, email, deletion.
 *  Every one starts by re-checking the member's current password, behind a
 *  throttle, so a stolen session alone cannot take the account over. Methods
 *  return a language key for the refusal, or null on success. */
final class AccountSecurity
{
    public const THROTTLED = 'account.err_throttled';

    public function __construct(private Database $db, private AttemptLimiter $limiter) {}

    /** Re-check the current password. Returns null when it is right, or the
     *  lang key of the refusal. A wrong try spends a slot; a right one clears them. */
    public function checkPassword(int $userId, string $password, string $ip): ?string
    {
        $key = 'u' . $userId;
        if (!$this->limiter->claim('reauth', $key, $ip, 5, 30)) return self::THROTTLED;
        $row = $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        $hash = $row === null ? '' : (string) $row['password_hash'];
        if ($hash === '' || !password_verify($password, $hash)) return 'account.err_wrong_password';
        $this->limiter->clear('reauth', $key);
        return null;
    }

    /** Set a new password. The hash rewrite moves the session epoch, which
     *  ends every other signed-in session and voids pending sign-in links. */
    public function changePassword(int $userId, string $current, string $new, string $confirm, string $ip): ?string
    {
        if (($err = $this->checkPassword($userId, $current, $ip)) !== null) return $err;
        if (strlen($new) < 8) return 'account.err_password_short';
        if ($new !== $confirm) return 'account.err_password_mismatch';
        $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId]);
        $this->db->query('DELETE FROM password_resets WHERE email = (SELECT email FROM users WHERE id = ?)', [$userId]);
        return null;
    }

    public const EMAIL_TTL = 86400;

    /** Start an email change: the password is re-checked, the new address must
     *  be valid and free, and a one-time token is stored (hashed) for the link
     *  mailed to the NEW address. Returns [lang key of the refusal or null,
     *  raw token or null]. The address on the account does not change here. */
    public function requestEmailChange(int $userId, string $newEmail, string $password, string $ip): array
    {
        if (($err = $this->checkPassword($userId, $password, $ip)) !== null) return [$err, null];
        $newEmail = trim($newEmail);
        if ($newEmail === '' || strlen($newEmail) > 254 || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) return ['account.err_email_invalid', null];
        $me = $this->db->one('SELECT email FROM users WHERE id = ?', [$userId]);
        if ($me === null) return ['account.err_wrong_password', null];
        if (strcasecmp((string) $me['email'], $newEmail) === 0) return ['account.err_email_same', null];
        if ($this->emailTaken($newEmail, $userId)) return ['account.err_email_taken', null];
        $token = bin2hex(random_bytes(32));
        $this->db->query('INSERT OR REPLACE INTO email_changes (user_id, new_email, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $newEmail, hash('sha256', $token), date('c', time() + self::EMAIL_TTL), date('c')]);
        return [null, $token];
    }

    /** Whether the mailed link would still change an address. Read only, so a
     *  mail scanner that prefetches the link cannot burn it. */
    public function peekEmailChange(string $token): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) return false;
        $row = $this->db->one('SELECT expires_at FROM email_changes WHERE token_hash = ?', [hash('sha256', $token)]);
        return $row !== null && strtotime((string) $row['expires_at']) >= time();
    }

    /** Use the link: burns the token (the deleted row is the single-use
     *  proof), re-checks the address is still free, then swaps it in.
     *  Returns ['old' => ..., 'new' => ...] or the lang key of the refusal. */
    public function applyEmailChange(string $token): array|string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) return 'account.email_link_expired';
        $hash = hash('sha256', $token);
        $this->db->begin();
        try {
            $row = $this->db->one('SELECT user_id, new_email, expires_at FROM email_changes WHERE token_hash = ?', [$hash]);
            $burned = $row === null ? 0 : $this->db->query('DELETE FROM email_changes WHERE token_hash = ?', [$hash])->rowCount();
            if ($burned !== 1 || strtotime((string) $row['expires_at']) < time()) {
                $this->db->commit();
                return 'account.email_link_expired';
            }
            $id = (int) $row['user_id'];
            if ($this->emailTaken((string) $row['new_email'], $id)) {
                $this->db->commit();
                return 'account.email_link_taken';
            }
            $old = (string) $this->db->one('SELECT email FROM users WHERE id = ?', [$id])['email'];
            // Opening a link mailed to the new address proves it is theirs.
            $this->db->query('UPDATE users SET email = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?',
                [$row['new_email'], date('c'), $id]);
            // Anything still waiting on the old address dies with it.
            $this->db->query('DELETE FROM password_resets WHERE email = ?', [$old]);
            $this->db->query('DELETE FROM email_verifications WHERE email = ?', [$old]);
            $this->db->commit();
            return ['old' => $old, 'new' => (string) $row['new_email']];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function emailTaken(string $email, int $exceptUserId): bool
    {
        return $this->db->one('SELECT 1 AS x FROM users WHERE LOWER(email) = LOWER(?) AND id != ?', [$email, $exceptUserId]) !== null
            || $this->db->one('SELECT 1 AS x FROM email_verifications WHERE LOWER(email) = LOWER(?)', [$email]) !== null;
    }

    /** The reserved address of the placeholder account that keeps the work of
     *  members who delete their account but leave their stories up. */
    public const ANON_EMAIL = 'anonymous@kiption.invalid';

    /** Whether this member is the only admin left (a locked admin cannot act,
     *  so it does not count as one). */
    public function isLastAdmin(int $userId): bool
    {
        $me = $this->db->one('SELECT role FROM users WHERE id = ?', [$userId]);
        if ($me === null || $me['role'] !== 'admin') return false;
        return $this->db->one("SELECT 1 AS x FROM users WHERE role = 'admin' AND is_locked = 0 AND id != ?", [$userId]) === null;
    }

    /** Delete the member's account after a password re-check. $keepStories
     *  true moves their stories and series to a placeholder "Anonymous"
     *  account; false lets the database cascade remove them. Returns the
     *  lang key of the refusal, or null once it is done. */
    public function deleteAccount(int $userId, string $password, bool $keepStories, string $ip): ?string
    {
        if (($err = $this->checkPassword($userId, $password, $ip)) !== null) return $err;
        if ($this->isLastAdmin($userId)) return 'account.err_last_admin';
        $this->db->begin();
        try {
            if ($keepStories) {
                $anon = $this->anonymousId();
                $this->db->query('UPDATE stories SET author_id = ? WHERE author_id = ?', [$anon, $userId]);
                $this->db->query('UPDATE series SET owner_id = ? WHERE owner_id = ?', [$anon, $userId]);
            }
            $this->db->query('DELETE FROM password_resets WHERE email = (SELECT email FROM users WHERE id = ?)', [$userId]);
            $this->db->query('DELETE FROM email_verifications WHERE email = (SELECT email FROM users WHERE id = ?)', [$userId]);
            $this->db->query('DELETE FROM users WHERE id = ?', [$userId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return null;
    }

    /** The placeholder account, made on first use. It can never sign in (the
     *  hash is not a valid hash, the address cannot receive mail) and is not
     *  verified or approved, so it stays out of the member directory. */
    private function anonymousId(): int
    {
        $row = $this->db->one('SELECT id FROM users WHERE email = ?', [self::ANON_EMAIL]);
        if ($row !== null) return (int) $row['id'];
        $name = 'Anonymous';
        for ($n = 2; $this->db->one('SELECT 1 AS x FROM users WHERE penname = ? OR profile_slug = ?', [$name, strtolower(str_replace(' ', '-', $name))]) !== null; $n++) {
            $name = 'Anonymous ' . $n;
        }
        $this->db->query('INSERT INTO users (email, password_hash, penname, profile_slug) VALUES (?, ?, ?, ?)',
            [self::ANON_EMAIL, '!', $name, strtolower(str_replace(' ', '-', $name))]);
        return (int) $this->db->lastInsertId();
    }
}
