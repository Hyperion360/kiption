<?php // tests/ListsTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ListsTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-lists-') . '.sqlite';
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
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-lists-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-lists-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    /** The factory keeps the App instance as $this->app: TestClient drives
     *  App::handle directly, which bypasses the static cache entirely (the
     *  SeriesTest finding); drive $this->app->handle(new Request(...)) when a
     *  request needs a cache roundtrip. */
    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    /** Comfort reads fixture: betafriend's public list holding the seeded story. */
    private function seedComfortReads(): void
    {
        $sid = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db->query('INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, ?, ?, ?, 1)',
            [$this->memberId(), 'Comfort reads', 'comfort-reads', 'Stories for bad days.']);
        $this->db->query('INSERT INTO reading_list_items (list_id, story_id, position, note) VALUES ((SELECT id FROM reading_lists WHERE slug = ?), ?, 1, ?)',
            ['comfort-reads', $sid, 'Start here.']);
    }

    public function test_public_list_page_lists_stories(): void
    {
        $this->seedComfortReads();
        $res = $this->client()->get('/lists/view/comfort-reads');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Comfort reads', $res->body);
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole"', $res->body);
        $this->assertStringContainsString('Start here.', $res->body);
        $this->assertStringContainsString('betafriend', $res->body); // owner byline links the profile
        // private lists 404 for guests, render for the owner
        $this->db->query('INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, ?, ?, ?, 0)',
            [$this->memberId(), 'Secret', 'secret-list', '']);
        $this->assertSame(404, $this->client()->get('/lists/view/secret-list')->status);
        $this->assertSame(200, $this->client($this->memberId())->get('/lists/view/secret-list')->status);
        $this->assertSame(404, $this->client()->get('/lists/view/nope')->status);
    }

    public function test_member_creates_edits_and_the_slug_is_generated(): void
    {
        $me = $this->client($this->memberId());
        $this->assertSame(302, $me->postWithToken('/lists/create', ['title' => 'My TBR', 'summary' => 'To read.', 'is_public' => '1'])->status);
        $db = $this->db();
        $this->assertNotNull($db->one("SELECT * FROM reading_lists WHERE slug = 'my-tbr' AND is_public = 1"));
        // collision suffixes; title required; junk visibility coerced
        $this->assertSame(302, $me->postWithToken('/lists/create', ['title' => 'My TBR', 'summary' => '', 'is_public' => ''])->status);
        $this->assertNotNull($db->one("SELECT * FROM reading_lists WHERE slug = 'my-tbr-2' AND is_public = 0"));
        $this->assertSame(422, $me->postWithToken('/lists/create', ['title' => '', 'summary' => '', 'is_public' => '1'])->status);
        $this->assertSame(302, $me->postWithToken('/lists/update/my-tbr', ['title' => 'My TBR 2', 'summary' => 'To read.', 'is_public' => '1'])->status);
        $this->assertSame(403, $this->client($this->authorId())->post('/lists/update/my-tbr', ['title' => 'X', 'summary' => '', 'is_public' => '1'])->status, 'CSRF-first');
        // finding 11: ownership with a VALID token -> own()'s 404
        $this->assertSame(404, $this->client($this->authorId())->postWithToken('/lists/update/my-tbr', ['title' => 'X', 'summary' => '', 'is_public' => '1'])->status);
    }

    public function test_edit_form_prefills_and_renders_for_the_owner(): void
    {
        $this->seedComfortReads();
        $owner = $this->client($this->memberId());
        $res = $owner->get('/lists/edit/comfort-reads');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Comfort reads', $res->body);
        $this->assertStringContainsString('/lists/update/comfort-reads', $res->body);
        // the create form renders for any member
        $this->assertSame(200, $this->client($this->authorId())->get('/lists/new')->status);
        // ownership: another member's edit GET is own()'s 404 too
        $this->assertSame(404, $this->client($this->authorId())->get('/lists/edit/comfort-reads')->status);
    }

    public function test_owner_sees_management_ui_on_their_list(): void
    {
        $this->seedComfortReads();
        $guest = $this->client()->get('/lists/view/comfort-reads')->body;
        $this->assertStringNotContainsString('/lists/edit/comfort-reads', $guest); // guests get no management UI
        $owner = $this->client($this->memberId())->get('/lists/view/comfort-reads')->body;
        $this->assertStringContainsString('/lists/edit/comfort-reads', $owner);
        $this->assertStringContainsString('/lists/delete/comfort-reads', $owner);
    }

    public function test_owner_deletes_their_list(): void
    {
        $this->seedComfortReads();
        // ownership with a VALID token (finding 11): another member's delete is own()'s 404
        $this->assertSame(404, $this->client($this->authorId())->postWithToken('/lists/delete/comfort-reads')->status);
        $this->assertSame(302, $this->client($this->memberId())->postWithToken('/lists/delete/comfort-reads')->status);
        $this->assertNull($this->db()->one("SELECT * FROM reading_lists WHERE slug = 'comfort-reads'"));
        $this->assertNull($this->db()->one("SELECT * FROM reading_list_items")); // items cascade with the list
        $this->assertSame(404, $this->client()->get('/lists/view/comfort-reads')->status);
    }

    public function test_lists_forms_require_auth(): void
    {
        $this->assertSame(302, $this->client()->get('/lists/new')->status); // auth redirect
        $this->assertSame(403, $this->client()->post('/lists/create', ['title' => 'Nope', 'summary' => '', 'is_public' => '1'])->status); // CSRF before auth: tokenless POST is 403
    }

    public function test_restricted_unvalidated_and_deleted_stories_leave_the_blob(): void
    {
        // the restricted lesson: guests lose the row inside the blob, members keep it
        $this->seedComfortReads();
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $guest = $this->client()->get('/lists/view/comfort-reads')->body;
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $guest);
        $member = $this->client($this->authorId())->get('/lists/view/comfort-reads')->body;
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole"', $member);
        // unvalidated and soft-deleted items leave for everyone (the blob's gates)
        $this->db->query("UPDATE stories SET is_restricted = 0, validated = 0 WHERE slug = 'the-rabbit-hole'");
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $this->client()->get('/lists/view/comfort-reads')->body);
        $this->db->query("UPDATE stories SET validated = 1, deleted_at = ? WHERE slug = 'the-rabbit-hole'", [date('c')]);
        $owner = $this->client($this->memberId())->get('/lists/view/comfort-reads')->body;
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $owner);
    }

    public function test_public_list_page_is_one_query(): void
    {
        // the budget probe lives here, not in QueryBudgetTest: the seeder plants
        // no list, so the fixture row rides this test's own DB (the search-page
        // precedent); /lists/view is a budget-1 public surface.
        $this->seedComfortReads();
        $app = $this->app;
        $db = $app->container->make(\Kip\Database::class);
        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        $res = $app->handle(new \Kip\Http\Request('GET', '/lists/view/comfort-reads', [], [], []));
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status);
        $this->assertLessThanOrEqual(1, $queries, "list page ran {$queries} content queries, budget is 1");
    }

    public function test_list_page_is_static_cacheable_and_purges(): void
    {
        // StaticCacheTest idiom: TestClient responses never carry X-Static-Cache;
        // exercise Cache directly against a temp dir.
        $this->seedComfortReads();
        $this->db->query('INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, ?, ?, ?, 0)',
            [$this->memberId(), 'Secret', 'secret-list', '']);
        $dir = sys_get_temp_dir() . '/kiption-lists-cache-' . uniqid('', true);
        $cache = new \App\StaticCache\Cache($dir);
        $req = new \Kip\Http\Request('GET', '/lists/view/comfort-reads', [], [], []);
        $cache->maybeStore($req, $this->app->handle($req));
        $this->assertNotNull($cache->serve($req)); // whitelist accepted it, fill worked
        // private lists never fill: the guest render is the SQL 404
        $privReq = new \Kip\Http\Request('GET', '/lists/view/secret-list', [], [], []);
        $cache->maybeStore($privReq, $this->app->handle($privReq));
        $this->assertNull($cache->serve($privReq));
        // the purge method itself empties a filled entry (finding 4's contract)
        $cache->purgeList('comfort-reads');
        $this->assertNull($cache->serve($req));
        // through-controller purge wiring (files under public/cache) is verified
        // live in the Task 8 smoke, not here (controllers hardcode the real cache
        // dir; tests never write into it).
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
