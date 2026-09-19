<?php // tests/HomeTest.php
namespace App\Tests;
use Kip\App;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

final class HomeTest extends TestCase
{
    private function app(): App
    {
        return new App([
            'env' => 'prod',
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite::memory:'],
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ]);
    }

    public function test_home_page_renders_the_site_name(): void
    {
        $res = $this->app()->handle(new Request('GET', '/home/index', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<title>Kiption</title>', $res->body);
    }

    public function test_root_path_maps_to_home_index(): void
    {
        $res = $this->app()->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(200, $res->status);
    }
}
