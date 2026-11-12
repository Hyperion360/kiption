<?php // app/Features/Signupcheck/Tests/SignupCheckTest.php
namespace App\Features\Signupcheck\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Signup spam checks: honeypot, minimum fill time, the owner's question. */
final class SignupCheckTest extends TestCase
{
    private string $path = '';
    private string $log = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-sc-') . '.sqlite';
        $this->log = tempnam(sys_get_temp_dir(), 'kiption-sc-mail-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm'); @unlink($this->log);
    }

    private function client(array $over = []): TestClient
    {
        return new TestClient(new App($over + [
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->log, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-sc-upl'],
            'registration_mode' => 'open',
            'signup_min_seconds' => 1,
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
    }

    private function adminClient(): TestClient
    {
        $id = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        \App\Adminness::setRole($this->db, $id, 'admin');
        return $this->client()->actingAs($id);
    }

    private function stampFrom(string $html): string
    {
        preg_match('/name="form_ts" value="([^"]*)"/', $html, $m);
        return html_entity_decode($m[1] ?? '');
    }

    private function fields(string $stamp, array $over = []): array
    {
        return $over + ['penname' => 'newbie', 'email' => 'newbie@example.test', 'password' => 'password123', 'confirm' => 'password123',
            'homepage' => '', 'form_ts' => $stamp];
    }

    private function registered(): bool
    {
        return $this->db->one("SELECT 1 AS x FROM users WHERE penname = 'newbie'") !== null;
    }

    /** Load the form like a browser, wait out the minimum, post. */
    private function realSignup(TestClient $c, array $over = []): \Kip\Http\Response
    {
        $stamp = $this->stampFrom($c->get('/auth/register')->body);
        $this->assertNotSame('', $stamp, 'the form carries a stamp');
        sleep(2);
        return $c->postWithToken('/auth/store', $this->fields($stamp, $over));
    }

    public function test_a_real_signup_works(): void
    {
        $res = $this->realSignup($this->client());
        $this->assertSame(302, $res->status, $res->body);
        $this->assertTrue($this->registered());
    }

    public function test_the_form_has_a_hidden_honeypot_and_no_question_by_default(): void
    {
        $body = $this->client()->get('/auth/register')->body;
        $this->assertMatchesRegularExpression('/<div hidden[^>]*>.*name="homepage"/s', $body);
        $this->assertStringNotContainsString('check_answer', $body);
    }

    public function test_a_filled_honeypot_is_refused(): void
    {
        $c = $this->client();
        $res = $this->realSignup($c, ['homepage' => 'http://spam.example']);
        $this->assertSame(422, $res->status);
        $this->assertFalse($this->registered());
    }

    public function test_a_too_fast_submission_is_refused(): void
    {
        $c = $this->client(['signup_min_seconds' => 30]);
        $stamp = $this->stampFrom($c->get('/auth/register')->body);
        $res = $c->postWithToken('/auth/store', $this->fields($stamp));
        $this->assertSame(422, $res->status);
        $this->assertFalse($this->registered());
    }

    public function test_a_missing_or_forged_stamp_is_refused(): void
    {
        $c = $this->client();
        $c->get('/auth/register');
        $this->assertSame(422, $c->postWithToken('/auth/store', $this->fields(''))->status);
        $this->assertSame(422, $c->postWithToken('/auth/store', $this->fields((time() - 100) . '.' . str_repeat('a', 64)))->status);
        $this->assertFalse($this->registered());
    }

    public function test_a_stamp_from_another_session_does_not_transfer(): void
    {
        $stamp = $this->stampFrom($this->client()->get('/auth/register')->body);
        sleep(2);
        $other = $this->client();
        $other->get('/auth/register');
        $this->assertSame(422, $other->postWithToken('/auth/store', $this->fields($stamp))->status);
        $this->assertFalse($this->registered());
    }

    public function test_the_owner_question_is_required_when_on(): void
    {
        $this->db->query("INSERT INTO signup_check (id, enabled, question, answer) VALUES (1, 1, 'Name of this archive?', 'Kiption')");
        $c = $this->client();
        $this->assertStringContainsString('Name of this archive?', $c->get('/auth/register')->body);
        $this->assertSame(422, $this->realSignup($c)->status, 'no answer');
        $this->assertSame(422, $this->realSignup($c, ['check_answer' => 'Wrong'])->status);
        $this->assertFalse($this->registered());
        $this->assertSame(302, $this->realSignup($c, ['check_answer' => '  kiption '])->status, 'trimmed, case-insensitive');
        $this->assertTrue($this->registered());
    }

    public function test_a_question_that_is_switched_off_is_not_asked(): void
    {
        $this->db->query("INSERT INTO signup_check (id, enabled, question, answer) VALUES (1, 0, 'Name?', 'x')");
        $this->assertStringNotContainsString('check_answer', $this->client()->get('/auth/register')->body);
        $this->assertSame(302, $this->realSignup($this->client())->status);
    }

    public function test_admin_can_set_the_question_and_members_cannot(): void
    {
        $admin = $this->adminClient();
        $this->assertSame(200, $admin->get('/signupcheck')->status);
        $res = $admin->postWithToken('/signupcheck/save', ['enabled' => '1', 'question' => 'Archive name?', 'answer' => 'Kiption']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringContainsString('Archive name?', $this->client()->get('/auth/register')->body);
        $member = $this->client()->actingAs((int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id']);
        $this->assertSame(403, $member->get('/signupcheck')->status);
        $this->assertSame(403, $member->postWithToken('/signupcheck/save', ['enabled' => '0', 'question' => '', 'answer' => ''])->status);
        $this->assertSame(1, (int) $this->db->one('SELECT enabled FROM signup_check WHERE id = 1')['enabled'], 'a member changed nothing');
    }

    public function test_turning_it_on_needs_a_question_and_an_answer_and_a_csrf_token(): void
    {
        $admin = $this->adminClient();
        $this->assertSame(422, $admin->postWithToken('/signupcheck/save', ['enabled' => '1', 'question' => 'Q?', 'answer' => ''])->status);
        $this->assertNull($this->db->one('SELECT 1 AS x FROM signup_check'));
        $this->assertSame(403, $admin->post('/signupcheck/save', ['enabled' => '1', 'question' => 'Q?', 'answer' => 'A'], ['sec-fetch-site' => 'cross-site'])->status);
    }
}
