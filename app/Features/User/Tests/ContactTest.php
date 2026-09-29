<?php // app/Features/User/Tests/ContactTest.php
namespace App\Features\User\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ContactTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-contact-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-contact-mail-') . '.log';
        touch($this->mailLog);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
    }

    private function newApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-contact-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    public function test_contact_sends_mail_without_exposing_address(): void
    {
        $res = $this->client($this->memberId())->postWithToken('/user/contact/demo-author', ['body' => 'Loved the descent.']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('sent', strtolower($res->body));
        $this->assertStringNotContainsString('demo@example.test', $res->body);
        $mail = file_get_contents($this->mailLog);
        $this->assertStringContainsString('demo@example.test', $mail);
        $this->assertStringContainsString('Loved the descent.', $mail);
        $this->assertStringContainsString('/user/contact/betafriend', $mail); // reply path
    }

    public function test_contact_validates_and_throttles(): void
    {
        $me = $this->client($this->memberId());
        $this->assertSame(200, $me->get('/user/contact/demo-author')->status); // GET renders the form when logged in
        $this->assertSame(422, $me->postWithToken('/user/contact/demo-author', ['body' => ''])->status);
        $this->assertSame(422, $me->postWithToken('/user/contact/demo-author', ['body' => str_repeat('y', 5001)])->status);
        $this->assertSame(404, $me->postWithToken('/user/contact/ghost', ['body' => 'hi'])->status);
        $me->postWithToken('/user/contact/demo-author', ['body' => 'one']);
        $me->postWithToken('/user/contact/demo-author', ['body' => 'two']);
        $me->postWithToken('/user/contact/demo-author', ['body' => 'three']);
        $this->assertSame(429, $me->postWithToken('/user/contact/demo-author', ['body' => 'four'])->status);
    }

    public function test_contact_requires_auth(): void
    {
        $this->assertSame(302, $this->client()->get('/user/contact/demo-author')->status);
    }

    public function test_contact_self_404s(): void
    {
        // self-contact is the same 404 as an unknown slug: no channel to yourself
        $res = $this->client($this->memberId())->postWithToken('/user/contact/betafriend', ['body' => 'note to self']);
        $this->assertSame(404, $res->status);
    }
}
