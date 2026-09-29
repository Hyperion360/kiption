<?php // app/Features/Theme/Tests/ThemeTest.php
namespace App\Features\Theme\Tests;
use Kip\App;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function test_current_returns_null_without_cookie(): void
    {
        $this->assertNull(\App\Theme::current(new Request('GET', '/', [], [], [])));
    }

    public function test_current_reads_light_cookie(): void
    {
        $req = new Request('GET', '/', [], [], ['theme' => 'light']);
        $this->assertSame('light', \App\Theme::current($req));
    }

    public function test_current_ignores_garbage_cookie(): void
    {
        $req = new Request('GET', '/', [], [], ['theme' => 'hotdog']);
        $this->assertNull(\App\Theme::current($req));
    }

    private function app(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite::memory:'],
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ]);
    }

    public function test_toggle_sets_cookie_and_redirects(): void
    {
        $res = $this->app()->handle(new Request('GET', '/theme/light', ['return_to' => '/browse'], [], []));
        $this->assertSame(302, $res->status);
        $this->assertSame('/browse', $res->headers['Location']);
        $this->assertStringContainsString('theme=light', $res->headers['Set-Cookie']);
        $this->assertStringContainsString('SameSite=Lax', $res->headers['Set-Cookie']);
    }

    public function test_toggle_rejects_unsafe_return(): void
    {
        $res = $this->app()->handle(new Request('GET', '/theme/dark', ['return_to' => '//evil.example'], [], []));
        $this->assertSame('/', $res->headers['Location']);
    }
}
