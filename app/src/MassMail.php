<?php // app/src/MassMail.php
namespace App;

use Kip\Database;

/** One mail to every member who may receive it. The recipient rule is the
 *  member directory gate (UserRepository::authorsDirectory): approved,
 *  verified and unlocked members with a penname. Both the mail:users command
 *  and the /mailusers screen go through here, so the two always agree. */
final class MassMail
{
    /** Seconds the whole archive waits between broadcasts from the screen. */
    public const COOLDOWN = 600;

    /** @return list<string> the addresses, oldest member first */
    public static function recipients(Database $db): array
    {
        return array_column($db->all(
            'SELECT email FROM users WHERE penname IS NOT NULL AND is_locked = 0
             AND approved_at IS NOT NULL AND email_verified_at IS NOT NULL ORDER BY id'), 'email');
    }

    /** Send to each recipient once. One bad address never stops the batch
     *  (the Digest idiom). Returns how many were handed to the mail layer. */
    public static function send(MailGuard $mailer, array $recipients, string $subject, string $body): int
    {
        $mailed = 0;
        foreach ($recipients as $email) {
            try {
                if ($mailer->send($email, $subject, $body) === true) $mailed++;
            } catch (\Throwable $e) {
                error_log("mail:users failed for {$email}: " . $e->getMessage());
            }
        }
        return $mailed;
    }

    /** Claim the broadcast slot for the screen: true when no broadcast went
     *  out in the last ten minutes. The check and the claim are one INSERT,
     *  so two admins pressing Send together cannot both pass. */
    public static function claim(Database $db, int $adminId, string $subject, int $recipients): bool
    {
        $stmt = $db->query(
            'INSERT INTO mass_mail_log (admin_id, subject, recipients, sent_at)
             SELECT ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM mass_mail_log WHERE sent_at > ?)',
            [$adminId, $subject, $recipients, gmdate('c'), gmdate('c', time() - self::COOLDOWN)]);
        return $stmt->rowCount() === 1;
    }
}
