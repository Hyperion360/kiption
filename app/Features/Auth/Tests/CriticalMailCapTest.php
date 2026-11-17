<?php // app/Features/Auth/Tests/CriticalMailCapTest.php
namespace App\Features\Auth\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** The critical-mail surfaces (registration, forgot password, sign-in link)
 *  at the verification-mail ceiling: the account-existence-neutral pages stay
 *  byte-identical, registration never creates an account its verification
 *  mail cannot reach, and one address cannot be mailed endlessly. The
 *  ceiling lives in App\MailGuard's own bucket (rows keyed c:YYYY-MM-DD). */
#[Medium]
final class CriticalMailCapTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private string $mailLog = '';
    private string $errLog = '';
    private App $app;
    private Database $db;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::path('kiption-crit-');
        $this->root = sys_get_temp_dir() . '/kiption-crit-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->mailLog = $this->root . '/mail.log';
        $this->errLog = $this->root . '/error.log';
        touch($this->mailLog);
        $this->app = new App($this->config());
        $this->db = $this->app->container->make(Database::class);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        $this->app->runDeferred(); // a deferred send must not fire after the root is gone
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function config(array $extra = []): array
    {
        return $extra + [
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'mail_daily_cap' => 5, // the guard is armed in every test here; fillCriticalBucket fills its critical bucket
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'signup_min_seconds' => 0, // these tests post the form without loading it first
        ];
    }

    private function app(array $extra): App
    {
        return new App($this->config($extra));
    }

    private function post(string $path, array $form, ?App $app = null): Response
    {
        $app ??= $this->app;
        $form['_token'] = $app->session->csrfToken();
        return $app->handle(new Request('POST', $path, [], $form, [], '127.0.0.1'));
    }

    private function registerMember(string $email = 'known@e.test'): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES (?, ?, ?, ?, ?)',
            [$email, \App\Tests\Support\Hashes::of('password123'), explode('@', $email)[0], date('c'), date('c')]);
        return (int) $this->db->lastInsertId();
    }

    /** Fill the critical bucket to the given daily cap. */
    private function fillCriticalBucket(int $cap): void
    {
        $this->db->query('INSERT INTO mail_sends (day, sent) VALUES (?, ?)', ['c:' . gmdate('Y-m-d'), $cap]);
    }

    /** phpunit parks error_log at /dev/null; refusals are asserted from a
     *  real file, restored whatever happens (the MailGuardTest idiom). */
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

    private function mailCount(): int
    {
        return substr_count((string) file_get_contents($this->mailLog), 'From: noreply@localhost');
    }

    public function test_forgot_page_is_byte_identical_for_known_and_unknown_addresses_when_the_ceiling_is_hit(): void
    {
        $this->registerMember();
        $baseline = $this->post('/auth/remind', ['email' => 'known@e.test']); // the bucket is still empty
        $this->fillCriticalBucket(5);
        $known = $this->post('/auth/remind', ['email' => 'known@e.test']);
        $unknown = $this->post('/auth/remind', ['email' => 'ghost@e.test']);
        $this->assertSame(200, $known->status);
        $this->assertSame(200, $unknown->status);
        $this->assertSame($baseline->body, $known->body, 'a full ceiling changes nothing the visitor sees');
        $this->assertSame($known->body, $unknown->body, 'no account existence oracle at the ceiling either');
    }

    public function test_a_refused_reset_mail_writes_a_log_line_without_the_address(): void
    {
        $this->registerMember();
        $this->fillCriticalBucket(5);
        $res = null;
        $log = $this->withErrorLog(function () use (&$res): void {
            $res = $this->post('/auth/remind', ['email' => 'known@e.test']);
            $this->app->runDeferred();
        });
        $this->assertSame(200, $res->status);
        $this->assertSame(0, $this->mailCount(), 'the ceiling refused the send');
        $this->assertStringContainsString('skipped critical mail', $log);
        $this->assertStringNotContainsString('known@e.test', $log, 'the address never rides the log');
    }

    public function test_the_sign_in_link_page_is_byte_identical_for_known_and_unknown_addresses_when_the_ceiling_is_hit(): void
    {
        $this->registerMember();
        $baseline = $this->post('/auth/send', ['email' => 'known@e.test']);
        $this->fillCriticalBucket(5);
        $known = $this->post('/auth/send', ['email' => 'known@e.test']);
        $unknown = $this->post('/auth/send', ['email' => 'ghost@e.test']);
        $this->assertSame(200, $known->status);
        $this->assertSame($baseline->body, $known->body, 'a full ceiling changes nothing the visitor sees');
        $this->assertSame($known->body, $unknown->body, 'no account existence oracle at the ceiling either');
    }

    public function test_a_refused_link_mail_writes_a_log_line_without_the_address(): void
    {
        $this->registerMember();
        $this->fillCriticalBucket(5);
        $res = null;
        $log = $this->withErrorLog(function () use (&$res): void {
            $res = $this->post('/auth/send', ['email' => 'known@e.test']);
            $this->app->runDeferred();
        });
        $this->assertSame(200, $res->status);
        $this->assertSame(0, $this->mailCount(), 'the ceiling refused the send');
        $this->assertStringContainsString('skipped critical mail', $log);
        $this->assertStringNotContainsString('known@e.test', $log, 'the address never rides the log');
    }

    public function test_register_with_a_full_critical_bucket_creates_no_account(): void
    {
        $before = (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'];
        $this->fillCriticalBucket(5);
        $res = $this->app(['registration_mode' => 'verify', 'mail_daily_cap' => 5])
            ->handle(new Request('POST', '/auth/store', [], [
                '_token' => $this->app->session->csrfToken(),
                'penname' => 'latecomer', 'email' => 'late@e.test', 'password' => 'password123', 'confirm' => 'password123',
            ], [], '127.0.0.1'));
        $this->assertSame(422, $res->status, $res->body);
        $this->assertStringContainsString(\App\Lang::t('auth.err_mail_busy'), $res->body);
        $this->assertSame($before, (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'], 'nothing was created');
        $this->assertSame(0, $this->mailCount(), 'no mail left the archive');
    }

    public function test_register_when_the_send_is_refused_after_the_account_exists_points_to_forgot_password(): void
    {
        // The narrow race: the bucket fills between the preflight and the send.
        // The trigger stands in for the concurrent writer: the account INSERT
        // pushes the c: row to its ceiling, so the send refuses after the
        // account exists.
        $this->db->query("CREATE TRIGGER fill_critical_race AFTER INSERT ON users
            BEGIN UPDATE mail_sends SET sent = 3 WHERE day = 'c:' || strftime('%Y-%m-%d', 'now'); END");
        $this->fillCriticalBucket(0);
        $before = (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'];
        $res = $this->app(['registration_mode' => 'verify', 'mail_daily_cap' => 3])
            ->handle(new Request('POST', '/auth/store', [], [
                '_token' => $this->app->session->csrfToken(),
                'penname' => 'racer', 'email' => 'racer@e.test', 'password' => 'password123', 'confirm' => 'password123',
            ], [], '127.0.0.1'));
        $this->assertSame(200, $res->status, $res->body);
        $this->assertSame($before + 1, (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'], 'the race let the account exist');
        $this->assertSame(0, $this->mailCount(), 'the refused send mailed nobody');
        $this->assertStringContainsString(\App\Lang::t('auth.mail_not_sent'), $res->body);
        $this->assertStringContainsString('Forgot Password', $res->body, 'the page names the resend path');
    }

    public function test_a_limited_register_creates_no_account(): void
    {
        // Burn the address's three mail_addr slots, then try to register it.
        $limiter = new \App\AttemptLimiter($this->db);
        for ($i = 0; $i < 3; $i++) $this->assertTrue($limiter->claim('mail_addr', 'busy@e.test', '127.0.0.1', 3, 30));
        $before = (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'];
        $res = $this->app(['registration_mode' => 'verify'])
            ->handle(new Request('POST', '/auth/store', [], [
                '_token' => $this->app->session->csrfToken(),
                'penname' => 'busyone', 'email' => 'busy@e.test', 'password' => 'password123', 'confirm' => 'password123',
            ], [], '127.0.0.1'));
        $this->assertSame(422, $res->status, $res->body);
        $this->assertStringContainsString(\App\Lang::t('auth.err_mail_busy'), $res->body);
        $this->assertSame($before, (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'], 'no account exists that its mail cannot reach');
        $this->assertSame(0, $this->mailCount());
    }

    public function test_one_address_cannot_be_mailed_more_than_three_times_per_window_on_register_and_forgot(): void
    {
        $this->registerMember();
        for ($i = 0; $i < 3; $i++) $this->assertSame(200, $this->post('/auth/remind', ['email' => 'known@e.test'])->status);
        $rows = (int) $this->db->one("SELECT COUNT(*) c FROM login_attempts WHERE kind = 'mail_addr' AND email = 'known@e.test'")['c'];
        $this->assertSame(3, $rows, 'three claims landed');
        $tokens = (int) $this->db->one('SELECT COUNT(*) c FROM password_resets')['c'];
        $fourth = $this->post('/auth/remind', ['email' => 'known@e.test']);
        $this->assertSame(200, $fourth->status);
        $this->assertSame(3, (int) $this->db->one("SELECT COUNT(*) c FROM login_attempts WHERE kind = 'mail_addr' AND email = 'known@e.test'")['c'], 'the fourth claim is refused');
        $this->assertSame($tokens, (int) $this->db->one('SELECT COUNT(*) c FROM password_resets')['c'], 'the fourth request mints no reset token');
    }

    public function test_the_limit_applies_equally_to_unknown_addresses(): void
    {
        // Three remind posts for an address with no account burn the same
        // slots; the page never says whether the account exists, at the
        // limit or under it.
        $known = $this->post('/auth/remind', ['email' => 'known@e.test']);
        $unknownFirst = $this->post('/auth/remind', ['email' => 'ghost@e.test']);
        $this->assertSame($known->body, $unknownFirst->body);
        for ($i = 0; $i < 3; $i++) $this->post('/auth/remind', ['email' => 'ghost@e.test']);
        $limited = $this->post('/auth/remind', ['email' => 'ghost@e.test']);
        $this->assertSame($known->body, $limited->body, 'the limited page is the same page');
    }

    public function test_a_bot_cannot_exhaust_the_critical_bucket_with_ten_requests(): void
    {
        // Ten register posts mail ten verifications; the bucket holds the
        // whole daily cap, not ten.
        $client = $this->app(['registration_mode' => 'verify', 'mail_daily_cap' => 20]);
        for ($i = 0; $i < 10; $i++) {
            $res = $this->post('/auth/store', [
                'penname' => "bot$i", 'email' => "bot$i@e.test", 'password' => 'password123', 'confirm' => 'password123',
            ], $client);
            $this->assertSame(302, $res->status, "post $i: " . $res->body);
        }
        $this->assertSame(10, (int) $this->db->one("SELECT sent FROM mail_sends WHERE day = ?", ['c:' . gmdate('Y-m-d')])['sent']);
        $this->assertSame(10, $this->mailCount());
    }

    public function test_critical_flood_leaves_bulk_room_untouched(): void
    {
        $client = $this->app(['registration_mode' => 'verify', 'mail_daily_cap' => 20]);
        for ($i = 0; $i < 10; $i++) {
            $this->post('/auth/store', [
                'penname' => "flood$i", 'email' => "flood$i@e.test", 'password' => 'password123', 'confirm' => 'password123',
            ], $client);
        }
        $guard = $client->container->make(\App\MailGuard::class);
        $u = $guard->usage();
        $this->assertSame(0, $u['today'], 'critical mail never counts in the bulk row');
        $this->assertSame(10, $u['room'], 'the broadcast budget is untouched by the flood');
    }
}
