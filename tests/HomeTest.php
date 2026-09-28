<?php // tests/HomeTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Session;
use PHPUnit\Framework\TestCase;

final class HomeTest extends TestCase
{
    private function app(): App
    {
        // The home render reads users for the logged-in operator block (Task
        // 4's isAdmin datum), so the app needs a schema: a migrated
        // in-memory database shared into the container keeps this suite's
        // no-files style. Guests never touch it.
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite::memory:'],
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ]);
        $app->container->instance(Database::class, $db);
        return $app;
    }

    public function test_home_page_renders_the_site_name(): void
    {
        // canonical URL since kip c2f9730: only / is home
        $res = $this->app()->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<title>Kiption</title>', $res->body);
        $this->assertStringNotContainsString('Log out', $res->body); // guest sees no session-bound form
    }

    public function test_logged_in_home_shows_logout_form_with_csrf(): void
    {
        $store = [];
        $session = new Session($store);
        $session->set('user_id', 1);
        $res = $this->app()->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $session);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('action="/auth/logout"', $res->body);
        $this->assertStringContainsString('name="_token"', $res->body);
    }

    public function test_one_canonical_url_for_the_home_page(): void
    {
        $res = $this->app()->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(200, $res->status);
        // kip c2f9730: /home and any /controller/index spelling 404; / is the
        // home controller's only URL
        $this->assertSame(404, $this->app()->handle(new Request('GET', '/home', [], [], []))->status);
        $this->assertSame(404, $this->app()->handle(new Request('GET', '/home/index', [], [], []))->status);
    }

    public function test_layout_carries_theme_attribute_and_browse_link(): void
    {
        $res = $this->app()->handle(new Request('GET', '/', [], [], []));
        $this->assertStringNotContainsString('data-theme=', $res->body);
        $this->assertStringContainsString('href="/browse"', $res->body);
    }

    public function test_layout_reflects_light_cookie(): void
    {
        $res = $this->app()->handle(new Request('GET', '/', [], [], ['theme' => 'light']));
        $this->assertStringContainsString('data-theme="light"', $res->body);
    }

    public function test_layout_reflects_dark_cookie(): void
    {
        $res = $this->app()->handle(new Request('GET', '/', [], [], ['theme' => 'dark']));
        $this->assertStringContainsString('data-theme="dark"', $res->body);
    }
}
