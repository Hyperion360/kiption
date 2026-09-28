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
    private string $cacheDir = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-lists-') . '.sqlite';
        $this->cacheDir = sys_get_temp_dir() . '/kiption-lists-cfg-' . uniqid('', true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
    }

    private function newApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-lists-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-lists-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            // Config-injected static cache dir (the AdminstoriesController/Importer
            // pattern): the controllers' purge writes land here in tests, never in
            // the repo's public/cache, so through-controller purges are pinnable.
            'static_cache' => ['dir' => $this->cacheDir],
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

    /** betafriend's second list (private, empty) for the index and rider tests. */
    private function seedSecretList(): void
    {
        $this->db->query('INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, ?, ?, ?, 0)',
            [$this->memberId(), 'Secret', 'secret-list', '']);
    }

    /** An unvalidated story row (the add-by-slug reject fixture). */
    private function seedDraftTale(): void
    {
        $this->db->query(
            "INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, created_at, updated_at)
             SELECT 'Draft Tale', 'draft-tale', '', author_id, rating_id, 0, 0, 0, created_at, updated_at
             FROM stories WHERE slug = 'the-rabbit-hole'");
    }

    /** Fill the config-injected cache with the public list page (guest render). */
    private function fillListCache(): \App\StaticCache\Cache
    {
        $cache = new \App\StaticCache\Cache($this->cacheDir);
        $req = new \Kip\Http\Request('GET', '/lists/view/comfort-reads', [], [], []);
        $cache->maybeStore($req, $this->app->handle($req));
        return $cache;
    }

    /** @return array<string,int> story slug => position on comfort-reads,
     *  ordered by position so assertSame reads top-to-bottom */
    private function positions(): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT s.slug, li.position FROM reading_list_items li JOIN stories s ON s.id = li.story_id
             JOIN reading_lists l ON l.id = li.list_id WHERE l.slug = 'comfort-reads'") as $r) {
            $out[(string) $r['slug']] = (int) $r['position'];
        }
        asort($out);
        return $out;
    }

    private function itemId(string $storySlug): int
    {
        return (int) $this->db->one(
            "SELECT li.id FROM reading_list_items li JOIN stories s ON s.id = li.story_id
             JOIN reading_lists l ON l.id = li.list_id WHERE l.slug = 'comfort-reads' AND s.slug = ?",
            [$storySlug])['id'];
    }

    public function test_owner_adds_items_by_slug_with_honest_rejects(): void
    {
        $this->seedComfortReads();
        $this->seedDraftTale();
        $owner = $this->client($this->memberId());
        // any validated, non-deleted story adds; restricted is fine (the owner's
        // list, the owner's eyes; the PUBLIC page's blob hides it from guests)
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'after-hours'");
        $res = $owner->postWithToken('/lists/item/comfort-reads', ['story_slug' => 'after-hours', 'note' => 'Second.']);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one(
            "SELECT li.position, li.note FROM reading_list_items li JOIN stories s ON s.id = li.story_id
             JOIN reading_lists l ON l.id = li.list_id WHERE l.slug = 'comfort-reads' AND s.slug = 'after-hours'");
        $this->assertSame(2, (int) $row['position'], 'appends after the last item');
        $this->assertSame('Second.', $row['note']);
        $this->assertStringContainsString('Second.', $this->client($this->memberId())->get('/lists/view/comfort-reads')->body);
        // honest 422s: unknown slug, unvalidated slug, duplicate item (the UNIQUE)
        $res = $owner->postWithToken('/lists/item/comfort-reads', ['story_slug' => 'nope', 'note' => '']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('No story with that slug exists.', $res->body);
        $res = $owner->postWithToken('/lists/item/comfort-reads', ['story_slug' => 'draft-tale', 'note' => '']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('That story is not validated yet.', $res->body);
        $res = $owner->postWithToken('/lists/item/comfort-reads', ['story_slug' => 'after-hours', 'note' => '']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('That story is already on this list.', $res->body);
        // a soft-deleted story reads as unknown (it is gone from every surface)
        $this->db->query(
            "INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, created_at, updated_at, deleted_at)
             SELECT 'Gone Tale', 'gone-tale', '', author_id, rating_id, 1, 0, 0, created_at, updated_at, ?
             FROM stories WHERE slug = 'the-rabbit-hole'", [date('c')]);
        $res = $owner->postWithToken('/lists/item/comfort-reads', ['story_slug' => 'gone-tale', 'note' => '']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('No story with that slug exists.', $res->body);
        // ownership: another member's add (valid token) is own()'s 404; unknown
        // list slug is a 404 even for the owner
        $this->assertSame(404, $this->client($this->authorId())->postWithToken('/lists/item/comfort-reads', ['story_slug' => 'the-rabbit-hole', 'note' => ''])->status);
        $this->assertSame(404, $owner->postWithToken('/lists/item/nope', ['story_slug' => 'after-hours', 'note' => ''])->status);
        // the first item on a fresh, empty list lands at position 1 (MAX over
        // zero rows is one NULL row; the COALESCE idiom must not misreport
        // the insert as a duplicate)
        $this->assertSame(302, $owner->postWithToken('/lists/create', ['title' => 'Empty start', 'summary' => '', 'is_public' => ''])->status);
        $this->assertSame(302, $owner->postWithToken('/lists/item/empty-start', ['story_slug' => 'after-hours', 'note' => ''])->status);
        $first = $this->db->one(
            "SELECT li.position FROM reading_list_items li JOIN reading_lists l ON l.id = li.list_id WHERE l.slug = 'empty-start'");
        $this->assertSame(1, (int) $first['position']);
    }

    public function test_owner_removes_and_reorders_items(): void
    {
        $this->seedComfortReads();
        $owner = $this->client($this->memberId());
        $this->assertSame(302, $owner->postWithToken('/lists/item/comfort-reads', ['story_slug' => 'after-hours', 'note' => ''])->status);
        $this->assertSame(['the-rabbit-hole' => 1, 'after-hours' => 2], $this->positions());
        // the edit page carries the management section (add-by-slug lives there)
        $edit = $owner->get('/lists/edit/comfort-reads')->body;
        $this->assertStringContainsString('action="/lists/item/comfort-reads"', $edit);
        // swap up (the series swap idiom): after-hours rises over the rabbit hole
        $this->assertSame(302, $owner->postWithToken('/lists/move/comfort-reads/' . $this->itemId('after-hours') . '/up')->status);
        $this->assertSame(['after-hours' => 1, 'the-rabbit-hole' => 2], $this->positions());
        // and back down
        $this->assertSame(302, $owner->postWithToken('/lists/move/comfort-reads/' . $this->itemId('after-hours') . '/down')->status);
        $this->assertSame(['the-rabbit-hole' => 1, 'after-hours' => 2], $this->positions());
        // boundary: moving the top item up is a calm no-op
        $this->assertSame(302, $owner->postWithToken('/lists/move/comfort-reads/' . $this->itemId('the-rabbit-hole') . '/up')->status);
        $this->assertSame(['the-rabbit-hole' => 1, 'after-hours' => 2], $this->positions());
        // junk direction coerces (the browse page-param philosophy)
        $this->assertSame(302, $owner->postWithToken('/lists/move/comfort-reads/' . $this->itemId('after-hours') . '/sideways')->status);
        // the item write purges the cached public page (through-controller, config dir)
        $cache = $this->fillListCache();
        $req = new \Kip\Http\Request('GET', '/lists/view/comfort-reads', [], [], []);
        $this->assertNotNull($cache->serve($req));
        $this->assertSame(302, $owner->postWithToken('/lists/remove/comfort-reads/after-hours')->status);
        $this->assertNull($cache->serve($req), 'item removal purged the list page');
        $this->assertSame(['the-rabbit-hole' => 2], $this->positions(), 'removal keeps gaps: ordering is by position');
        // ownership: another member's item ops (valid token) are own()'s 404;
        // a guest's tokenless POST is the login 302 (gate before CSRF since kip
        // b29e269) and re-adds nothing
        $author = $this->client($this->authorId());
        $this->assertSame(404, $author->postWithToken('/lists/move/comfort-reads/' . $this->itemId('the-rabbit-hole') . '/up')->status);
        $this->assertSame(404, $author->postWithToken('/lists/remove/comfort-reads/the-rabbit-hole')->status);
        $this->assertSame(302, $this->client()->post('/lists/item/comfort-reads', ['story_slug' => 'after-hours', 'note' => ''])->status);
        $this->assertSame(['the-rabbit-hole' => 2], $this->positions(), 'the guest POST wrote no item');
    }

    public function test_move_rollback_restores_positions_when_a_swap_write_fails(): void
    {
        // The transactional swap's undo path: a failure between the sentinel
        // write and the restore must roll back cleanly, never stranding an
        // item on position -1. A RAISE trigger aborts the neighbour write
        // mid-swap; the repository rethrows and the positions survive intact.
        $this->seedComfortReads();
        $owner = $this->client($this->memberId());
        $this->assertSame(302, $owner->postWithToken('/lists/item/comfort-reads', ['story_slug' => 'after-hours', 'note' => ''])->status);
        $item = $this->itemId('after-hours'); // position 2
        $this->db()->query("CREATE TRIGGER swap_boom BEFORE UPDATE ON reading_list_items FOR EACH ROW WHEN NEW.id = $item BEGIN SELECT RAISE(ABORT, 'boom'); END");
        try {
            (new \App\Repositories\ListsRepository($this->db()))->move('comfort-reads', $item, 'up', $this->memberId());
            $this->fail('the aborted swap must rethrow');
        } catch (\PDOException) {
            // the repository's rollback arm ran and rethrew the driver error
        }
        $this->assertSame(['the-rabbit-hole' => 1, 'after-hours' => 2], $this->positions(), 'the rollback restored both positions');
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) FROM reading_list_items WHERE position = -1')['COUNT(*)'], 'no item strands on the sentinel');
        // with the fault cleared the same swap goes through: the table is healthy
        $this->db()->query('DROP TRIGGER swap_boom');
        $this->assertSame(302, $owner->postWithToken('/lists/move/comfort-reads/' . $item . '/up')->status);
        $this->assertSame(['after-hours' => 1, 'the-rabbit-hole' => 2], $this->positions());
    }

    public function test_member_lists_index_lists_own_lists_with_counts(): void
    {
        $this->seedComfortReads();
        $this->seedSecretList();
        $body = $this->client($this->memberId())->get('/lists')->body;
        $this->assertStringContainsString('href="/lists/view/comfort-reads"', $body);
        $this->assertStringContainsString('href="/lists/view/secret-list"', $body);
        $this->assertStringContainsString('Comfort reads', $body);
        $this->assertStringContainsString('Secret', $body);
        $this->assertStringContainsString('1 works', $body);  // comfort-reads holds one story
        $this->assertStringContainsString('0 works', $body);  // the private list is empty
        $this->assertStringContainsString('href="/lists/new"', $body);
        // own lists only: the author's index shows the honest empty state
        $mine = $this->client($this->authorId())->get('/lists')->body;
        $this->assertStringNotContainsString('comfort-reads', $mine);
        $this->assertStringContainsString('You have no reading lists yet.', $mine);
        // guests hit the auth redirect
        $this->assertSame(302, $this->client()->get('/lists')->status);
    }

    public function test_member_lists_index_is_one_query(): void
    {
        $this->seedComfortReads();
        $app = $this->newApp();
        $db = $app->container->make(\Kip\Database::class);
        $store = [];
        $session = new \Kip\Session($store);
        $session->set('user_id', $this->memberId());
        $hash = (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$this->memberId()])['password_hash'];
        $session->set('pwd_epoch', substr($hash, 0, \Kip\Auth::EPOCH_LEN));
        $queries = 0;
        $db->onQuery(function (string $sql) use (&$queries): void {
            if (!str_contains($sql, 'password_hash')) $queries++; // the auth gate's epoch SELECT is not content
        });
        $res = $app->handle(new \Kip\Http\Request('GET', '/lists', [], [], ['kip_test_session' => '1']), $session);
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertLessThanOrEqual(1, $queries, "lists index ran {$queries} content queries, budget is 1");
    }

    public function test_story_page_links_the_lists_page(): void
    {
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('href="/lists"', $body);
        $this->assertStringContainsString('Reading lists', $body);
    }

    public function test_restricted_flip_purges_the_cached_public_list(): void
    {
        // THE rider pin (finding 4): a story-side guest-visibility change must
        // purge every PUBLIC list containing the story, looked up caller-side
        // and passed through purgeStory's listSlugs.
        $this->seedComfortReads();
        $cache = $this->fillListCache();
        $req = new \Kip\Http\Request('GET', '/lists/view/comfort-reads', [], [], []);
        $this->assertNotNull($cache->serve($req));
        // without list slugs a story purge leaves the containing list file alone:
        // the parameter is load-bearing, not a blanket purgeAll
        $cache->purgeStory('the-rabbit-hole', []);
        $this->assertNotNull($cache->serve($req), 'no list slugs passed, no list unlink');
        // the real write path flips the story restricted; the rider purges the list
        $author = $this->client($this->authorId());
        $this->assertSame(302, $author->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.',
             'rating_id' => (string) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'],
             'categories' => [(string) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id']],
             'restricted' => '1'])->status);
        $this->assertNull($cache->serve($req), 'the story-side visibility change purged the public list');
        // stale-CORRECT, not just stale-gone: the rebuilt guest page hides the story
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $this->client()->get('/lists/view/comfort-reads')->body);
    }

    public function test_public_list_slugs_for_story_is_public_only(): void
    {
        $this->seedComfortReads();
        $this->seedSecretList();
        $sid = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db->query('INSERT INTO reading_list_items (list_id, story_id, position) VALUES ((SELECT id FROM reading_lists WHERE slug = ?), ?, 1)',
            ['secret-list', $sid]);
        $repo = new \App\Repositories\ListsRepository($this->db);
        $this->assertSame(['comfort-reads'], $repo->publicListSlugsForStory($sid), 'private lists never cache, so never purge');
        $this->assertSame([], $repo->publicListSlugsForStory($sid + 1000000));
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
        // gate before CSRF since kip b29e269: the guest POST 302s to login too,
        // whatever the token, and writes nothing
        $this->assertSame(302, $this->client()->post('/lists/create', ['title' => 'Nope', 'summary' => '', 'is_public' => '1'])->status);
        $this->assertNull($this->db()->one("SELECT * FROM reading_lists WHERE title = 'Nope'"), 'no list written by a guest');
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
