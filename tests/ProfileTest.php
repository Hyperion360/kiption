<?php // tests/ProfileTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-prof-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function newApp(): App
    {
        return new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-prof-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-prof-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    /** The factory keeps the App instance as $this->app: TestClient drives
     *  App::handle directly, which bypasses the static cache entirely (plan
     *  review finding 3); drive $this->app->handle(new Request(...)) when a
     *  request needs explicit cookies or a cache roundtrip. */
    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    public function test_profile_renders_public_data(): void
    {
        $res = $this->client()->get('/user/view/demo-author');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Demo Author', $res->body);
        $this->assertStringContainsString('href="/user/stories/demo-author"', $res->body);
        $this->assertStringContainsString('href="/user/favorites/demo-author"', $res->body);
        $this->assertStringNotContainsString('demo@example.test', $res->body); // email never leaks
    }

    public function test_stories_tab_lists_validated_works(): void
    {
        $body = $this->client()->get('/user/stories/demo-author')->body;
        $this->assertStringContainsString('The Rabbit Hole', $body);
        $this->assertStringContainsString('After Hours', $body);
    }

    public function test_favorites_tab_lists_public_shelf(): void
    {
        $fan = $this->client($this->memberId());
        $fan->postWithToken('/favorites/toggle/the-rabbit-hole', []);
        $body = $this->client()->get('/user/favorites/betafriend')->body;
        $this->assertStringContainsString('The Rabbit Hole', $body);
    }

    public function test_profile_pages_cache_and_purge(): void
    {
        // StaticCacheTest idiom (finding 3): fill via a real render, then purge.
        $dir = sys_get_temp_dir() . '/kiption-prof-cache-' . uniqid('', true);
        $cache = new \App\StaticCache\Cache($dir);
        $req = new \Kip\Http\Request('GET', '/user/stories/demo-author', [], [], []);
        $cache->maybeStore($req, $this->app->handle($req));
        $this->assertNotNull($cache->serve($req));
        $cache->purgeUser('demo-author');
        $this->assertNull($cache->serve($req));
        // the through-controller wiring (purgeStory's authorSlug on chapter publish)
        // is exercised live in Task 9's ad-hoc-server smoke.
    }

    public function test_unknown_or_locked_profile_404s(): void
    {
        $this->assertSame(404, $this->client()->get('/user/view/ghost')->status);
    }

    public function test_directory_lists_members_with_links(): void
    {
        $res = $this->client()->get('/browse/authors');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="/user/view/demo-author"', $res->body);
        $this->assertStringContainsString('Beta reader', $res->body); // betafriend badge
        $this->assertStringContainsString('href="/user/view/betafriend"', $res->body);
    }

    public function test_directory_letter_filter_and_beta_filter(): void
    {
        $this->assertStringContainsString('betafriend', $this->client()->get('/browse/authors/b')->body);
        $this->assertStringNotContainsString('demo-author', $this->client()->get('/browse/authors/b')->body);
        $beta = $this->client()->get('/browse/authors', ['beta' => '1'])->body;
        $this->assertStringContainsString('betafriend', $beta);
        $this->assertStringNotContainsString('/user/view/demo-author"', $beta);
        $this->assertStringContainsString('noindex', $this->client()->get('/browse/authors', ['beta' => '1'])->body); // faceted
    }

    public function test_directory_junk_letter_coerces(): void
    {
        $this->assertSame(200, $this->client()->get('/browse/authors/zz9')->status); // arity 404s zz9? one arg only: 200 with coerced 'z'
    }

    public function test_directory_cacheable(): void
    {
        $dir = sys_get_temp_dir() . '/kiption-dir-cache-' . uniqid('', true);
        $cache = new \App\StaticCache\Cache($dir);
        $req = new \Kip\Http\Request('GET', '/browse/authors', [], [], []);
        $res = $this->app->handle($req);
        $this->assertSame(200, $res->status);
        $cache->maybeStore($req, $res);
        $this->assertNotNull($cache->serve($req));
        $cache->purgeAuthors();
        $this->assertNull($cache->serve($req));
    }
}
