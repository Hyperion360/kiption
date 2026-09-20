<?php // tests/SeriesTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class SeriesTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-series-') . '.sqlite';
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
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-series-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-series-upl'],
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

    public function test_public_series_page_renders_for_guests(): void
    {
        $res = $this->client()->get('/series/view/down-the-rabbit-hole');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Down the Rabbit Hole', $res->body);
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole"', $res->body);
        $this->assertStringContainsString('href="/user/view/demo-author"', $res->body);
        $this->assertStringContainsString('Demo Author', $res->body);
        $this->assertStringNotContainsString('Remove', $res->body); // guests see no management UI
    }

    public function test_owner_sees_management_and_members_see_submit_form(): void
    {
        $owner = $this->client($this->authorId()); // Demo Author
        $body = $owner->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertStringContainsString('Remove', $body);
        $member = $this->client($this->memberId()); // betafriend
        $this->assertStringContainsString('Add your story', $member->get('/series/view/down-the-rabbit-hole')->body); // open series
    }

    public function test_story_view_links_its_series(): void
    {
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('href="/series/view/down-the-rabbit-hole"', $body);
        $this->assertStringContainsString('Down the Rabbit Hole', $body);
    }

    public function test_series_page_is_static_cacheable_and_purges(): void
    {
        // StaticCacheTest idiom (finding 3): TestClient responses never carry
        // X-Static-Cache; exercise Cache directly against a temp dir.
        $dir = sys_get_temp_dir() . '/kiption-series-cache-' . uniqid('', true);
        $cache = new \App\StaticCache\Cache($dir);
        $req = new \Kip\Http\Request('GET', '/series/view/down-the-rabbit-hole', [], [], []);
        $cache->maybeStore($req, $this->app->handle($req));
        $this->assertNotNull($cache->serve($req)); // whitelist accepted it, fill worked
        // adding an item fires the purge wiring (controller path, real cache dir)
        $this->client($this->authorId())->postWithToken('/series/add/down-the-rabbit-hole', ['story_slug' => 'after-hours']);
        // the fresh dynamic render now carries the new member
        $fresh = $this->app->handle($req);
        $this->assertStringContainsString('href="/story/view/after-hours"', $fresh->body);
        // and the purge method itself empties a filled entry
        $cache->maybeStore($req, $fresh);
        $cache->purgeSeries('down-the-rabbit-hole');
        $this->assertNull($cache->serve($req));
        // through-controller purge wiring (files under public/cache) is verified
        // live in Task 9's ad-hoc-server smoke, not here (controllers hardcode the
        // real cache dir; tests never write into it).
    }

    public function test_soft_deleted_story_leaves_the_series_page(): void
    {
        $this->db->query("UPDATE stories SET deleted_at = ? WHERE slug = 'the-rabbit-hole'", [date('c')]);
        $guest = $this->client()->get('/series/view/down-the-rabbit-hole');
        $this->assertSame(200, $guest->status);
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $guest->body);
        $owner = $this->client($this->authorId())->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $owner); // everyone, not just guests
    }

    public function test_restricted_member_story_hidden_from_guests_on_series(): void
    {
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'after-hours'");
        $this->client($this->authorId())->postWithToken('/series/add/down-the-rabbit-hole', ['story_slug' => 'after-hours']);
        $guest = $this->client()->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertStringNotContainsString('href="/story/view/after-hours"', $guest);
        $this->assertStringNotContainsString('After Hours', $guest);
        $member = $this->client($this->memberId())->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertStringContainsString('href="/story/view/after-hours"', $member); // members still see it
    }

    public function test_series_json_ld_urls_are_absolute(): void
    {
        $body = $this->client()->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertStringContainsString('"url":"https://archive.example/story/view/the-rabbit-hole"', $body);
    }

    public function test_item_count_matches_the_visible_items_for_guests(): void
    {
        // the only /story/view/ links on a series page are its item rows
        $normal = $this->client()->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertSame(1, substr_count($normal, 'href="/story/view/'));
        $this->assertStringContainsString('1 works', $normal);

        // restricted-only fixture: the guest count must drop with the list
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $restricted = $this->client()->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertSame(0, substr_count($restricted, 'href="/story/view/'));
        $this->assertStringContainsString('0 works', $restricted);
        // a member still sees both the row and the count
        $member = $this->client($this->memberId())->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertSame(1, substr_count($member, 'href="/story/view/'));
        $this->assertStringContainsString('1 works', $member);

        // soft-deleted items count for nobody
        $this->db->query("UPDATE stories SET is_restricted = 0, deleted_at = ? WHERE slug = 'the-rabbit-hole'", [date('c')]);
        $deleted = $this->client()->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertSame(0, substr_count($deleted, 'href="/story/view/'));
        $this->assertStringContainsString('0 works', $deleted);
    }

    public function test_unknown_series_404s(): void
    {
        $this->assertSame(404, $this->client()->get('/series/view/nope')->status);
    }
}
