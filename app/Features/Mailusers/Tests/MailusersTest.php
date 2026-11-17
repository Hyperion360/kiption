<?php // app/Features/Mailusers/Tests/MailusersTest.php
namespace App\Features\Mailusers\Tests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The owner broadcast screen. */
#[Medium]
final class MailusersTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $targetId = 0;
    private int $heirId = 0;
    private int $moderatorId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-mailusers-');
        $this->root = sys_get_temp_dir() . '/kiption-mailusers-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->adminId = $this->user('roster-admin', 'admin');
        $this->targetId = $this->user('leaving-member', 'member');
        $this->heirId = $this->user('heir-member', 'member');
        $this->moderatorId = $this->user('roster-mod', 'moderator');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function user(string $name, string $role): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ["$name@e.test", \App\Tests\Support\Hashes::of('password123'), $name, $role, $role === 'admin' ? 1 : 0, date('c'), date('c'), $name]);
        return (int) $this->db->lastInsertId();
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        $client = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
        ] + $extra));
        return $as === null ? $client : $client->actingAs($as);
    }

    private function mail(): string
    {
        return is_file($this->root . '/mail.log') ? (string) file_get_contents($this->root . '/mail.log') : '';
    }

    /** Members the command's rule reaches, counted independently in SQL. */
    private function eligible(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) c FROM users WHERE penname IS NOT NULL AND is_locked = 0 AND approved_at IS NOT NULL AND email_verified_at IS NOT NULL')['c'];
    }

    public function test_preview_shows_the_count_and_sends_nothing(): void
    {
        $this->db->query('UPDATE users SET is_locked = 1 WHERE id = ?', [$this->heirId]);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('nov@e.test', 'x', NULL, ?, ?)", [date('c'), date('c')]);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at) VALUES ('unapproved@e.test', 'x', 'unapproved', ?)", [date('c')]);
        $res = $this->client($this->adminId)->postWithToken('/mailusers/preview', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('This will go to ' . $this->eligible() . ' member(s).', $res->body);
        $this->assertStringContainsString('action="/mailusers/send"', $res->body);
        $this->assertSame('', $this->mail());
    }

    #[Group('process')]
    public function test_count_matches_the_command(): void
    {
        $dry = (string) shell_exec(sprintf('KIP_DB_DSN=%s %s %s mail:users Subj Body --dry-run 2>&1',
            escapeshellarg('sqlite:' . $this->path), escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__, 4) . '/bin/kip')));
        $res = $this->client($this->adminId)->postWithToken('/mailusers/preview', ['subject' => 'S', 'body' => 'B']);
        $this->assertMatchesRegularExpression('/This will go to (\d+) member/', $res->body);
        preg_match('/This will go to (\d+) member/', $res->body, $m);
        $this->assertSame($this->eligible(), (int) $m[1]);
        $this->assertMatchesRegularExpression('/would mail (\d+) recipient/', $dry);
        preg_match('/would mail (\d+) recipient/', $dry, $d);
        $this->assertSame((int) $d[1], (int) $m[1], 'same number as kip mail:users --dry-run');
    }

    public function test_send_mails_each_recipient_once(): void
    {
        $res = $this->client($this->adminId)->postWithToken('/mailusers/send', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('Sent to ' . $this->eligible() . ' of ' . $this->eligible(), $res->body);
        $mail = $this->mail();
        $this->assertSame($this->eligible(), substr_count($mail, 'Subject: Hello all'));
        foreach (['heir-member@e.test', 'leaving-member@e.test', 'roster-admin@e.test'] as $to) {
            $this->assertSame(1, substr_count($mail, 'To: ' . $to), $to);
        }
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM mass_mail_log')['c']);
    }

    /** The refusal text renders through the escaped error slot, so the
     *  assertions compare the escaped form. */
    private function escaped(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    public function test_a_broadcast_at_a_cap_with_no_room_is_refused_up_front(): void
    {
        // Cap 10, usable 0: the up-front refusal fires before the cooldown is
        // claimed (Task B4 semantics; the old mid-send drop with "Sent to 0 of
        // N" cannot start from the screen any more).
        $res = $this->client($this->adminId, ['mail_daily_cap' => 10])->postWithToken('/mailusers/send', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString($this->escaped(\App\Lang::t('mailusers.err_cap_day',
            ['n' => number_format($this->eligible()), 'room' => number_format(0)])), $res->body);
        $this->assertSame('', $this->mail());
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM mass_mail_log')['c']);
    }

    public function test_a_second_send_within_ten_minutes_is_refused(): void
    {
        $admin = $this->client($this->adminId);
        $this->assertSame(200, $admin->postWithToken('/mailusers/send', ['subject' => 'One', 'body' => 'First.'])->status);
        $before = substr_count($this->mail(), 'Subject: ');
        $again = $admin->postWithToken('/mailusers/send', ['subject' => 'Two', 'body' => 'Second.']);
        $this->assertSame(429, $again->status);
        $this->assertStringContainsString('last ten minutes', $again->body);
        $this->assertSame($before, substr_count($this->mail(), 'Subject: '));
        // after the cooldown it works again
        $this->db->query('UPDATE mass_mail_log SET sent_at = ?', [gmdate('c', time() - 700)]);
        $this->assertSame(200, $admin->postWithToken('/mailusers/send', ['subject' => 'Three', 'body' => 'Third.'])->status);
    }

    public function test_bad_input_is_refused_without_using_the_slot(): void
    {
        $admin = $this->client($this->adminId);
        $this->assertSame(422, $admin->postWithToken('/mailusers/send', ['subject' => '', 'body' => 'x'])->status);
        $this->assertSame(422, $admin->postWithToken('/mailusers/send', ['subject' => 'x', 'body' => ' '])->status);
        $this->assertSame(422, $admin->postWithToken('/mailusers/preview', ['subject' => str_repeat('a', 201), 'body' => 'x'])->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM mass_mail_log')['c']);
        $this->assertSame('', $this->mail());
    }

    public function test_a_subject_cannot_smuggle_headers(): void
    {
        $this->client($this->adminId)->postWithToken('/mailusers/send', ['subject' => "Hi\r\nBcc: spy@e.test", 'body' => 'x']);
        $this->assertStringNotContainsString("\r\nBcc:", $this->mail());
    }

    public function test_only_admins_with_a_token_can_use_it(): void
    {
        $this->assertSame(200, $this->client($this->adminId)->get('/mailusers')->status);
        foreach ([$this->moderatorId, $this->heirId] as $who) {
            $this->assertSame(403, $this->client($who)->get('/mailusers')->status);
            $this->assertSame(403, $this->client($who)->postWithToken('/mailusers/preview', ['subject' => 'S', 'body' => 'B'])->status);
            $this->assertSame(403, $this->client($who)->postWithToken('/mailusers/send', ['subject' => 'S', 'body' => 'B'])->status);
        }
        $this->assertSame(403, $this->client($this->adminId)->post('/mailusers/send', ['subject' => 'S', 'body' => 'B'])->status, 'no CSRF token');
        $this->assertSame(403, $this->client($this->adminId)->post('/mailusers/preview', ['subject' => 'S', 'body' => 'B'])->status, 'no CSRF token');
        $this->assertNotSame(200, $this->client()->get('/mailusers')->status);
        $this->assertSame('', $this->mail());
    }

    public function test_the_compose_screen_says_who_it_reaches_and_when_the_last_one_went(): void
    {
        $n = $this->eligible();
        $body = $this->client($this->adminId)->get('/mailusers')->body;
        $this->assertStringContainsString(\App\Lang::t($n === 1 ? 'mailusers.reach_one' : 'mailusers.reach', ['n' => $n]), $body);
        $this->assertStringContainsString(\App\Lang::t('mailusers.last_never'), $body);
        $this->assertTrue(\App\MassMail::claim($this->db, $this->adminId, 'Autumn <news>', $n));
        $body = $this->client($this->adminId)->get('/mailusers')->body;
        $this->assertStringContainsString('Autumn &lt;news&gt;', $body);
        $this->assertStringNotContainsString(\App\Lang::t('mailusers.last_never'), $body);
    }

    public function test_a_screen_with_nobody_to_reach_says_so(): void
    {
        // The zero-recipient edge of MassMail::summary: the compose screen
        // explains itself instead of offering a send to nobody.
        $this->db->query('UPDATE users SET approved_at = NULL');
        $body = $this->client($this->adminId)->get('/mailusers')->body;
        $this->assertStringContainsString(\App\Lang::t('mailusers.reach_none'), $body);
    }

    /** Extra members for the cap tests, all broadcast-eligible. */
    private int $seeded = 0;

    private function seedMembers(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $j = $this->seeded++;
            $this->db->query('INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES (?, ?, ?, ?, ?)',
                ["cap$j@e.test", 'x', "capmember$j", date('c'), date('c')]);
        }
    }

    private function eligibleTotal(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) c FROM users WHERE penname IS NOT NULL AND is_locked = 0 AND approved_at IS NOT NULL AND email_verified_at IS NOT NULL')['c'];
    }

    public function test_a_broadcast_that_does_not_fit_is_refused_up_front(): void
    {
        $this->seedMembers(40); // 43 eligible with the seeded roster; daily cap 30 leaves room for 20
        $admin = $this->client($this->adminId, ['mail_daily_cap' => 30]);
        $preview = $admin->postWithToken('/mailusers/preview', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertSame(200, $preview->status);
        $this->assertStringContainsString(\App\Lang::t('mailusers.fits_not'), $preview->body);
        $this->assertStringContainsString('<button type="submit" disabled', $preview->body, 'the Send control renders disabled');
        $before = substr_count($this->mail(), 'Subject: ');
        $sent = $admin->postWithToken('/mailusers/send', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertSame(422, $sent->status);
        $this->assertStringContainsString($this->escaped(\App\Lang::t('mailusers.err_cap_day',
            ['n' => number_format($this->eligibleTotal()), 'room' => number_format(20)])), $sent->body);
        $this->assertSame($before, substr_count($this->mail(), 'Subject: '), 'no mail left the archive');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM mass_mail_log')['c'], 'the cooldown is not burned');
    }

    public function test_a_broadcast_over_the_monthly_cap_names_the_month(): void
    {
        $this->seedMembers(25); // 28 eligible; monthly cap 20 binds first
        $admin = $this->client($this->adminId, ['mail_daily_cap' => 500, 'mail_monthly_cap' => 20]);
        $sent = $admin->postWithToken('/mailusers/send', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertSame(422, $sent->status);
        $this->assertStringContainsString($this->escaped(\App\Lang::t('mailusers.err_cap_month',
            ['n' => number_format($this->eligibleTotal()), 'room' => number_format(20)])), $sent->body);
        $this->assertSame('', $this->mail());
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM mass_mail_log')['c']);
    }

    public function test_a_broadcast_to_exactly_the_room_sends(): void
    {
        while ($this->eligibleTotal() < 20) $this->seedMembers(1); // exactly the room at cap 30 (usable 20)
        $admin = $this->client($this->adminId, ['mail_daily_cap' => 30]);
        $res = $admin->postWithToken('/mailusers/send', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('Sent to ' . $this->eligibleTotal() . ' of ' . $this->eligibleTotal(), $res->body);
    }

    public function test_the_preview_fit_note_is_advisory_and_the_send_still_refuses(): void
    {
        while ($this->eligibleTotal() < 20) $this->seedMembers(1); // 20 eligible: the preview says the message fits
        $admin = $this->client($this->adminId, ['mail_daily_cap' => 30]);
        $preview = $admin->postWithToken('/mailusers/preview', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertStringContainsString(\App\Lang::t('mailusers.fits'), $preview->body);
        $this->assertStringContainsString(\App\Lang::t('mailusers.fits_advisory'), $preview->body);
        // Another send takes the last slot between the preview and the POST.
        $this->db->query('INSERT INTO mail_sends (day, sent) VALUES (?, 1)', [gmdate('Y-m-d')]);
        $sent = $admin->postWithToken('/mailusers/send', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertSame(422, $sent->status, 'the POST re-checks and refuses');
        $this->assertSame('', $this->mail());
    }

    #[Group('process')]
    public function test_the_command_refuses_a_broadcast_that_does_not_fit(): void
    {
        $this->seedMembers(25); // 28 eligible, room 20 at cap 30
        $out = (string) shell_exec(sprintf(
            'KIP_DB_DSN=%s KIP_MAIL_DAILY_CAP=30 KIP_STATIC_CACHE_DIR=%s %s %s mail:users "Hello all" "Big news." --commit 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg($this->root . '/static'),
            escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__, 4) . '/bin/kip')));
        $this->assertStringContainsString(\App\Lang::t('mailusers.err_cap_day',
            ['n' => number_format($this->eligibleTotal()), 'room' => number_format(20)]), $out);
        $this->assertSame('', $this->mail());
    }

    /** The CLI exit code check rides the same refusal; assert it separately
     *  from the output text (shell_exec hides the exit code). */
    #[Group('process')]
    public function test_the_command_exits_non_zero_on_a_refused_broadcast(): void
    {
        while ($this->eligibleTotal() < 30) $this->seedMembers(1); // room 20 at cap 30
        $cmd = sprintf('KIP_DB_DSN=%s KIP_MAIL_DAILY_CAP=30 KIP_STATIC_CACHE_DIR=%s %s %s mail:users "Hello all" "Big news." --commit >/dev/null 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg($this->root . '/static'),
            escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__, 4) . '/bin/kip'));
        exec($cmd, $ignored, $code);
        $this->assertSame(1, $code, 'a refused broadcast exits non-zero');
        $this->assertSame('', $this->mail());
    }

    public function test_the_compose_screen_shows_the_usage_lines(): void
    {
        $this->db->query('INSERT INTO mail_sends (day, sent) VALUES (?, 42)', [gmdate('Y-m-d')]);
        // The month row plus today's row is what the month line shows (the
        // monthly sum counts every bulk row in the UTC month).
        $this->db->query('INSERT INTO mail_sends (day, sent) VALUES (?, 568)', [gmdate('Y-m-01')]);
        $body = $this->client($this->adminId, ['mail_daily_cap' => 150, 'mail_monthly_cap' => 2000])->get('/mailusers')->body;
        $this->assertStringContainsString(\App\Lang::t('mailusers.usage_day', ['sent' => number_format(42), 'total' => number_format(140)]), $body);
        $this->assertStringContainsString(\App\Lang::t('mailusers.usage_month', ['sent' => number_format(610), 'total' => number_format(2000)]), $body);
        $this->assertStringContainsString(\App\Lang::t('mailusers.usage_help'), $body);
        $this->assertStringNotContainsString(\App\Lang::t('mailusers.warn_close'), $body, '42 of 140 is under the warning line');
    }

    public function test_the_usage_lines_warn_at_eighty_percent(): void
    {
        $this->db->query('INSERT INTO mail_sends (day, sent) VALUES (?, 112)', [gmdate('Y-m-d')]); // 112 of 140 usable
        $body = $this->client($this->adminId, ['mail_daily_cap' => 150])->get('/mailusers')->body;
        $this->assertStringContainsString(\App\Lang::t('mailusers.warn_close'), $body);
        $this->db->query('UPDATE mail_sends SET sent = 111 WHERE day = ?', [gmdate('Y-m-d')]);
        $body = $this->client($this->adminId, ['mail_daily_cap' => 150])->get('/mailusers')->body;
        $this->assertStringNotContainsString(\App\Lang::t('mailusers.warn_close'), $body, '111 of 140 stays under the warning line');
    }

    public function test_an_uncapped_compose_screen_gains_nothing(): void
    {
        // The open-source shape: no cap keys, no usage lines, no warning.
        $body = $this->client($this->adminId)->get('/mailusers')->body;
        $this->assertStringNotContainsString(\App\Lang::t('mailusers.usage_day', ['sent' => '0', 'total' => '0']), $body);
        $this->assertStringNotContainsString(\App\Lang::t('mailusers.usage_help'), $body);
        $this->assertStringNotContainsString(\App\Lang::t('mailusers.warn_close'), $body);
    }

    public function test_the_preview_shows_the_usage_lines_and_the_fit_verdict(): void
    {
        $this->db->query('INSERT INTO mail_sends (day, sent) VALUES (?, 42)', [gmdate('Y-m-d')]);
        $admin = $this->client($this->adminId, ['mail_daily_cap' => 150, 'mail_monthly_cap' => 2000]);
        $preview = $admin->postWithToken('/mailusers/preview', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertStringContainsString(\App\Lang::t('mailusers.usage_day', ['sent' => number_format(42), 'total' => number_format(140)]), $preview->body);
        $this->assertStringContainsString(\App\Lang::t('mailusers.fits'), $preview->body);
        $this->db->query('UPDATE mail_sends SET sent = 140 WHERE day = ?', [gmdate('Y-m-d')]);
        $full = $admin->postWithToken('/mailusers/preview', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertStringContainsString(\App\Lang::t('mailusers.fits_not'), $full->body);
    }
}
