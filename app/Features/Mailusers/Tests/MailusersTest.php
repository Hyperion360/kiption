<?php // app/Features/Mailusers/Tests/MailusersTest.php
namespace App\Features\Mailusers\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The owner broadcast screen. */
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
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-mailusers-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-mailusers-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
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
            ["$name@e.test", password_hash('password123', PASSWORD_DEFAULT), $name, $role, $role === 'admin' ? 1 : 0, date('c'), date('c'), $name]);
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

    public function test_mails_dropped_by_the_daily_cap_are_not_counted_as_sent(): void
    {
        $res = $this->client($this->adminId, ['mail_daily_cap' => 10])->postWithToken('/mailusers/send', ['subject' => 'Hello all', 'body' => 'Big news.']);
        $this->assertStringContainsString('Sent to 0 of ' . $this->eligible(), $res->body);
        $this->assertSame('', $this->mail());
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
}
