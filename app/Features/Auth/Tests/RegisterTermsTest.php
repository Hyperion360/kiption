<?php // app/Features/Auth/Tests/RegisterTermsTest.php
namespace App\Features\Auth\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The register form's terms line (config 'signup_terms_url',
 *  KIP_SIGNUP_TERMS_URL): one line under the submit button when the URL is
 *  set, nothing when it is not, and never a link for a scheme the footer
 *  stance rejects (the FooterLegal defensive precedent). */
final class RegisterTermsTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-terms-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(array $overrides = []): TestClient
    {
        $config = array_merge([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-terms-mail.log', 'from' => 'noreply@localhost'],
            'site_name' => 'Kiption',
            'base_url' => 'https://archive.example',
        ], $overrides);
        return new TestClient(new App($config));
    }

    private function body(array $overrides = []): string
    {
        return $this->client($overrides)->get('/auth/register')->body;
    }

    public function test_configured_https_url_renders_the_anchor(): void
    {
        $body = $this->body(['signup_terms_url' => 'https://example.test/terms']);
        $this->assertStringContainsString('By creating an account you accept the <a href="https://example.test/terms">the terms</a>.', $body);
    }

    public function test_site_relative_url_renders_the_anchor(): void
    {
        $body = $this->body(['signup_terms_url' => '/rules']);
        $this->assertStringContainsString('href="/rules"', $body);
        $this->assertStringContainsString('By creating an account you accept', $body);
    }

    public function test_unconfigured_renders_nothing(): void
    {
        $body = $this->body();
        $this->assertStringNotContainsString('auth-terms', $body);
        $this->assertStringNotContainsString('By creating an account you accept', $body);
    }

    public function test_javascript_url_renders_as_plain_text(): void
    {
        $body = $this->body(['signup_terms_url' => 'javascript:alert(1)']);
        $this->assertStringContainsString('By creating an account you accept the terms.', $body, 'the sentence still renders');
        $this->assertStringNotContainsString('href="javascript:', $body, 'the rejected scheme never becomes a link');
        $this->assertStringNotContainsString('<a href="javascript:alert(1)"', $body);
    }
}
