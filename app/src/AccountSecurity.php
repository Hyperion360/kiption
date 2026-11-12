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
}
