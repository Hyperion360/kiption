<?php // app/src/MailGuard.php
namespace App;

use Kip\Database;
use Kip\Mailer;

/** The mail quota guard (table mail_sends, migration 044; config
 *  'mail_daily_cap' and the optional 'mail_monthly_cap', 0 = off, the
 *  default). Wraps the transport so every outbound path (verification,
 *  password resets, the publish notification fan-out, the digest, member
 *  contact, coauthor adds, mail:users) counts against a hosting operator's
 *  caps: a free tier that rejects at 300/day would otherwise eat a
 *  verification mail mid-fan-out.
 *
 *  Two counters live in the one table, on UTC days:
 *  - bulk mail (everything non-critical) keys rows 'YYYY-MM-DD' and stops at
 *    dailyCap - RESERVE, plus the optional monthly cap with no reserve;
 *  - critical mail (address verification, password reset, sign-in link,
 *    email change: pass MailGuard::CRITICAL from the call site) keys rows
 *    'c:YYYY-MM-DD' and stops at dailyCap, in its own bucket, so a flood on
 *    a public form can neither eat the broadcast budget nor send unbounded
 *    verification mail.
 *  A skipped mail writes no counter row, so it never spends quota. The
 *  reason lands in the error log (the existing idiom); the subject rides
 *  along, the recipient address never does. */
final class MailGuard
{
    /** Pass to send() for mail that must go out even at the cap. */
    public const CRITICAL = true;

    /** Slots held open at the daily cap (a verification or reset would
     *  otherwise be eaten by the morning digest run). Bulk mail stops here;
     *  the critical bucket does not need it, it has its own ceiling. */
    public const RESERVE = 10;

    /** Critical mail counts in its own daily bucket, ceiling = the daily
     *  cap. The CASTs pin the numeric compare: PDO binds every parameter as
     *  TEXT, and a bare `1 <= ?` would order by SQLite's type rules (any
     *  INTEGER sorts below any TEXT, the comparison is always true). */
    private const CRITICAL_UPSERT = 'INSERT INTO mail_sends (day, sent) SELECT ?, 1 WHERE 1 <= CAST(? AS INTEGER)'
        . ' ON CONFLICT (day) DO UPDATE SET sent = sent + 1 WHERE mail_sends.sent < CAST(? AS INTEGER)'
        . ' RETURNING sent';

    /** Non-critical mail is admitted only while the after-count stays at or
     *  below cap - RESERVE: the SELECT arm's WHERE gates the day's first
     *  send, the DO UPDATE arm's WHERE (it sees the pre-update row) gates
     *  the rest. A refused arm returns no row and writes nothing. */
    private const GATED_UPSERT = 'INSERT INTO mail_sends (day, sent) SELECT ?, 1 WHERE 1 <= CAST(? AS INTEGER)'
        . ' ON CONFLICT (day) DO UPDATE SET sent = sent + 1 WHERE mail_sends.sent < CAST(? AS INTEGER)'
        . ' RETURNING sent';

    /** The daily gate plus the monthly one (config 'mail_monthly_cap', 0 =
     *  the plain statement above, no extra cost for installs without it).
     *  The month sum is the bulk rows of this calendar month only: the
     *  bounded range (day >= monthStart AND day < nextMonthStart) excludes
     *  every 'c:' row, so critical mail never eats the monthly budget. The
     *  monthly term appears in BOTH arms, each with its own bound parameters.
     *  The monthly gate has no reserve: a monthly cap of N admits N mails. */
    private const GATED_UPSERT_MONTHLY = 'INSERT INTO mail_sends (day, sent) SELECT ?, 1'
        . ' WHERE 1 <= CAST(? AS INTEGER) AND (SELECT COALESCE(SUM(sent), 0) FROM mail_sends WHERE day >= ? AND day < ?) < CAST(? AS INTEGER)'
        . ' ON CONFLICT (day) DO UPDATE SET sent = sent + 1'
        . ' WHERE mail_sends.sent < CAST(? AS INTEGER) AND (SELECT COALESCE(SUM(sent), 0) FROM mail_sends WHERE day >= ? AND day < ?) < CAST(? AS INTEGER)'
        . ' RETURNING sent';

    /** 'c:...' sorts after every ISO date, so the bulk clause never matches
     *  a critical row; the second clause retires them at the same horizon. */
    private const PRUNE = "DELETE FROM mail_sends WHERE day < ? OR (day LIKE 'c:%' AND day < ?)";

    /** Rows older than this are pruned on the day's first send. 40 days so a
     *  full calendar month of rows survives for the monthly sum. */
    private const PRUNE_DAYS = 40;

    private \Closure $clock;

    /** $clock is the test seam: an optional LAST parameter, defaulted, so
     *  every existing caller and off() stay valid. It returns the current
     *  unix time and every date this class derives reads it. The type is
     *  ?callable on purpose: the container autowires this constructor on
     *  every request, and a ?\Closure parameter would make it try to build
     *  a Closure instance (500 on every page); a builtin type takes the
     *  default and the guard resolves as before. */
    public function __construct(private Database $db, private Mailer $mailer, private \Kip\App $app, ?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): int => time() : \Closure::fromCallable($clock);
    }

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

    /** The optional monthly cap. A config that predates the key reads as 0
     *  (off): an older install runs daily-only until its config gains the
     *  key, never a fatal, never a guessed number. */
    private function monthlyCap(): int
    {
        return max(0, (int) $this->app->config('mail_monthly_cap', 0));
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
        $now = ($this->clock)();
        $day = gmdate('Y-m-d', $now);
        $reserveAt = $dailyCap - self::RESERVE;
        if ($critical) {
            $row = $this->db->one(self::CRITICAL_UPSERT, ['c:' . $day, $dailyCap, $dailyCap]);
        } else {
            $monthlyCap = $this->monthlyCap();
            if ($monthlyCap > 0) {
                $monthStart = gmdate('Y-m-01', $now);
                $nextMonthStart = gmdate('Y-m-01', strtotime('first day of next month', $now));
                $row = $this->db->one(self::GATED_UPSERT_MONTHLY,
                    [$day, $reserveAt, $monthStart, $nextMonthStart, $monthlyCap,
                     $reserveAt, $monthStart, $nextMonthStart, $monthlyCap]);
            } else {
                $row = $this->db->one(self::GATED_UPSERT, [$day, $reserveAt, $reserveAt]);
            }
        }
        if ($row === null) {
            // The recipient address never rides the log (the defer rule); the
            // subject names the dropped path without it.
            error_log($critical
                ? sprintf('mail quota: verification limit %d reached, skipped critical mail (%s)', $dailyCap, $subject)
                : sprintf('mail quota: daily cap %d reached, skipped non-critical mail (%s)', $dailyCap, $subject));
            return false;
        }
        if ((int) $row['sent'] === 1) { // the day's first counted send retires old days
            $cutoff = gmdate('Y-m-d', $now - self::PRUNE_DAYS * 86400);
            $this->db->query(self::PRUNE, [$cutoff, 'c:' . $cutoff]);
        }
        $this->mailer->send($to, $subject, $body);
        return true;
    }

    /** Advisory preflight for the critical bucket, for the one call site
     *  that must decide BEFORE side effects (registration checks it before
     *  it creates the account). One cheap read; the send's own statement
     *  still decides at send time. */
    public function criticalFits(): bool
    {
        $dailyCap = $this->dailyCap();
        if ($dailyCap < 1) return true;
        $row = $this->db->one('SELECT sent FROM mail_sends WHERE day = ?', ['c:' . gmdate('Y-m-d', ($this->clock)())]);
        return (int) ($row['sent'] ?? 0) < $dailyCap;
    }

    /** The one place the cap arithmetic lives: the broadcast screen, the
     *  preview, and the CLI all read it. Bulk only (critical mail has its
     *  own bucket and ceiling). With the guard off the room is unbounded and
     *  no counter query runs at all, so an uncapped page pays nothing.
     *  @return array{today:int,month:int,dailyCap:int,dailyUsable:int,monthlyCap:int,room:int,binding:?string} */
    public function usage(): array
    {
        $dailyCap = $this->dailyCap();
        $monthlyCap = $this->monthlyCap();
        if ($dailyCap < 1) {
            return ['today' => 0, 'month' => 0, 'dailyCap' => 0, 'dailyUsable' => 0,
                'monthlyCap' => $monthlyCap, 'room' => PHP_INT_MAX, 'binding' => null];
        }
        $now = ($this->clock)();
        $today = (int) ($this->db->one('SELECT sent FROM mail_sends WHERE day = ?', [gmdate('Y-m-d', $now)])['sent'] ?? 0);
        $month = (int) ($this->db->one('SELECT COALESCE(SUM(sent), 0) AS s FROM mail_sends WHERE day >= ? AND day < ?',
            [gmdate('Y-m-01', $now), gmdate('Y-m-01', strtotime('first day of next month', $now))])['s'] ?? 0);
        $dailyUsable = max(0, $dailyCap - self::RESERVE);
        $dailyRoom = max(0, $dailyUsable - $today);
        $monthlyRoom = $monthlyCap > 0 ? max(0, $monthlyCap - $month) : PHP_INT_MAX;
        $binding = $monthlyRoom < $dailyRoom ? 'month' : 'day';
        return ['today' => $today, 'month' => $month, 'dailyCap' => $dailyCap,
            'dailyUsable' => $dailyUsable, 'monthlyCap' => $monthlyCap,
            'room' => min($dailyRoom, $monthlyRoom), 'binding' => $binding];
    }
}
