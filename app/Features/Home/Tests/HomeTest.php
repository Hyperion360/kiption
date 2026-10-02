<?php // app/Features/Home/Tests/HomeTest.php
namespace App\Features\Home\Tests;
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
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite::memory:'],
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ]);
        $app->container->instance(Database::class, $db);
        return $app;
    }

    /** The S1 layout: Latest carries at most six M6 cards from the one
     *  UNION ALL query and links on to the full recent listing; an empty
     *  archive says so instead of rendering an empty list. */
    public function test_home_latest_shelf_caps_at_six_and_links_recent(): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $empty = $this->appOver($db)->handle(new Request('GET', '/', [], [], []))->body;
        $this->assertStringContainsString('id="home-latest"', $empty);
        $this->assertStringContainsString('<p class="empty-state">', $empty, 'no stories, no empty list');
        \App\Seeder::run($db);
        for ($i = 1; $i <= 7; $i++) {
            $db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, word_count, updated_at)
                        SELECT ?, ?, 's.', author_id, rating_id, 1, 100, ? FROM stories WHERE slug = 'the-rabbit-hole'",
                ["Extra {$i}", "extra-{$i}", sprintf('2026-09-%02dT10:00:00Z', 20 + $i)]);
        }
        $body = $this->appOver($db)->handle(new Request('GET', '/', [], [], []))->body;
        $this->assertSame(6, substr_count($body, '<li class="story-card">'), 'Latest stops at six');
        $this->assertStringContainsString('Extra 7', $body, 'newest first');
        $this->assertStringContainsString('<a href="/browse/recent">All recently updated stories</a>', $body);
        $this->assertStringNotContainsString('being set up', $body, 'the stale setup copy is gone');
    }

    private function appOver(Database $db): App
    {
        $app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
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

    public function test_layout_reflects_legacy_light_cookie_as_paper(): void
    {
        $res = $this->app()->handle(new Request('GET', '/', [], [], ['theme' => 'light']));
        $this->assertStringContainsString('data-theme="paper"', $res->body);
    }

    public function test_layout_reflects_legacy_dark_cookie_as_night(): void
    {
        $res = $this->app()->handle(new Request('GET', '/', [], [], ['theme' => 'dark']));
        $this->assertStringContainsString('data-theme="night"', $res->body);
    }
}
