<?php // app/Features/Account/Tests/EmailChangeTest.php
namespace App\Features\Account\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Changing the email address: a confirm link to the new one, applied only when used. */
final class EmailChangeTest extends TestCase
{
    private string $path = '';
    private string $log = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-em-') . '.sqlite';
        $this->log = tempnam(sys_get_temp_dir(), 'kiption-em-mail-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm'); @unlink($this->log);
    }

    private function app(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->log, 'from' => 'noreply@localhost'],
            'mail_daily_cap' => 5, // armed in every test here; the cap-refusal tests fill the critical bucket to it
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-em-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function member(): TestClient
    {
        $id = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        return (new TestClient($this->app()))->actingAs($id);
    }

    private function email(): string
    {
        return (string) $this->db->one("SELECT email FROM users WHERE penname = 'betafriend'")['email'];
    }

    private function ask(string $new = 'fresh@example.test', string $pw = 'password123'): \Kip\Http\Response
    {
        return $this->member()->postWithToken('/account/email', ['new_email' => $new, 'current_password' => $pw]);
    }

    private function token(): string
    {
        preg_match('/emailconfirm\?t=([0-9a-f]{64})/', (string) file_get_contents($this->log), $m);
        return $m[1] ?? '';
    }

    public function test_email_change_requests_are_capped_even_with_the_right_password(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame(302, $this->ask('victim' . $i . '@example.test')->status);
        }
        $before = substr_count((string) file_get_contents($this->log), 'emailconfirm?t=');
        $this->ask('victim4@example.test');
        $this->assertSame($before, substr_count((string) file_get_contents($this->log), 'emailconfirm?t='),
            'a fourth request in the window must not mail anyone');
    }

    public function test_asking_mails_the_new_address_and_changes_nothing_yet(): void
    {
        $res = $this->ask();
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('beta@example.test', $this->email());
        $mail = (string) file_get_contents($this->log);
        $this->assertStringContainsString('fresh@example.test', $mail);
        $this->assertNotSame('', $this->token());
    }

    public function test_opening_the_link_only_shows_a_button_then_posting_applies_it(): void
    {
        $this->ask();
        $t = $this->token();
        $guest = new TestClient($this->app());
        $page = $guest->get('/account/emailconfirm', ['t' => $t]);
        $this->assertSame(200, $page->status);
        $this->assertSame('beta@example.test', $this->email(), 'a GET never applies it');
        $this->assertSame('no-referrer', $page->headers['Referrer-Policy'] ?? '');
        $done = $guest->postWithToken('/account/emailapply', ['t' => $t]);
        $this->assertSame(200, $done->status, $done->body);
        $this->assertSame('fresh@example.test', $this->email());
        $mail = (string) file_get_contents($this->log);
        $this->assertStringContainsString('beta@example.test', $mail, 'the old address gets a notice');
        $this->assertStringContainsString('fresh@example.test', $mail);
    }

    public function test_a_used_link_cannot_be_replayed(): void
    {
        $this->ask();
        $t = $this->token();
        $guest = new TestClient($this->app());
        $guest->postWithToken('/account/emailapply', ['t' => $t]);
        $this->db->query("UPDATE users SET email = 'beta@example.test' WHERE penname = 'betafriend'");
        $again = (new TestClient($this->app()))->postWithToken('/account/emailapply', ['t' => $t]);
        $this->assertSame(410, $again->status);
        $this->assertSame('beta@example.test', $this->email());
        $this->assertSame(410, (new TestClient($this->app()))->get('/account/emailconfirm', ['t' => $t])->status);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $this->ask();
        $t = $this->token();
        $this->db->query("UPDATE email_changes SET expires_at = ?", [date('c', time() - 60)]);
        $this->assertSame(410, (new TestClient($this->app()))->get('/account/emailconfirm', ['t' => $t])->status);
        $res = (new TestClient($this->app()))->postWithToken('/account/emailapply', ['t' => $t]);
        $this->assertSame(410, $res->status);
        $this->assertSame('beta@example.test', $this->email());
    }

    public function test_garbage_and_missing_tokens_are_refused(): void
    {
        $g = new TestClient($this->app());
        $this->assertSame(410, $g->get('/account/emailconfirm', ['t' => 'nope'])->status);
        $this->assertSame(410, $g->postWithToken('/account/emailapply', ['t' => str_repeat('a', 64)])->status);
        $this->assertSame(410, $g->get('/account/emailconfirm')->status);
    }

    public function test_apply_without_csrf_is_refused(): void
    {
        $this->ask();
        $res = (new TestClient($this->app()))->post('/account/emailapply', ['t' => $this->token()], ['sec-fetch-site' => 'cross-site']);
        $this->assertSame(403, $res->status);
        $this->assertSame('beta@example.test', $this->email());
    }

    public function test_a_taken_address_is_refused_at_request_and_at_apply(): void
    {
        $res = $this->ask('demo@example.test');
        $this->assertSame(422, $res->status);
        $this->assertSame('', $this->token());
        // taken between the request and the click
        $this->ask('late@example.test');
        $t = $this->token();
        $this->db->query("UPDATE users SET email = 'late@example.test' WHERE penname = 'Demo Author'");
        $done = (new TestClient($this->app()))->postWithToken('/account/emailapply', ['t' => $t]);
        $this->assertSame(410, $done->status);
        $this->assertSame('beta@example.test', $this->email());
    }

    public function test_wrong_password_bad_address_and_missing_csrf_are_refused(): void
    {
        $this->assertSame(422, $this->ask('fresh@example.test', 'wrong-pass')->status);
        $this->assertSame(422, $this->ask('not-an-email')->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM email_changes')['c']);
        $this->assertSame(403, $this->member()->post('/account/email', ['new_email' => 'x@example.test', 'current_password' => 'password123'])->status);
    }

    public function test_a_guest_cannot_ask(): void
    {
        $res = (new TestClient($this->app()))->postWithToken('/account/email', ['new_email' => 'x@example.test', 'current_password' => 'password123']);
        $this->assertNotSame(200, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM email_changes')['c']);
    }

    public function test_asking_again_replaces_the_earlier_link(): void
    {
        $this->ask('one@example.test');
        $first = $this->token();
        $this->ask('two@example.test');
        $this->assertFalse((new \App\AccountSecurity($this->db, new \App\AttemptLimiter($this->db)))->peekEmailChange($first));
    }

    /** @return iterable<string, array{string}> */
    public static function deadAccounts(): iterable
    {
        yield 'locked' => ['UPDATE users SET is_locked = 1 WHERE penname = \'betafriend\''];
        yield 'deleted' => ['UPDATE users SET email = \'deleted-9@deleted.invalid\' WHERE penname = \'betafriend\''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deadAccounts')]
    public function test_a_locked_or_deleted_account_cannot_apply_an_email_change(string $sql): void
    {
        $this->ask();
        $t = $this->token();
        $this->db->query($sql);
        $before = $this->email();
        $acc = new \App\AccountSecurity($this->db, new \App\AttemptLimiter($this->db));
        $this->assertSame('account.email_link_expired', $acc->applyEmailChange($t));
        $this->assertSame($before, $this->email());
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM email_changes')['c'], 'the token is burned');
    }

    public function test_applying_a_change_deletes_pending_sign_in_links(): void
    {
        $id = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        (new \App\LoginLinks($this->db))->mint($id, 1800);
        $this->ask();
        $acc = new \App\AccountSecurity($this->db, new \App\AttemptLimiter($this->db));
        $this->assertIsArray($acc->applyEmailChange($this->token()));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM login_links WHERE user_id = ?', [$id])['c']);
    }


    public function test_a_password_change_since_the_request_voids_it(): void
    {
        // Whoever asked may have been an attacker the member has since reset away.
        $this->ask('attacker@example.test');
        $t = $this->token();
        $this->db->query("UPDATE users SET password_hash = ? WHERE penname = 'betafriend'", [password_hash('a-new-password', PASSWORD_DEFAULT)]);
        $this->assertSame(410, (new TestClient($this->app()))->get('/account/emailconfirm', ['t' => $t])->status);
        $this->assertSame(410, (new TestClient($this->app()))->postWithToken('/account/emailapply', ['t' => $t])->status);
        $this->assertSame('beta@example.test', $this->email());
    }

    public function test_reserved_invalid_addresses_cannot_be_claimed(): void
    {
        $res = $this->ask(\App\AccountSecurity::ANON_EMAIL);
        $this->assertSame(422, $res->status);
        $this->assertSame('', $this->token(), 'no link is mailed');
        $this->assertSame('beta@example.test', $this->email());
        $users = new \App\Repositories\UserRepository($this->db);
        foreach ([\App\AccountSecurity::ANON_EMAIL, 'x@Deleted.INVALID'] as $reserved) {
            [$ok] = $users->register('open', 'squatter', $reserved, 'password123', '');
            $this->assertFalse($ok, $reserved . ' must not register');
        }
        $this->assertNull($this->db->one("SELECT 1 AS x FROM users WHERE penname = 'squatter'"));
    }

    public function test_a_case_variant_of_a_taken_address_is_refused_at_request_and_at_apply(): void
    {
        $res = $this->ask('DEMO@Example.TEST');
        $this->assertSame(422, $res->status);
        $this->assertSame('', $this->token());
        $this->ask('late2@example.test');
        $t = $this->token();
        $this->db->query("UPDATE users SET email = 'LATE2@Example.test' WHERE penname = 'Demo Author'");
        $done = (new TestClient($this->app()))->postWithToken('/account/emailapply', ['t' => $t]);
        $this->assertSame(410, $done->status);
        $this->assertSame('beta@example.test', $this->email());
    }

    /** The verification-mail ceiling refused the confirm mail: the member
     *  gets the existing refuse path, and no request row is logged (the
     *  change never went out). */
    public function test_email_change_refused_mail_uses_the_existing_refuse_path(): void
    {
        $this->fillCriticalBucket(5);
        $errFile = tempnam(sys_get_temp_dir(), 'kiption-em-err-');
        ini_set('error_log', $errFile);
        $res = $this->ask();
        ini_set('error_log', '/dev/null');
        $this->assertSame(422, $res->status, $res->body);
        $this->assertStringContainsString(\App\Lang::t('account.err_email_mail'), $res->body);
        $this->assertSame('beta@example.test', $this->email(), 'nothing changed');
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM activity_log WHERE action = 'account.email_requested'")['c']);
        @unlink($errFile);
    }

    /** The apply step's notice to the OLD address at the ceiling: the change
     *  is already applied, so the refusal only writes a log line. */
    public function test_a_refused_change_notice_only_logs(): void
    {
        $this->ask(); // the request mail goes out while the bucket is empty
        $t = $this->token();
        $this->fillCriticalBucket(5);
        $errFile = tempnam(sys_get_temp_dir(), 'kiption-em-err-');
        ini_set('error_log', $errFile);
        $done = (new TestClient($this->app()))->postWithToken('/account/emailapply', ['t' => $t]);
        ini_set('error_log', '/dev/null');
        $this->assertSame(200, $done->status, $done->body);
        $this->assertSame('fresh@example.test', $this->email(), 'the change itself went through');
        $log = (string) file_get_contents($errFile);
        $this->assertStringContainsString('Email change notice skipped: the verification mail limit is reached', $log);
        $this->assertStringNotContainsString('beta@example.test', $log, 'the old address never rides the log');
        @unlink($errFile);
    }

    private function fillCriticalBucket(int $cap): void
    {
        $this->db->query('INSERT INTO mail_sends (day, sent) VALUES (?, ?) ON CONFLICT (day) DO UPDATE SET sent = excluded.sent',
            ['c:' . gmdate('Y-m-d'), $cap]);
    }
}
