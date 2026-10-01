<?php // tests/ThemeValuesTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

// C1: the redesign's theme value set (paper / sepia / night / auto). Pre-0.5
// archives stored light / dark; they map once, at cookie read time, so a
// returning visitor's old cookie keeps working without a data migration of
// browsers. auto is the explicit "no preference" choice: it reads as null so
// the layout omits data-theme and prefers-color-scheme decides, which is also
// what keeps cookieless cached pages theme-neutral bytes.
final class ThemeValuesTest extends TestCase
{
    private function app(): App
    {
        // The home render reads users (the operator block), so the app needs a
        // schema: the HomeTest idiom, a migrated in-memory database shared
        // into the container.
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite::memory:'],
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ]);
        $app->container->instance(Database::class, $db);
        return $app;
    }

    private function render(array $cookies): string
    {
        return $this->app()->handle(new Request('GET', '/', [], [], $cookies))->body;
    }

    public function test_current_maps_legacy_cookies(): void
    {
        $this->assertSame('paper', \App\Theme::current(new Request('GET', '/', [], [], ['theme' => 'light'])));
        $this->assertSame('night', \App\Theme::current(new Request('GET', '/', [], [], ['theme' => 'dark'])));
    }

    public function test_current_accepts_the_new_values(): void
    {
        foreach (['paper', 'sepia', 'night'] as $v) {
            $this->assertSame($v, \App\Theme::current(new Request('GET', '/', [], [], ['theme' => $v])));
        }
    }

    public function test_current_returns_null_for_auto_garbage_and_absent(): void
    {
        foreach (['auto', 'hotdog', 'AUTO', 'paper '] as $v) {
            $this->assertNull(\App\Theme::current(new Request('GET', '/', [], [], ['theme' => $v])));
        }
        $this->assertNull(\App\Theme::current(new Request('GET', '/', [], [], [])));
    }

    public function test_array_cookie_never_fatals_the_render(): void
    {
        // PHP parses Cookie: theme[]=x into an array in $_COOKIE, which the
        // legacy map lookup would TypeError on (red-team finding, verified by
        // execution: one crafted header 500'd every themed page). A non-string
        // cookie is simply absent.
        $this->assertNull(\App\Theme::current(new Request('GET', '/', [], [], ['theme' => ['x']])));
        $body = $this->render(['theme' => ['x'], 'reader' => ['y']]);
        $this->assertStringNotContainsString('data-theme=', $body,
            'an array theme cookie renders theme-neutral');
        $this->assertStringNotContainsString('data-mode=', $body,
            'an array reader cookie renders pref-neutral');
    }

    public function test_layout_emits_the_new_data_theme_values(): void
    {
        $this->assertStringContainsString('data-theme="sepia"', $this->render(['theme' => 'sepia']));
        $this->assertStringNotContainsString('data-theme=', $this->render(['theme' => 'auto']),
            'auto is the explicit no-preference choice: no attribute, prefers-color-scheme decides');
        $this->assertStringContainsString('data-theme="night"', $this->render(['theme' => 'dark']),
            'a legacy dark cookie renders as night');
        $this->assertStringNotContainsString('data-theme=', $this->render([]),
            'cookieless stays theme-neutral (the static-cache byte contract)');
    }
}
