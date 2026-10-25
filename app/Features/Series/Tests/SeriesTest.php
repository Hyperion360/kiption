<?php // app/Features/Series/Tests/SeriesTest.php
namespace App\Features\Series\Tests;
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
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function newApp(string $uploadsDir = ''): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-series-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $uploadsDir !== '' ? $uploadsDir : sys_get_temp_dir() . '/kiption-series-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            // a throwaway static cache: purges never touch the real public/cache
            'static_cache' => ['enabled' => true, 'dir' => $this->cacheDir()],
        ]);
    }

    private function cacheDir(): string
    {
        return sys_get_temp_dir() . '/kiption-series-cache-' . md5($this->path);
    }

    /** Creating a series purges the cached /series index in the CONFIGURED
     *  cache directory (KIP_STATIC_CACHE_DIR), not a hardcoded public/cache. */
    public function test_create_purges_the_configured_series_index(): void
    {
        $cache = new \App\StaticCache\Cache($this->cacheDir());
        $req = new \Kip\Http\Request('GET', '/series', [], [], []);
        $cache->maybeStore($req, new \Kip\Http\Response('stale index', 200));
        $this->assertNotNull($cache->serve($req));
        $this->client($this->authorId())->postWithToken('/series/create', ['title' => 'Fresh Works', 'summary' => '', 'membership' => 'open']);
        $this->assertNull($cache->serve($req), 'the configured index file is gone');
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
        $this->assertDoesNotMatchRegularExpression('/\dT\d{2}:\d{2}/', $res->body); // dates render date-only, the listing convention
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

    /** A session revoked by a password change filters the series page as a
     *  guest (the Viewer::id doctrine, f7ca7c3's /browse/recent rider): the
     *  raw session id would keep member-tier visibility after revocation. */
    public function test_revoked_session_sees_the_guest_series_list(): void
    {
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $member = $this->memberId();
        $c = $this->client($member);
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole"', $c->get('/series/view/down-the-rabbit-hole')->body);
        $this->db->query("UPDATE users SET password_hash = 'rotated' WHERE id = $member");
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $c->get('/series/view/down-the-rabbit-hole')->body,
            'the revoked session filters the series as a guest (the series slug itself is a different string)');
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

    public function test_create_edit_flow(): void
    {
        $owner = $this->client($this->authorId());
        $res = $owner->postWithToken('/series/create',
            ['title' => 'Night Works', 'summary' => 'Later things.', 'membership' => 'moderated']);
        $this->assertSame(302, $res->status, $res->body);
        $body = $owner->get('/series/view/night-works')->body;
        $this->assertStringContainsString('Night Works', $body);
        $this->assertStringContainsString('moderated', $body); // shown as pending-submission hint to owner
        $res = $owner->postWithToken('/series/update/night-works',
            ['title' => 'Night Works II', 'summary' => 'Later things.', 'membership' => 'closed']);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('Night Works II', $owner->get('/series/view/night-works')->body);
    }

    public function test_create_validation(): void
    {
        $owner = $this->client($this->authorId());
        $this->assertSame(422, $owner->postWithToken('/series/create', ['title' => '', 'summary' => '', 'membership' => 'open'])->status);
        $this->assertSame(422, $owner->postWithToken('/series/create', ['title' => 'X' , 'summary' => '', 'membership' => 'sneaky'])->status);
    }

    public function test_edit_gate_is_owner_or_admin(): void
    {
        $member = $this->client($this->memberId());
        $this->assertSame(404, $member->get('/series/edit/down-the-rabbit-hole')->status);
    }

    public function test_account_lists_your_series_with_pending_counts(): void
    {
        $body = $this->client($this->authorId())->get('/account')->body;
        $this->assertStringContainsString('Your series', $body);
        $this->assertStringContainsString('down-the-rabbit-hole', $body);
    }

    public function test_account_series_counts_exclude_soft_deleted_stories(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/series/create', ['title' => 'Counted', 'summary' => '', 'membership' => 'closed']);
        $owner->postWithToken('/series/add/counted', ['story_slug' => 'after-hours']);
        $this->db->query("UPDATE stories SET deleted_at = ? WHERE slug = 'after-hours'", [date('c')]);
        $body = $owner->get('/account')->body;
        // the series page hides the deleted item; the account counts must agree
        // (membership 'closed' distinguishes this row from the seeded open series)
        $this->assertStringContainsString('0 works · closed', $body);
        $this->assertStringNotContainsString('(1 works, closed)', $body);
    }

    public function test_series_forms_require_auth(): void
    {
        $this->assertSame(302, $this->client()->get('/series/new')->status); // auth redirect
        // gate before CSRF since kip b29e269: the guest POST 302s to login too,
        // whatever the token, and writes nothing
        $this->assertSame(302, $this->client()->post('/series/create', ['title' => 'Nope', 'summary' => '', 'membership' => 'open'])->status);
        $this->assertNull($this->db->one("SELECT * FROM series WHERE title = 'Nope'"), 'no series written by a guest');
    }

    public function test_item_actions_confirm_move_remove_with_gates(): void
    {
        // fixture: a second full member authoring a validated story
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('ow@e.test', ?, 'otherwriter', ?, ?, 'otherwriter')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $otherId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO stories (title, slug, author_id, rating_id, validated) VALUES (?, ?, ?, (SELECT id FROM ratings LIMIT 1), 1)', ['Other Tale', 'other-tale', $otherId]);

        $owner = $this->client($this->authorId());
        $owner->postWithToken('/series/create', ['title' => 'Actions', 'summary' => '', 'membership' => 'moderated']);
        $this->client($otherId)->postWithToken('/series/add/actions', ['story_slug' => 'other-tale']); // pending
        $itemId = (int) $this->db->one(
            "SELECT si.id FROM series_items si JOIN series ser ON ser.id = si.series_id JOIN stories s ON s.id = si.story_id
             WHERE ser.slug = 'actions' AND s.slug = 'other-tale'")['id'];

        // non-owner, non-admin, non-author: every item action 404s
        $member = $this->client($this->memberId());
        $this->assertSame(404, $member->postWithToken("/series/confirm/actions/{$itemId}", [])->status);
        $this->assertSame(404, $member->postWithToken("/series/move/actions/{$itemId}/up", [])->status);
        $this->assertSame(404, $member->postWithToken('/series/remove/actions/other-tale', [])->status);

        // owner confirms: guest sees the item, the submitter is notified
        $this->assertSame(302, $owner->postWithToken("/series/confirm/actions/{$itemId}", [])->status);
        $this->assertStringContainsString('other-tale', $this->client()->get('/series/view/actions')->body);
        $this->assertStringContainsString('was added to a series', $this->client($otherId)->get('/notifications')->body);

        // owner adds a second item and swaps it upward
        $owner->postWithToken('/series/add/actions', ['story_slug' => 'after-hours']);
        $secondId = (int) $this->db->one(
            "SELECT si.id FROM series_items si JOIN series ser ON ser.id = si.series_id JOIN stories s ON s.id = si.story_id
             WHERE ser.slug = 'actions' AND s.slug = 'after-hours'")['id'];
        $this->assertSame(302, $owner->postWithToken("/series/move/actions/{$secondId}/up", [])->status);
        $positions = $this->db->all(
            "SELECT s.slug, si.position FROM series_items si JOIN series ser ON ser.id = si.series_id JOIN stories s ON s.id = si.story_id
             WHERE ser.slug = 'actions' ORDER BY si.position");
        $this->assertSame('after-hours', $positions[0]['slug']); // swapped above other-tale

        // junk direction coerces (the browse page-param philosophy), unknown item 404s
        $this->assertSame(302, $owner->postWithToken("/series/move/actions/{$secondId}/sideways", [])->status);
        $this->assertSame(404, $owner->postWithToken('/series/move/actions/999999/up', [])->status);

        // owner removes the second item
        $this->assertSame(302, $owner->postWithToken('/series/remove/actions/after-hours')->status);
        $this->assertStringNotContainsString('after-hours', $owner->get('/series/view/actions')->body);
    }

    /** The 046 shape: one nullable column, up then down, on the real ledger
     *  path (every setUp already migrated it; this pins reversibility). */
    public function test_046_adds_and_drops_series_cover_path(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $cols = fn (): array => array_column($db->all("PRAGMA table_info(series)"), 'name');
        $this->assertContains('cover_path', $cols(), 'setUp migrated 046');
        (require dirname(__DIR__) . '/migrations/046_series_cover.php')->down($db);
        $this->assertNotContains('cover_path', $cols());
        (require dirname(__DIR__) . '/migrations/046_series_cover.php')->up($db);
        $this->assertContains('cover_path', $cols());
    }

    /** down() drops a column, which SQLite only parses from 3.35: the guard
     *  must fail with the actionable instruction on older hosts instead of a
     *  raw parse error mid-batch (the 010/045 rollback shape). */
    public function test_046_down_carries_the_sqlite_335_guard(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/migrations/046_series_cover.php');
        $down = substr($src, (int) strpos($src, 'function down'));
        $this->assertStringContainsString('sqlite_version', $down);
        $this->assertStringContainsString('3.35', $down);
    }

    public function test_series_view_renders_uploaded_cover(): void
    {
        (new Database('sqlite:' . $this->path))->query("UPDATE series SET cover_path = '/uploads/series-cover.png' WHERE slug = 'down-the-rabbit-hole'");
        $body = $this->app->handle(new \Kip\Http\Request('GET', '/series/view/down-the-rabbit-hole', [], [], []))->body;
        $this->assertStringContainsString('<img class="cover" src="/uploads/series-cover.png" alt="">', $body);
    }

    public function test_owner_uploads_series_cover_stranger_404s_junk_422s(): void
    {
        // client() rebuilds the App per call (discarding any container swap),
        // so this test builds ONE app with the copy-mover Storage seam and
        // drives every role through TestClients on that same instance
        // (outside-voice finding 1). The dir is unique per run and the test
        // cleans it up itself (finding 5): tearDown only removes DB files.
        $dir = sys_get_temp_dir() . '/kiption-series-cover-' . uniqid('', true);
        @mkdir($dir);
        $this->app = $this->newApp($dir);
        $this->app->container->instance(\Kip\Storage::class,
            new \Kip\Storage($dir, 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn (string $t, string $d): bool => copy($t, $d)));
        $owner = (new TestClient($this->app))->actingAs($this->authorId());
        $stranger = (new TestClient($this->app))->actingAs(
            (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id']);
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'kiption-cover-');
        file_put_contents($tmp, $png);
        $post = ['name' => 'c.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)];

        // a stranger draws the honest 404 (forEdit, the ownership-unobservable rule)
        $this->assertSame(404, $stranger->postWithFile('/series/cover/down-the-rabbit-hole', [], 'cover', $post)->status);

        // junk extension draws the Storage 422 before any write
        $this->assertSame(422, $owner->postWithFile('/series/cover/down-the-rabbit-hole', [], 'cover',
            ['name' => 'c.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)])->status);

        // the owner's good upload lands and redirects to the edit form
        $res = $owner->postWithFile('/series/cover/down-the-rabbit-hole', [], 'cover', $post);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT cover_path FROM series WHERE slug = 'down-the-rabbit-hole'");
        $this->assertMatchesRegularExpression('#^/uploads/[0-9a-f]{16}\.png$#', (string) $row['cover_path']);
        $this->assertFileExists($dir . '/' . basename((string) $row['cover_path']));

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    /** The library guard: an in-use series cover refuses deletion; clearing
     *  the reference releases it. Uploads through the real /images/upload so
     *  the file carries a genuine Storage hex name; the SAME dir backs the
     *  config uploads.dir so /images/delete's unlink path finds the file
     *  (ImagesController::uploadsDir reads config, finding 1's second half). */
    public function test_series_cover_reference_refuses_library_delete(): void
    {
        $dir = sys_get_temp_dir() . '/kiption-series-lib-' . uniqid('', true);
        @mkdir($dir);
        $this->app = $this->newApp($dir);
        $this->app->container->instance(\Kip\Storage::class,
            new \Kip\Storage($dir, 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn (string $t, string $d): bool => copy($t, $d)));
        // the seeder ships no admin (ImagesTest ships its own fixture too)
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('seradmin@e.test', ?, 'seradmin', 'admin', 1, ?, ?, 'seradmin')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $adminId = (int) $this->db->one("SELECT id FROM users WHERE role = 'admin'")['id'];
        $admin = (new TestClient($this->app))->actingAs($adminId);
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'kiption-lib-');
        file_put_contents($tmp, $png);
        $this->assertSame(302, $admin->postWithFile('/images/upload', [], 'file',
            ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)])->status);
        $files = glob($dir . '/*') ?: [];
        $this->assertCount(1, $files, 'the upload landed in the library dir');
        $name = basename($files[0]);

        $this->db->query("INSERT INTO series (title, slug, owner_id, cover_path) VALUES ('S', 's', 1, ?)", ['/uploads/' . $name]);
        $this->assertSame(409, $admin->postWithToken('/images/delete', ['name' => $name])->status,
            'a series-referenced file refuses deletion');
        $this->db->query("UPDATE series SET cover_path = NULL WHERE slug = 's'");
        $this->assertSame(302, $admin->postWithToken('/images/delete', ['name' => $name])->status);

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    /** The bare "Upload cover" submit (no file chosen) draws the 422 before
     *  any Storage call, and the admin leg of the owner-or-admin gate lands:
     *  both negative/positive arms of the action's first two returns. */
    public function test_series_cover_without_a_file_422s_and_admin_uploads(): void
    {
        $dir = sys_get_temp_dir() . '/kiption-series-cover-' . uniqid('', true);
        @mkdir($dir);
        $this->app = $this->newApp($dir);
        $this->app->container->instance(\Kip\Storage::class,
            new \Kip\Storage($dir, 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn (string $t, string $d): bool => copy($t, $d)));
        $owner = (new TestClient($this->app))->actingAs($this->authorId());

        // no file field: the null branch, no write
        $this->assertSame(422, $owner->postWithToken('/series/cover/down-the-rabbit-hole', [])->status);
        $this->assertNull($this->db->one("SELECT cover_path FROM series WHERE slug = 'down-the-rabbit-hole' AND cover_path IS NOT NULL"));

        // the admin leg of forEdit's may_edit (the ImagesTest fixture idiom)
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('seradmin3@e.test', ?, 'seradmin3', 'admin', 1, ?, ?, 'seradmin3')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $adminId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'seradmin3'")['id'];
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'kiption-cover-');
        file_put_contents($tmp, $png);
        $res = (new TestClient($this->app))->actingAs($adminId)->postWithFile('/series/cover/down-the-rabbit-hole', [], 'cover',
            ['name' => 'c.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertMatchesRegularExpression('#^/uploads/[0-9a-f]{16}\.png$#',
            (string) $this->db->one("SELECT cover_path FROM series WHERE slug = 'down-the-rabbit-hole'")['cover_path']);

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    /** The Edit link follows the upload gate: owner or admin, and nobody
     *  else (an admin previously had upload rights with no UI path). */
    public function test_edit_link_shows_for_owner_or_admin_not_members(): void
    {
        $owner = $this->client($this->authorId())->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertStringContainsString('href="/series/edit/down-the-rabbit-hole"', $owner);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('seradmin4@e.test', ?, 'seradmin4', 'admin', 1, ?, ?, 'seradmin4')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $adminId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'seradmin4'")['id'];
        $admin = $this->client($adminId)->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertStringContainsString('href="/series/edit/down-the-rabbit-hole"', $admin, 'admins reach the edit form (and the cover upload)');
        $betaId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $member = $this->client($betaId)->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertStringNotContainsString('href="/series/edit/down-the-rabbit-hole"', $member);
    }
}
