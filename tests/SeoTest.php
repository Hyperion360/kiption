<?php // tests/SeoTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class SeoTest extends TestCase
{
    private string $path = '';
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-seo-') . '.sqlite';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($db);
        $this->app = new App([
            'env' => 'prod',
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-seo-upl'],
            'site_name' => 'Kiption',
            'base_url' => 'https://archive.example',
        ]);
    }

    protected function tearDown(): void
    {
        unset($this->app);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function get(string $uri): string
    {
        return $this->app->handle(new Request('GET', $uri, [], [], []))->body;
    }

    public function test_story_view_head(): void
    {
        $body = $this->get('/story/view/the-rabbit-hole');
        $this->assertStringContainsString('<title>The Rabbit Hole by Demo Author - Kiption</title>', $body);
        $this->assertStringContainsString('name="description" content="A slow fall into a stranger world."', $body);
        $this->assertStringContainsString('<link rel="canonical" href="https://archive.example/story/view/the-rabbit-hole">', $body);
        $this->assertStringContainsString('property="og:type" content="article"', $body);
        $this->assertStringContainsString('"@type":"Book"', $body);
        $this->assertStringContainsString('"@type":"CreativeWork"', $body); // chapters as hasPart
        $this->assertStringContainsString('rel="alternate" type="application/atom+xml"', $body);
    }

    public function test_meta_description_override_wins(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $db->query('UPDATE stories SET meta_description = ? WHERE slug = ?', ['Hand-written override.', 'the-rabbit-hole']);
        $this->assertSame('Hand-written override.', explode('"', explode('name="description" content="', $this->get('/story/view/the-rabbit-hole'))[1])[0]);
    }

    public function test_chapter_read_head(): void
    {
        $body = $this->get('/story/read/the-rabbit-hole/2');
        $this->assertStringContainsString('<title>Chapter 2: Through - The Rabbit Hole - Kiption</title>', $body);
        $this->assertStringContainsString('article:published_time', $body);
    }

    public function test_browse_heads_and_breadcrumbs(): void
    {
        $this->assertStringContainsString('<title>Browse - Kiption</title>', $this->get('/browse'));
        $body = $this->get('/browse/category/general');
        $this->assertStringContainsString('<title>Category: general - Kiption</title>', $body);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $body);
    }

    public function test_recent_carries_itemlist(): void
    {
        $this->assertStringContainsString('"@type":"ItemList"', $this->get('/browse/recent'));
    }

    public function test_empty_category_is_noindexed_and_headered(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse/category/nope', [], [], []));
        $this->assertStringContainsString('name="robots" content="noindex"', $res->body);
        $this->assertSame('noindex', $res->headers['X-Robots-Tag']);
    }

    public function test_og_image_config_renders(): void
    {
        $app = new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-seo-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'og_image' => '/assets/card.png',
        ]);
        $body = $app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []))->body;
        $this->assertStringContainsString('property="og:image" content="/assets/card.png"', $body);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $body);
    }

    public function test_home_ships_website_jsonld_and_feed_link(): void
    {
        $body = $this->get('/');
        $this->assertStringContainsString('<title>Kiption</title>', $body);
        $this->assertStringContainsString('"@type":"WebSite"', $body);
        $this->assertStringContainsString('"@type":"SearchAction"', $body);
    }
}
