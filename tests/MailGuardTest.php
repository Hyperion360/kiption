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

    private function mailer(): \Kip\Mailer
    {
        return new \Kip\Mailer(['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost']);
    }

    /** phpunit parks error_log at /dev/null; the skip line is asserted from a
     *  real file, restored whatever happens (the seed/teardown discipline). */
    private function withErrorLog(callable $fn): string
    {
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

    public function test_at_cap_verification_mail_sends_and_increments(): void
    {
        $this->seedSent(12); // the counter sits AT the cap
        $res = $this->client(['registration_mode' => 'verify', 'mail_daily_cap' => 12])
            ->post('/auth/store', ['penname' => 'atcap', 'email' => 'atcap@e.test', 'password' => 'password123', 'confirm' => 'password123']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringContainsString('/auth/verify/', (string) file_get_contents($this->mailLog), 'critical mail went out at the cap');
        $this->assertSame(13, $this->sent(), 'the critical send counted');
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
        $this->assertSame(3, $this->sent(), 'the critical send counted');
    }

    public function test_below_cap_both_kinds_send(): void
    {
        $this->seedSent(1); // far below the 300 cap
        $guard = $this->guard(300);
        $this->assertTrue($guard->send('a@e.test', 'Story update: X', 'body'));
        $this->assertTrue($guard->send('a@e.test', 'Verify your account', 'body', MailGuard::CRITICAL));
        $this->assertSame(3, $this->sent());
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
}
