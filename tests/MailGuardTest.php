<?php // tests/MailGuardTest.php
namespace App\Tests;
use App\Digest;
use App\MailGuard;
use App\PublishFanout;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class MailGuardTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private string $mailLog = '';
    private string $errLog = '';
    private Database $db;
    private int $fanId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-quota-') . '.sqlite';
        $this->root = tempnam(sys_get_temp_dir(), 'kiption-quota-');
        unlink($this->root);
        mkdir($this->root, 0775, true);
        $this->mailLog = $this->root . '/mail.log';
        $this->errLog = $this->root . '/error.log';
        touch($this->mailLog);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('qf@e.test', ?, 'quotafan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->fanId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO user_prefs (user_id) VALUES (?)', [$this->fanId]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** The register-test harness plus the guard binding public/index.php owns:
     *  a cap in the config only reaches the send path when the container
     *  carries a counted MailGuard, so the client mirrors that seam. */
    private function client(array $overrides = []): TestClient
    {
        $config = array_merge([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'site_name' => 'Kiption',
            'base_url' => 'https://archive.example',
            'signup_min_seconds' => 0, // these tests post the form without loading it first
        ], $overrides);
        return new TestClient(new App($config)); // MailGuard autowires: App reads the cap from the merged config
    }

    private function guard(int $cap): MailGuard
    {
        $app = new App(['app_dir' => dirname(__DIR__) . '/app', 'env' => 'prod',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'mail_daily_cap' => $cap, 'site_name' => 'Kiption', 'base_url' => 'https://archive.example']);
        return $app->container->make(MailGuard::class);
    }

    /** A guard builder with an optional frozen clock, on the log transport,
     *  named so it does not collide with the existing guard(int $cap) helper.
     *  Kip\Mailer is final, so the sends are counted through the real
     *  transport's log file (one From: header per mail), never a double. */
    private function cappedGuard(int $daily, int $monthly = 0, ?\Closure $clock = null): MailGuard
    {
        $config = ['app_dir' => dirname(__DIR__) . '/app', 'env' => 'prod',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'mail_daily_cap' => $daily, 'mail_monthly_cap' => $monthly,
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example'];
        $db = new Database($config['db']['dsn']);
        $mailer = new \Kip\Mailer($config['mail']);
        return new MailGuard($db, $mailer, new App($config), $clock);
    }

    /** Sends in the transport's log, counted the way the existing tests count
     *  them (one From: header per mail). */
    private function sentCount(): int
    {
        return substr_count((string) file_get_contents($this->mailLog), 'From: noreply@localhost');
    }

    /** The critical bucket's counter (rows keyed c:YYYY-MM-DD). $day names the
     *  bucket for a test on a frozen clock; the default is the real UTC day. */
    private function criticalSent(?string $day = null): int
    {
        return (int) ($this->db->one('SELECT sent FROM mail_sends WHERE day = ?', ['c:' . ($day ?? gmdate('Y-m-d'))])['sent'] ?? 0);
    }

    private function mailer(): \Kip\Mailer
    {
        return new \Kip\Mailer(['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost']);
    }

    /** phpunit parks error_log at /dev/null; the skip line is asserted from a
     *  real file, restored whatever happens (the seed/teardown discipline).
     *  The file is touched first, so a wrapped closure that logs nothing
     *  returns '' instead of tripping a read warning. */
    private function withErrorLog(callable $fn): string
    {
        touch($this->errLog);
        ini_set('error_log', $this->errLog);
        try {
            $fn();
        } finally {
            ini_set('error_log', '/dev/null');
        }
        return (string) file_get_contents($this->errLog);
    }

    private function sent(): int
    {
        return (int) ($this->db->one('SELECT sent FROM mail_sends WHERE day = ?', [gmdate('Y-m-d')])['sent'] ?? 0);
    }

    private function seedSent(int $n): void
    {
        $this->db->query('INSERT INTO mail_sends (day, sent) VALUES (?, ?)', [gmdate('Y-m-d'), $n]);
    }

    /** Critical mail counts in its own c: bucket now (Task B1 semantics):
     *  the bulk row stays where the seed put it, and the verification mail
     *  still goes out at the bulk cap. */
    public function test_at_cap_verification_mail_sends_and_increments(): void
    {
        $this->seedSent(12); // the bulk counter sits AT the cap
        $res = $this->client(['registration_mode' => 'verify', 'mail_daily_cap' => 12])
            ->post('/auth/store', ['penname' => 'atcap', 'email' => 'atcap@e.test', 'password' => 'password123', 'confirm' => 'password123']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringContainsString('/auth/verify/', (string) file_get_contents($this->mailLog), 'critical mail went out at the cap');
        $this->assertSame(12, $this->sent(), 'the critical send never touched the bulk row');
        $this->assertSame(1, $this->criticalSent(), 'the critical send counted in its own bucket');
    }

    public function test_at_cap_notification_mail_is_skipped_with_log_line(): void
    {
        $this->seedSent(12);
        $this->db->query("INSERT INTO follows (follower_id, author_id, notify_mode) VALUES (?, 1, 'email')", [$this->fanId]);
        $this->withErrorLog(fn () => (new PublishFanout($this->db, $this->guard(12), 'https://archive.example'))->publish('the-rabbit-hole'));
        $this->assertSame('', (string) file_get_contents($this->mailLog), 'non-critical fan-out mail was dropped');
        $this->assertSame(12, $this->sent(), 'a skipped mail never spends quota');
        $this->assertStringContainsString('mail quota: daily cap 12 reached, skipped non-critical mail', (string) file_get_contents($this->errLog));
    }

    public function test_reserve_boundary_holds_slots_for_critical_mail(): void
    {
        $guard = $this->guard(12); // threshold: 12 - 10 = 2 non-critical sends
        $this->assertTrue($guard->send('a@e.test', 'notification one', 'body'));
        $this->assertTrue($guard->send('a@e.test', 'notification two', 'body'));
        $allowed = true;
        $logged = $this->withErrorLog(function () use ($guard, &$allowed): void {
            $allowed = $guard->send('a@e.test', 'notification three', 'body');
        });
        $this->assertFalse($allowed, 'the send past the reserve line was refused');
        $this->assertSame(2, $this->sent(), 'the refused send wrote nothing');
        $this->assertStringContainsString('skipped non-critical mail', $logged);
        $this->assertTrue($guard->send('a@e.test', 'Verify your account', 'body', MailGuard::CRITICAL), 'critical mail still sends');
        $this->assertSame(2, $this->sent(), 'critical mail lives in its own bucket, not the bulk row');
        $this->assertSame(1, $this->criticalSent(), 'the critical send counted in the c: bucket');
    }

    public function test_below_cap_both_kinds_send(): void
    {
        $this->seedSent(1); // far below the 300 cap
        $guard = $this->guard(300);
        $this->assertTrue($guard->send('a@e.test', 'Story update: X', 'body'));
        $this->assertTrue($guard->send('a@e.test', 'Verify your account', 'body', MailGuard::CRITICAL));
        $this->assertSame(2, $this->sent(), 'bulk counted in the bulk row');
        $this->assertSame(1, $this->criticalSent(), 'critical counted in its own bucket');
        $this->assertSame(2, substr_count((string) file_get_contents($this->mailLog), 'From: noreply@localhost'), 'both mails went out');
    }

    public function test_cap_zero_disables_the_guard_entirely(): void
    {
        $res = $this->client(['registration_mode' => 'verify']) // no mail_daily_cap key: the config default is 0
            ->post('/auth/store', ['penname' => 'nocap', 'email' => 'nocap@e.test', 'password' => 'password123', 'confirm' => 'password123']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringContainsString('/auth/verify/', (string) file_get_contents($this->mailLog));
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM mail_sends")['c'], 'cap 0 never touches the counter table');
    }

    public function test_digest_reports_the_skip_count_and_keeps_the_marker(): void
    {
        $this->db->query("INSERT INTO follows (follower_id, author_id, notify_mode) VALUES (?, 1, 'digest')", [$this->fanId]);
        $this->db->query("INSERT INTO notifications (user_id, kind, story_id, actor_id, story_title) VALUES (?, 'update', 1, 1, 'The Rabbit Hole')", [$this->fanId]);
        $digest = new Digest($this->db, $this->guard(1), 'https://archive.example'); // cap 1: every threshold is below zero
        $mailed = -1;
        $logged = $this->withErrorLog(function () use ($digest, &$mailed): void {
            $mailed = $digest->send();
        });
        $this->assertSame(0, $mailed, 'nothing mailed at the cap');
        $this->assertSame(1, $digest->skipped, 'the skip is reported for the command line');
        $this->assertNull($this->db->one('SELECT digest_sent_at FROM user_prefs WHERE user_id = ?', [$this->fanId])['digest_sent_at'], 'a skipped member keeps their batch for the next run');
        $this->assertStringContainsString('skipped non-critical mail', $logged);
    }

    public function test_non_critical_mail_stops_at_the_monthly_cap(): void
    {
        $guard = $this->cappedGuard(daily: 100, monthly: 5); // daily usable 90, so the month binds
        for ($i = 0; $i < 5; $i++) $this->assertTrue($guard->send("a$i@x.test", 's', 'b'));
        $refused = false;
        $this->withErrorLog(function () use ($guard, &$refused): void {
            $refused = $guard->send('late@x.test', 's', 'b');
        });
        $this->assertFalse($refused);
        $this->assertSame(5, $this->sentCount());
    }

    public function test_a_new_month_starts_the_count_over(): void
    {
        $now = strtotime('2026-11-30 12:00:00 UTC');
        $guard = $this->cappedGuard(daily: 100, monthly: 2, clock: function () use (&$now) { return $now; });
        $this->assertTrue($guard->send('a@x.test', 's', 'b'));
        $this->assertTrue($guard->send('b@x.test', 's', 'b'));
        $this->withErrorLog(fn () => $this->assertFalse($guard->send('c@x.test', 's', 'b')));
        $now = strtotime('2026-12-01 00:00:01 UTC');
        $this->assertTrue($guard->send('d@x.test', 's', 'b'), 'the new month admits again');
    }

    public function test_monthly_cap_zero_means_off(): void
    {
        $guard = $this->cappedGuard(daily: 100, monthly: 0);
        for ($i = 0; $i < 90; $i++) $this->assertTrue($guard->send("a$i@x.test", 's', 'b')); // daily usable only
        $this->withErrorLog(fn () => $this->assertFalse($guard->send('late@x.test', 's', 'b')));
        $this->assertSame(90, $this->sentCount());
    }

    public function test_a_monthly_cap_with_daily_zero_does_nothing_and_is_documented(): void
    {
        // The guard is off at daily 0 (existing behavior); a hosting operator's
        // config is validated to refuse stamping this combination.
        $guard = $this->cappedGuard(daily: 0, monthly: 3);
        for ($i = 0; $i < 10; $i++) $this->assertTrue($guard->send("a$i@x.test", 's', 'b'));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM mail_sends')['c'], 'cap 0 never touches the counter table');
    }

    public function test_a_daily_cap_below_the_reserve_starves_bulk_and_keeps_critical_whole(): void
    {
        // The RESERVE slot math goes negative under a tiny cap, and that is
        // the safe direction: bulk mail stops entirely (no day ever spends a
        // slot the morning digest or a verification mail could need), the
        // critical bucket keeps its full cap, and the screens report room 0
        // with the day as the binding limit. This test pins that intent.
        $guard = $this->cappedGuard(daily: 5);
        $this->withErrorLog(fn () => $this->assertFalse($guard->send('bulk@x.test', 's', 'b'), 'usable 5-10 is negative: no bulk slot exists'));
        $this->assertTrue($guard->send('verify@x.test', 'v', 'b', MailGuard::CRITICAL), 'critical mail keeps the full cap');
        $this->assertSame(0, $guard->usage()['today'], 'the refused bulk wrote no counter row');
        $this->assertSame(0, $guard->usage()['room']);
        $this->assertSame('day', $guard->usage()['binding']);
        $this->assertSame(1, $this->criticalSent(), 'only the critical send counted');
    }

    public function test_critical_mail_has_its_own_bucket_and_does_not_reduce_bulk_room(): void
    {
        $guard = $this->cappedGuard(daily: 100);
        for ($i = 0; $i < 100; $i++) $guard->send("c$i@x.test", 'v', 'b', MailGuard::CRITICAL); // ceiling is the daily cap
        $this->withErrorLog(fn () => $this->assertFalse($guard->send('over@x.test', 'v', 'b', MailGuard::CRITICAL))); // 101st refused
        $this->assertTrue($guard->send('bulk@x.test', 's', 'b'), 'bulk room untouched');
        $this->assertSame(1, $guard->usage()['today']);
    }

    public function test_critical_mail_does_not_count_toward_the_monthly_total(): void
    {
        $guard = $this->cappedGuard(daily: 100, monthly: 3);
        for ($i = 0; $i < 5; $i++) $guard->send("c$i@x.test", 'v', 'b', MailGuard::CRITICAL);
        for ($i = 0; $i < 3; $i++) $this->assertTrue($guard->send("b$i@x.test", 's', 'b'), 'the monthly cap counts bulk mail only');
        $this->withErrorLog(fn () => $this->assertFalse($guard->send('late@x.test', 's', 'b')));
    }

    public function test_critical_ceiling_boundaries(): void
    {
        $now = strtotime('2026-11-15 10:00:00 UTC');
        $guard = $this->cappedGuard(daily: 10, clock: function () use (&$now) { return $now; });
        for ($i = 0; $i < 9; $i++) $this->assertTrue($guard->send("c$i@x.test", 'v', 'b', MailGuard::CRITICAL));
        $this->assertTrue($guard->send('tenth@x.test', 'v', 'b', MailGuard::CRITICAL), 'exactly the ceiling is admitted');
        $this->withErrorLog(fn () => $this->assertFalse($guard->send('eleventh@x.test', 'v', 'b', MailGuard::CRITICAL))); // the 11th refused
        $this->assertSame(10, $this->criticalSent('2026-11-15')); // the frozen clock's own day
        $now = strtotime('2026-11-16 00:00:01 UTC');
        $this->assertTrue($guard->send('nextday@x.test', 'v', 'b', MailGuard::CRITICAL), 'the next UTC day admits again');
    }

    public function test_old_critical_rows_are_pruned_with_old_bulk_rows(): void
    {
        $this->db->query("INSERT INTO mail_sends (day, sent) VALUES ('2026-09-01', 4), ('c:2026-09-01', 2)");
        $now = strtotime('2026-11-15 10:00:00 UTC'); // 75 days after the seeded rows
        $guard = $this->cappedGuard(daily: 100, clock: function () use (&$now) { return $now; });
        $this->assertTrue($guard->send('a@x.test', 's', 'b')); // the day's first send retires old days
        $days = array_column($this->db->all('SELECT day FROM mail_sends'), 'day');
        $this->assertSame(['2026-11-15'], $days, 'old bulk and c: rows both pruned');
    }

    public function test_the_single_statement_admits_exactly_the_cap_across_connections(): void
    {
        // Two guards on one database file, interleaved. This proves the statement
        // is atomic and exact; it is not a race test (one PHP process runs them
        // one after the other).
        $a = $this->cappedGuard(daily: 12); // usable 2
        $b = $this->cappedGuard(daily: 12);
        $delivered = 0;
        for ($i = 0; $i < 6; $i++) {
            $guard = $i % 2 === 0 ? $a : $b;
            $sent = false;
            $this->withErrorLog(function () use ($guard, &$sent, $i): void {
                $sent = $guard->send("m$i@x.test", 's', 'b');
            });
            if ($sent) $delivered++;
        }
        $this->assertSame(2, $delivered, 'exactly the two usable slots, never a third');
        $this->assertSame(2, $this->sent());
    }

    /** A config that predates the new keys must run daily-only:
     *  no fatal, no guessed monthly cap, no storage quota (Task C2 owns that key). */
    public function test_an_older_config_without_the_new_keys_runs_daily_only(): void
    {
        $app = new App(['app_dir' => dirname(__DIR__) . '/app', 'env' => 'prod',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'mail_daily_cap' => 12, 'site_name' => 'Kiption', 'base_url' => 'https://archive.example']);
        $guard = $app->container->make(MailGuard::class);
        $this->assertTrue($guard->send('a@e.test', 's', 'b'));
        $this->assertTrue($guard->send('b@e.test', 's', 'b'));
        $this->withErrorLog(fn () => $this->assertFalse($guard->send('c@e.test', 's', 'b'))); // the daily reserve line still binds
        $u = $guard->usage();
        $this->assertSame(0, $u['monthlyCap'], 'the missing key reads as 0, never a guessed number');
        $this->assertSame('day', $u['binding'], 'only the daily cap can bind');
    }

    public function test_usage_reports_usable_room_and_the_binding_limit(): void
    {
        $guard = $this->cappedGuard(daily: 100, monthly: 500);
        for ($i = 0; $i < 30; $i++) $guard->send("a$i@x.test", 's', 'b');
        $u = $guard->usage();
        $this->assertSame(30, $u['today']);
        $this->assertSame(90, $u['dailyUsable']); // cap minus RESERVE: the denominator the screen shows
        $this->assertSame(60, $u['room']);
        $this->assertSame('day', $u['binding']);
    }

    public function test_room_is_the_lesser_of_the_daily_and_monthly_room(): void
    {
        $guard = $this->cappedGuard(daily: 100, monthly: 20); // daily usable 90, the month is tighter
        $u = $guard->usage();
        $this->assertSame(20, $u['room']);
        $this->assertSame('month', $u['binding']);
        $this->assertSame(0, $u['month']);
    }

    public function test_room_with_no_cap_is_php_int_max_and_binding_is_null(): void
    {
        $guard = $this->cappedGuard(daily: 0, monthly: 0);
        $u = $guard->usage();
        $this->assertSame(PHP_INT_MAX, $u['room']);
        $this->assertNull($u['binding']);
        $this->assertSame(0, $u['dailyCap']);
    }

    public function test_room_is_zero_at_the_reserve_line_and_never_negative(): void
    {
        $guard = $this->cappedGuard(daily: 100);
        for ($i = 0; $i < 90; $i++) $guard->send("a$i@x.test", 's', 'b');
        $this->assertSame(0, $guard->usage()['room'], 'the reserve line leaves no room');
        for ($i = 90; $i < 94; $i++) $this->withErrorLog(fn () => $guard->send("r$i@x.test", 's', 'b')); // refused
        $u = $guard->usage();
        $this->assertSame(0, $u['room'], 'refused sends never push the count past the line');
        $this->assertSame('day', $u['binding']);
    }

    public function test_the_displayed_denominator_equals_cap_minus_reserve(): void
    {
        $this->assertSame(100 - MailGuard::RESERVE, $this->cappedGuard(daily: 100)->usage()['dailyUsable']);
    }

    /** Task B6: a fan-out that skips mail must be visible in the activity log
     *  (a null actor: cron and CLI runs have no session), one row per run. */

    private function withEmailFollower(): void
    {
        $this->db->query("INSERT INTO follows (follower_id, author_id, notify_mode) VALUES (?, 1, 'email')", [$this->fanId]);
    }

    private function skippedRows(): array
    {
        return $this->db->all("SELECT actor_id, action, detail FROM activity_log WHERE action = 'mail.skipped' ORDER BY id");
    }

    public function test_a_fanout_with_no_skips_writes_no_row(): void
    {
        $this->withEmailFollower();
        (new PublishFanout($this->db, $this->guard(22), 'https://archive.example'))->publish('the-rabbit-hole');
        $this->assertSame([], $this->skippedRows(), 'no refusal, no row');
        $this->assertSame(1, substr_count((string) file_get_contents($this->mailLog), 'From: noreply@localhost'));
    }

    public function test_two_chapter_publishes_in_one_window_each_write_their_own_row(): void
    {
        $this->withEmailFollower();
        $fanout = new PublishFanout($this->db, $this->guard(1), 'https://archive.example'); // cap 1: usable 0, the mail is skipped
        $this->withErrorLog(fn () => $fanout->publish('the-rabbit-hole'));
        $this->withErrorLog(fn () => $fanout->publish('the-rabbit-hole'));
        $rows = $this->skippedRows();
        $this->assertCount(2, $rows, 'plain record(), not recordOnce(): a second run is not dropped');
        foreach ($rows as $row) {
            $this->assertNull($row['actor_id'], 'cron and CLI runs have no session');
            $this->assertStringContainsString('1 emails skipped', $row['detail']);
        }
    }

    public function test_a_stamped_shape_config_keeps_sending_on_a_small_cap(): void
    {
        // End to end on the config shape a hosting operator stamps: two bulk
        // sends deliver, the third is refused, critical mail still delivers.
        $guard = $this->cappedGuard(daily: 12, monthly: 20);
        $this->assertTrue($guard->send('a@e.test', 'Story update: A', 'b'));
        $this->assertTrue($guard->send('b@e.test', 'Story update: B', 'b'));
        $this->withErrorLog(fn () => $this->assertFalse($guard->send('c@e.test', 'Story update: C', 'b')));
        $this->assertTrue($guard->send('d@e.test', 'Verify your account', 'b', MailGuard::CRITICAL));
        $this->assertSame(3, $this->sentCount());
    }
}
