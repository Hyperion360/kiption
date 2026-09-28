<?php // tests/WarningTest.php
namespace App\Tests;
use Kip\App;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

final class WarningTest extends TestCase
{
    private function app(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite::memory:'],
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ]);
    }

    public function test_accept_sets_cookie_and_redirects(): void
    {
        $res = $this->app()->handle(new Request('GET', '/warning/accept',
            ['return_to' => '/story/read/after-hours/1'], [], []));
        $this->assertSame(302, $res->status);
        $this->assertSame('/story/read/after-hours/1', $res->headers['Location']);
        $this->assertStringContainsString('age_ok=1', $res->headers['Set-Cookie']);
        $this->assertStringContainsString('Max-Age=31536000', $res->headers['Set-Cookie']);
    }

    public function test_accept_rejects_unsafe_return(): void
    {
        $res = $this->app()->handle(new Request('GET', '/warning/accept',
            ['return_to' => 'https://evil.example'], [], []));
        $this->assertSame('/', $res->headers['Location']);
    }
}
