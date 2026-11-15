<?php // app/Features/Contact/Tests/ContactTest.php
namespace App\Features\Contact\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The public /contact form. */
final class ContactTest extends TestCase
{
    private string $path = '';
    private string $log = '';
    private Database $db;

    protected function setUp(): void
    {
        \App\Features::reset();
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-ct-') . '.sqlite';
        $this->log = tempnam(sys_get_temp_dir(), 'kiption-ct-mail-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        \App\Features::init($this->db, []);
        \App\Adminness::setRole($this->db, (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'], 'admin');
        \App\Adminness::setRole($this->db, (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'], 'admin');
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm'); @unlink($this->log);
        \App\Features::reset();
    }

    private function client(array $extra = []): TestClient
    {
        return new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->log, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-ct-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ] + $extra));
    }

    private function form(array $over = []): array
    {
        return $over + ['name' => 'Pat Reader', 'email' => 'pat@example.test', 'message' => 'Hello, a question about the archive.', 'website' => ''];
    }

    private function mail(): string
    {
        return (string) file_get_contents($this->log);
    }

    public function test_the_form_renders_with_a_hidden_honeypot(): void
    {
        $res = $this->client()->get('/contact');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('action="/contact/send"', $res->body);
        $this->assertStringContainsString('name="website"', $res->body);
        $this->assertMatchesRegularExpression('/<div hidden[^>]*>.*name="website"/s', $res->body);
    }

    public function test_removed_menu_links_stay_hidden_on_the_contact_page(): void
    {
        $navFile = tempnam(sys_get_temp_dir(), 'kiption-ct-nav-') . '.json';
        $this->db->query('DELETE FROM nav_links');
        $this->db->query('INSERT INTO nav_links (label, url, position) VALUES (?,?,?)', ['Recent', '/browse/recent', 1]);
        \App\NavLinks::rebuild($this->db, $navFile);
        $body = $this->client(['nav_file' => $navFile])->get('/contact')->body;
        $this->assertStringContainsString('href="/browse/recent"', $body);
        $this->assertStringNotContainsString('href="/browse/authors"', $body);
        $this->assertStringNotContainsString('href="/series"', $body);
        @unlink($navFile);
    }

    public function test_a_signed_in_member_is_not_offered_a_log_in_link(): void
    {
        $this->assertStringContainsString('<a class="nav-account" href="/auth/login">', $this->client()->get('/contact')->body);
        $id = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $body = $this->client()->actingAs($id)->get('/contact')->body;
        $this->assertStringContainsString('<a class="nav-account" href="/account">', $body);
        $this->assertStringNotContainsString('href="/auth/login"', $body);
        $link = $this->client()->actingAs($id)->get('/auth/link')->body;
        $this->assertStringContainsString('<a class="nav-account" href="/account">', $link);
    }

    public function test_a_message_goes_to_every_admin(): void
    {
        $c = $this->client();
        $res = $c->postWithToken('/contact/send', $this->form());
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('/contact?sent=1', $res->headers['Location'] ?? '');
        $mail = $this->mail();
        $this->assertStringContainsString('beta@example.test', $mail);
        $this->assertStringContainsString('demo@example.test', $mail);
        $this->assertStringContainsString('Pat Reader', $mail);
        $this->assertStringContainsString('a question about the archive', $mail);
        $this->assertStringContainsString('Thank you', $c->get('/contact', ['sent' => '1'])->body);
    }

    public function test_a_locked_admin_gets_nothing(): void
    {
        $this->db->query("UPDATE users SET is_locked = 1 WHERE penname = 'betafriend'");
        $this->client()->postWithToken('/contact/send', $this->form());
        $this->assertStringNotContainsString('beta@example.test', $this->mail());
        $this->assertStringContainsString('demo@example.test', $this->mail());
    }

    public function test_the_honeypot_is_dropped_silently(): void
    {
        $res = $this->client()->postWithToken('/contact/send', $this->form(['website' => 'http://spam.example']));
        $this->assertSame(302, $res->status);
        $this->assertSame('/contact?sent=1', $res->headers['Location'] ?? '', 'looks like success to a bot');
        $this->assertSame('', $this->mail());
        // The cheap branch is not free: a honeypot hit spends a throttle slot
        // like a real send would, so a bot cannot probe at unlimited rate.
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM login_attempts WHERE kind = 'contact'")['c']);
    }

    public function test_the_fourth_message_in_a_window_is_rate_limited(): void
    {
        $c = $this->client();
        for ($i = 0; $i < 3; $i++) $this->assertSame(302, $c->postWithToken('/contact/send', $this->form())->status);
        $res = $c->postWithToken('/contact/send', $this->form(['message' => 'fourth']));
        $this->assertSame(429, $res->status);
        $this->assertStringNotContainsString('fourth', $this->mail());
    }

    public function test_bad_input_is_refused_and_sends_nothing(): void
    {
        $c = $this->client();
        $this->assertSame(422, $c->postWithToken('/contact/send', $this->form(['email' => 'nope']))->status);
        $this->assertSame(422, $c->postWithToken('/contact/send', $this->form(['name' => '']))->status);
        $this->assertSame(422, $c->postWithToken('/contact/send', $this->form(['message' => '   ']))->status);
        $this->assertSame(422, $c->postWithToken('/contact/send', $this->form(['message' => str_repeat('a', 5001)]))->status);
        $this->assertSame('', $this->mail());
    }

    public function test_a_blocked_word_is_refused_and_sends_nothing(): void
    {
        $this->db->query("INSERT INTO word_filter (word) VALUES ('zorbleflux')");
        \App\Features\Wordfilter\WordFilter::forget();
        $c = $this->client();
        $res = $c->postWithToken('/contact/send', $this->form(['message' => 'Hello, this has Zorbleflux in it.']));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Hello, this has Zorbleflux in it.', $res->body, 'the visitor keeps what they typed');
        $this->assertSame(422, $c->postWithToken('/contact/send', $this->form(['name' => 'zorbleflux']))->status);
        $this->assertSame('', $this->mail());
    }

    public function test_line_breaks_in_name_and_email_cannot_forge_headers(): void
    {
        $this->client()->postWithToken('/contact/send', $this->form(['name' => "Pat\r\nBcc: victim@example.test"]));
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $this->mail());
        $this->assertSame(422, $this->client()->postWithToken('/contact/send', $this->form(['email' => "pat@example.test\r\nBcc: x@example.test"]))->status);
    }

    public function test_cross_site_post_without_token_is_refused(): void
    {
        $res = $this->client()->post('/contact/send', $this->form(), ['sec-fetch-site' => 'cross-site']);
        $this->assertSame(403, $res->status);
        $this->assertSame('', $this->mail());
    }

    public function test_the_feature_flag_turns_it_off(): void
    {
        \App\Features::toggle('contact', false);
        $c = $this->client();
        $this->assertSame(404, $c->get('/contact')->status);
        $this->assertSame(404, $c->postWithToken('/contact/send', $this->form())->status);
        $this->assertSame('', $this->mail());
    }

    public function test_a_mail_dropped_by_the_daily_cap_is_not_reported_as_sent(): void
    {
        $res = $this->client(['mail_daily_cap' => 10])->postWithToken('/contact/send', $this->form());
        $this->assertSame(502, $res->status);
        $this->assertSame('', $this->mail());
    }

    public function test_no_admin_means_a_clear_refusal_not_a_silent_loss(): void
    {
        $this->db->query("UPDATE users SET role = 'member', is_admin = 0");
        $this->assertSame(503, $this->client()->postWithToken('/contact/send', $this->form())->status);
    }
}
