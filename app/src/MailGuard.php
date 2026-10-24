<?php // app/src/MailGuard.php
namespace App;

use Kip\Database;
use Kip\Mailer;

/** The daily mail quota guard (table mail_sends, migration 044; config
 *  'mail_daily_cap', 0 = off, the default). Wraps the transport so every
 *  outbound path (verification, password resets, the publish notification
 *  fan-out, the digest, member contact, coauthor adds, mail:users) counts
 *  against the provider's daily cap: a free tier that rejects at 300/day
 *  would otherwise eat a verification mail mid-fan-out. Critical mail
 *  (address verification, password reset: pass MailGuard::CRITICAL from the
 *  call site) always sends; non-critical mail is skipped once the counter
 *  reaches cap - RESERVE, with the reason in the error log (the existing
 *  idiom). A skipped mail writes no counter row, so it never spends quota. */
final class MailGuard
{
    /** Pass to send() for mail that must go out even at the cap. */
    public const CRITICAL = true;

    /** Slots held open at the cap for critical mail (a verification or reset
     *  would otherwise be eaten by the morning digest run). */
    public const RESERVE = 10;

    /** Critical mail counts too (the provider sees it), unconditionally. */
    private const CRITICAL_UPSERT = 'INSERT INTO mail_sends (day, sent) VALUES (?, 1)'
        . ' ON CONFLICT (day) DO UPDATE SET sent = sent + 1'
        . ' RETURNING sent';

    /** Non-critical mail is admitted only while the after-count stays at or
     *  below cap - RESERVE: the SELECT arm's WHERE gates the day's first
     *  send, the DO UPDATE arm's WHERE (it sees the pre-update row) gates
     *  the rest. A refused arm returns no row and writes nothing. The
     *  CASTs pin the numeric compare: PDO binds every parameter as TEXT,
     *  and a bare `1 <= ?` would order by SQLite's type rules (any INTEGER
     *  sorts below any TEXT, the comparison is always true). */
    private const GATED_UPSERT = 'INSERT INTO mail_sends (day, sent) SELECT ?, 1 WHERE 1 <= CAST(? AS INTEGER)'
        . ' ON CONFLICT (day) DO UPDATE SET sent = sent + 1 WHERE mail_sends.sent < CAST(? AS INTEGER)'
        . ' RETURNING sent';

    private const PRUNE = 'DELETE FROM mail_sends WHERE day < ?';

    public function __construct(private Database $db, private Mailer $mailer, private \Kip\App $app) {}

    /** A guard with no cap, for CLI and test callers that already hold a
     *  Mailer: the send path just delegates. */
    public static function off(Database $db, Mailer $mailer): self
    {
        $app = new \Kip\App(['app_dir' => dirname(__DIR__) . '/app', 'env' => 'prod',
            'db' => ['dsn' => 'sqlite::memory:'], 'mail_daily_cap' => 0]);
        return new self($db, $mailer, $app);
    }

    /** The cap from the merged config (config.php + getenv + settings
     *  overlay), read once per instance. The container autowires Database,
     *  Mailer, and App (self-bound), so controllers type-hint App\MailGuard
     *  and the guard resolves with zero wiring. */
    private function dailyCap(): int
    {
        return max(0, (int) $this->app->config('mail_daily_cap', 0));
    }

    /** Consult and increment in ONE statement (the rate_limits counting
     *  upsert), so a send attempt pays exactly one query in the steady
     *  state; the day's first counted send adds one prune DELETE (the same
     *  idiom). Cap 0 skips the database entirely and just sends. Returns
     *  whether the mail went out. A transport failure after a counted
     *  admission stays counted: conservative toward the provider's cap, and
     *  the catch at the call site is unchanged. */
    public function send(string $to, string $subject, string $body, bool $critical = false): bool
    {
        $dailyCap = $this->dailyCap();
        if ($dailyCap < 1) {
            $this->mailer->send($to, $subject, $body);
            return true;
        }
        $day = gmdate('Y-m-d');
        $reserveAt = $dailyCap - self::RESERVE;
        $row = $this->db->one(
            $critical ? self::CRITICAL_UPSERT : self::GATED_UPSERT,
            $critical ? [$day] : [$day, $reserveAt, $reserveAt]
        );
        if ($row === null) {
            // The recipient address never rides the log (the defer rule); the
            // subject names the dropped path without it.
            error_log(sprintf('mail quota: daily cap %d reached, skipped non-critical mail (%s)', $dailyCap, $subject));
            return false;
        }
        if ((int) $row['sent'] === 1) { // the day's first counted send retires old days
            $this->db->query(self::PRUNE, [gmdate('Y-m-d', time() - 7 * 86400)]);
        }
        $this->mailer->send($to, $subject, $body);
        return true;
    }
}
