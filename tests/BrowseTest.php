<?php // tests/BrowseTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class BrowseTest extends TestCase
{
    private string $dsn = '';
    private App $app;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kiption-browse-') . '.sqlite';
        $this->dsn = 'sqlite:' . $path;
        $db = new Database($this->dsn);
        (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
        $db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)', ['a@x.test', 'h', 'Demo Author']);
        $db->query('INSERT INTO ratings (label, position) VALUES (?, ?)', ['Teen', 1]);
        $db->query('INSERT INTO categories (name, slug) VALUES (?, ?)', ['General', 'general']);
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id, validated, updated_at) VALUES (?, ?, 1, 1, 1, ?)',
            ['The Rabbit Hole', 'the-rabbit-hole', '2026-09-01T10:00:00Z']);
        $db->query('INSERT INTO story_categories (story_id, category_id) VALUES (1, 1)');
        $this->app = new App([
            'env' => 'prod',
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => $this->dsn],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'items_per_page' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        unset($this->app);
        @unlink(substr($this->dsn, 7));
        @unlink(substr($this->dsn, 7) . '-wal');
        @unlink(substr($this->dsn, 7) . '-shm');
    }

    public function test_browse_lists_categories_with_counts(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('General', $res->body);
        $this->assertStringContainsString('1 stor', $res->body);
    }

    public function test_recent_lists_validated_stories(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse/recent', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('Demo Author', $res->body);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $res->body);
    }

    public function test_recent_page_param_coerced_to_page_one(): void
    {
        $default = $this->app->handle(new Request('GET', '/browse/recent', [], [], []));
        foreach (['banana', '-3', '0'] as $junk) {
            $res = $this->app->handle(new Request('GET', '/browse/recent', ['page' => $junk], [], []));
            $this->assertSame(200, $res->status);
            $this->assertSame($default->body, $res->body, "page={$junk} must coerce to page 1");
        }
    }

    public function test_category_lists_its_stories(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse/category/general', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('/browse/category/general?page=2', $res->body);
    }

    public function test_unknown_category_renders_honest_empty_state(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse/category/nope', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('No stories yet', $res->body);
    }

    public function test_huge_page_param_returns_empty_page_not_500(): void
    {
        foreach (['/browse/recent', '/browse/category/general'] as $path) {
            $res = $this->app->handle(new Request('GET', $path, ['page' => '99999999999999999999'], [], []));
            $this->assertSame(200, $res->status, "{$path} must not 500 on a huge page param");
            $this->assertStringContainsString('No stories yet', $res->body);
        }
    }

    public function test_h1_names_the_listing_on_each_route(): void
    {
        $recent = $this->app->handle(new Request('GET', '/browse/recent', [], [], []));
        $this->assertStringContainsString('<h1>Recently updated</h1>', $recent->body);
        $category = $this->app->handle(new Request('GET', '/browse/category/general', [], [], []));
        $this->assertStringContainsString('<h1>Category: general</h1>', $category->body);
    }
}
