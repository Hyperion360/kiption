<?php // tests/ChallengesTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Task 2: the challenge CRUD surface with prompt management (ListsTest
 * idiom, the ListsRepository method-for-method mirror). Task 3 appends the
 * public page, membership, and notification tests here.
 *
 * Features::init/reset discipline: the class inits in setUp and resets in
 * tearDown so no later suite inherits a memo pointing at this unlinking
 * temp DB. */
final class ChallengesTest extends TestCase
{
    private string $path = '';
    private string $cacheDir = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-chal-') . '.sqlite';
        $this->cacheDir = sys_get_temp_dir() . '/kiption-chal-cache-' . uniqid('', true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
        \App\Features::init($this->db, []);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
    }

    private function newApp(): App
    {
        return new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-chal-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-chal-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            // config-injected cache dir (the ListsController idiom): the
            // controller's purge wiring writes here, never the real public/cache
            'static_cache' => ['dir' => $this->cacheDir],
        ]);
    }

    /** The factory keeps the App instance as $this->app: TestClient drives
     *  App::handle directly, which bypasses the static cache entirely (the
     *  SeriesTest finding); drive $this->app->handle(new Request(...)) when a
     *  request needs explicit cookies or a cache roundtrip. */
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

    public function test_crud_prompts_and_gates(): void
    {
        $me = $this->client($this->memberId());
        $this->assertSame(302, $me->postWithToken('/challenges/create',
            ['title' => 'First Line Challenge', 'summary' => 'Open a story with this line.', 'membership' => 'open'])->status);
        $db = $this->db();
        $this->assertNotNull($db->one("SELECT * FROM challenges WHERE slug = 'first-line-challenge' AND membership = 'open'"));
        // junk membership 422; title required; hidden/guest gates
        $this->assertSame(422, $me->postWithToken('/challenges/create', ['title' => 'X', 'summary' => '', 'membership' => 'sneaky'])->status);
        $this->assertSame(422, $me->postWithToken('/challenges/create', ['title' => '', 'summary' => '', 'membership' => 'open'])->status);
        $this->assertSame(302, $this->client()->get('/challenges/new')->status, 'auth redirect');
        $this->assertSame(403, $this->client($this->authorId())->post('/challenges/create', ['title' => 'X', 'summary' => '', 'membership' => 'open'])->status, 'CSRF-first');
        $this->assertSame(404, $this->client($this->authorId())->postWithToken('/challenges/update/first-line-challenge', ['title' => 'X', 'summary' => '', 'membership' => 'open'])->status, 'ownership with a valid token');
        // prompts: add, list on the edit form, remove
        $this->assertSame(302, $me->postWithToken('/challenges/prompt/first-line-challenge', ['prompt_text' => 'It was the best of times, approximately.'])->status);
        $this->assertSame(302, $me->postWithToken('/challenges/prompt/first-line-challenge', ['prompt_text' => 'Call me whenever.'])->status);
        // scoped to this challenge: the seeder's community-challenge prompt shares the table
        $this->assertSame(2, (int) $db->one("SELECT COUNT(*) c FROM challenge_prompts cp JOIN challenges ch ON ch.id = cp.challenge_id WHERE ch.slug = 'first-line-challenge'")['c']);
        $this->assertSame(422, $me->postWithToken('/challenges/prompt/first-line-challenge', ['prompt_text' => ''])->status);
    }

    public function test_edit_form_lists_prompts_with_move_and_remove(): void
    {
        $me = $this->client($this->memberId());
        $me->postWithToken('/challenges/create', ['title' => 'Prompt Bowl', 'summary' => '', 'membership' => 'moderated']);
        $me->postWithToken('/challenges/prompt/prompt-bowl', ['prompt_text' => 'First.']);
        $me->postWithToken('/challenges/prompt/prompt-bowl', ['prompt_text' => 'Second.']);
        $me->postWithToken('/challenges/prompt/prompt-bowl', ['prompt_text' => 'Third.']);
        $db = $this->db();

        // the owner's edit form lists every prompt with its management forms
        $body = $me->get('/challenges/edit/prompt-bowl')->body;
        $this->assertStringContainsString('First.', $body);
        $this->assertStringContainsString('Third.', $body);
        $this->assertStringContainsString('/challenges/promptremove/prompt-bowl/', $body);
        $this->assertStringContainsString('/challenges/promptmove/prompt-bowl/', $body);
        // a stranger never sees the form (own()'s 404, not 403)
        $this->assertSame(404, $this->client($this->authorId())->get('/challenges/edit/prompt-bowl')->status);

        // the swap: moving the third prompt up trades positions with the second
        // (queries scoped to prompt-bowl: the seeder's fixture prompt shares the table)
        $ids = array_map('intval', array_column($db->all("SELECT cp.id FROM challenge_prompts cp JOIN challenges ch ON ch.id = cp.challenge_id WHERE ch.slug = 'prompt-bowl' ORDER BY cp.position"), 'id'));
        $this->assertSame(302, $me->postWithToken("/challenges/promptmove/prompt-bowl/{$ids[2]}/up", [])->status);
        $texts = array_column($db->all("SELECT cp.prompt_text FROM challenge_prompts cp JOIN challenges ch ON ch.id = cp.challenge_id WHERE ch.slug = 'prompt-bowl' ORDER BY cp.position"), 'prompt_text');
        $this->assertSame(['First.', 'Third.', 'Second.'], $texts);
        // junk direction coerces, a boundary move is a calm no-op, unknown prompt 404s
        $this->assertSame(302, $me->postWithToken("/challenges/promptmove/prompt-bowl/{$ids[2]}/sideways", [])->status);
        $this->assertSame(302, $me->postWithToken("/challenges/promptmove/prompt-bowl/{$ids[2]}/up", [])->status, 'top-of-list move up is a no-op');
        $this->assertSame(404, $me->postWithToken('/challenges/promptmove/prompt-bowl/999999/up', [])->status);

        // remove drops the row; every prompt op is owner-gated
        $this->assertSame(302, $me->postWithToken("/challenges/promptremove/prompt-bowl/{$ids[1]}", [])->status);
        $this->assertSame(2, (int) $db->one("SELECT COUNT(*) c FROM challenge_prompts cp JOIN challenges ch ON ch.id = cp.challenge_id WHERE ch.slug = 'prompt-bowl'")['c']);
        $this->assertSame(404, $me->postWithToken('/challenges/promptremove/prompt-bowl/999999', [])->status);
        $author = $this->client($this->authorId());
        $this->assertSame(404, $author->postWithToken('/challenges/prompt/prompt-bowl', ['prompt_text' => 'Not mine.'])->status);
        $this->assertSame(404, $author->postWithToken("/challenges/promptremove/prompt-bowl/{$ids[0]}", [])->status);
        $this->assertSame(404, $author->postWithToken("/challenges/promptmove/prompt-bowl/{$ids[0]}/down", [])->status);
        $this->assertSame(404, $author->postWithToken('/challenges/prompt/ghost-challenge', ['prompt_text' => 'X.'])->status, 'unknown slug');
    }

    public function test_update_delete_and_clamps(): void
    {
        $me = $this->client($this->memberId());
        $me->postWithToken('/challenges/create', ['title' => 'Rewrite Rondeau', 'summary' => str_repeat('s', 2500), 'membership' => 'closed']);
        $db = $this->db();
        // summary clamped to 2000 at rest
        $this->assertSame(2000, strlen((string) $db->one("SELECT summary FROM challenges WHERE slug = 'rewrite-rondeau'")['summary']));

        // update round-trips title and membership
        $this->assertSame(302, $me->postWithToken('/challenges/update/rewrite-rondeau',
            ['title' => 'Rondeau Redux', 'summary' => 'Again.', 'membership' => 'moderated'])->status);
        $row = $db->one("SELECT title, membership FROM challenges WHERE slug = 'rewrite-rondeau'");
        $this->assertSame('Rondeau Redux', $row['title']);
        $this->assertSame('moderated', $row['membership']);
        $this->assertStringContainsString('Rondeau Redux', $me->get('/challenges/edit/rewrite-rondeau')->body);
        // an over-long title 422s like the series form
        $this->assertSame(422, $me->postWithToken('/challenges/update/rewrite-rondeau',
            ['title' => str_repeat('T', 121), 'summary' => '', 'membership' => 'open'])->status);
        $this->assertSame('Rondeau Redux', $db->one("SELECT title FROM challenges WHERE slug = 'rewrite-rondeau'")['title']);

        // delete: prompts ride the FK cascade; strangers 404 first
        $me->postWithToken('/challenges/prompt/rewrite-rondeau', ['prompt_text' => 'Seventeen syllables exactly.']);
        $this->assertSame(404, $this->client($this->authorId())->postWithToken('/challenges/delete/rewrite-rondeau', [])->status);
        $this->assertSame(302, $me->postWithToken('/challenges/delete/rewrite-rondeau', [])->status);
        $this->assertNull($db->one("SELECT * FROM challenges WHERE slug = 'rewrite-rondeau'"));
        $this->assertSame(0, (int) $db->one("SELECT COUNT(*) c FROM challenge_prompts cp JOIN challenges ch ON ch.id = cp.challenge_id WHERE ch.slug = 'rewrite-rondeau'")['c']);
        $this->assertSame(404, $me->postWithToken('/challenges/delete/ghost-challenge', [])->status);
    }

    public function test_flag_off_404s_the_module(): void
    {
        $me = $this->client($this->memberId());
        $this->assertSame(302, $me->postWithToken('/challenges/create', ['title' => 'Before Off', 'summary' => '', 'membership' => 'open'])->status);
        \App\Features::toggle('challenges', false);
        $this->assertSame(404, $me->get('/challenges/new')->status);
        $this->assertSame(404, $me->get('/challenges/edit/before-off')->status);
        $this->assertSame(404, $me->postWithToken('/challenges/create', ['title' => 'X', 'summary' => '', 'membership' => 'open'])->status);
        $this->assertSame(404, $me->postWithToken('/challenges/prompt/before-off', ['prompt_text' => 'X.'])->status);
        // Task 3's public surfaces guard the same way: view, index, join,
        // confirm, remove all draw the byte-identical 404
        $this->assertSame(404, $me->get('/challenges')->status);
        $this->assertSame(404, $this->client()->get('/challenges/view/before-off')->status);
        $this->assertSame(404, $me->get('/challenges/view/before-off')->status);
        $this->assertSame(404, $me->postWithToken('/challenges/join/before-off', ['story_slug' => 'the-rabbit-hole'])->status);
        $this->assertSame(404, $me->postWithToken('/challenges/confirm/before-off/1', [])->status);
        $this->assertSame(404, $me->postWithToken('/challenges/remove/before-off/the-rabbit-hole', [])->status);
    }

    public function test_public_page_membership_and_notifications(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership) VALUES ('Mod Challenge', 'mod-challenge', 'Write it.', ?, 'moderated')", [$this->memberId()]);
        $db->query("INSERT INTO challenge_prompts (challenge_id, position, prompt_text) VALUES ((SELECT id FROM challenges WHERE slug = 'mod-challenge'), 1, 'A prompt.')");
        // owner join: immediate; author join: pending + owner notified
        $author = $this->client($this->authorId());
        $this->assertSame(302, $author->postWithToken('/challenges/join/mod-challenge', ['story_slug' => 'the-rabbit-hole'])->status);
        $row = $db->one("SELECT ci.id, ci.confirmed FROM challenge_items ci JOIN challenges ch ON ch.id = ci.challenge_id
                         WHERE ch.slug = 'mod-challenge' AND ci.story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole')");
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['confirmed'], 'moderated join pends');
        $inbox = $this->client($this->memberId())->get('/notifications')->body;
        $this->assertStringContainsString('challenge', strtolower($inbox));
        $this->assertStringContainsString('to your challenge', $inbox, 'the challenge_submit kind renders');
        // the public page: guest sees the challenge + prompt, NOT the pending story
        $page = $this->client()->get('/challenges/view/mod-challenge');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Mod Challenge', $page->body);
        $this->assertStringContainsString('A prompt.', $page->body);
        $this->assertStringContainsString('Write it.', $page->body);
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $page->body);
        // own-pending visibility: the story's author and the challenge owner see it
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $author->get('/challenges/view/mod-challenge')->body);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client($this->memberId())->get('/challenges/view/mod-challenge')->body);
        // duplicate join, unknown slug 404
        $this->assertSame(302, $author->postWithToken('/challenges/join/mod-challenge', ['story_slug' => 'the-rabbit-hole'])->status);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM challenge_items')['c'], 'no duplicate row');
        $this->assertSame(404, $author->postWithToken('/challenges/join/ghost', ['story_slug' => 'the-rabbit-hole'])->status);
    }

    public function test_membership_outcomes_and_join_gates(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership) VALUES ('Open Bowl', 'open-bowl', '', ?, 'open')", [$this->authorId()]);
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership) VALUES ('Shut', 'shut', '', ?, 'closed')", [$this->authorId()]);
        $author = $this->client($this->authorId());
        $member = $this->client($this->memberId());
        // open + story-side: immediate confirm, zero notifications
        $this->assertSame(302, $author->postWithToken('/challenges/join/open-bowl', ['story_slug' => 'the-rabbit-hole'])->status);
        $this->assertSame(1, (int) $db->one("SELECT ci.confirmed FROM challenge_items ci JOIN challenges ch ON ch.id = ci.challenge_id WHERE ch.slug = 'open-bowl'")['confirmed'], 'open join confirms immediately');
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM notifications')['c'], 'no notification on the immediate path');
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client()->get('/challenges/view/open-bowl')->body);
        // closed: 422 for a non-owner member
        $this->assertSame(422, $member->postWithToken('/challenges/join/shut', ['story_slug' => 'the-rabbit-hole'])->status, 'closed challenge rejects member joins');
        // foreign story: the actor must be story-side (or the challenge owner);
        // after-hours, a story the member never wrote and open-bowl never took
        $this->assertSame(422, $member->postWithToken('/challenges/join/open-bowl', ['story_slug' => 'after-hours'])->status, 'not your story');
        // unknown story slug reads as the not-found 404
        $this->assertSame(404, $author->postWithToken('/challenges/join/open-bowl', ['story_slug' => 'ghost-story'])->status);
        // CSRF-first on the write surface
        $this->assertSame(403, $author->post('/challenges/join/open-bowl', ['story_slug' => 'the-rabbit-hole'])->status);
        // the owner joining a member's story confirms immediately and notifies the author
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership) VALUES ('Host Picks', 'host-picks', '', ?, 'moderated')", [$this->memberId()]);
        $this->assertSame(302, $member->postWithToken('/challenges/join/host-picks', ['story_slug' => 'the-rabbit-hole'])->status, 'the owner bypass confirms a foreign story');
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) c FROM notifications WHERE kind = 'challenge_confirm'")['c'], 'the author learns immediately');
        $this->assertSame(1, (int) $db->one("SELECT ci.confirmed FROM challenge_items ci JOIN challenges ch ON ch.id = ci.challenge_id WHERE ch.slug = 'host-picks'")['confirmed']);
        // the join form renders for logged-in members on open challenges
        $this->assertStringContainsString('/challenges/join/open-bowl', $author->get('/challenges/view/open-bowl')->body);
        $this->assertStringNotContainsString('/challenges/join/shut', $this->client($this->authorId())->get('/challenges/view/shut')->body, 'closed challenges carry no join form');
    }

    public function test_guest_gates_hide_unvalidated_and_restricted_items(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership) VALUES ('Gated', 'gated', '', ?, 'open')", [$this->memberId()]);
        $author = $this->client($this->authorId());
        $author->postWithToken('/challenges/join/gated', ['story_slug' => 'the-rabbit-hole']);
        $author->postWithToken('/challenges/join/gated', ['story_slug' => 'after-hours']);
        $db->query("UPDATE stories SET validated = 0 WHERE slug = 'the-rabbit-hole'");
        $db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'after-hours'", []); // the restricted flag, the SeriesTest idiom
        $guest = $this->client()->get('/challenges/view/gated');
        $this->assertSame(200, $guest->status);
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $guest->body, 'unvalidated hidden from guests');
        $this->assertStringNotContainsString('/story/view/after-hours', $guest->body, 'restricted hidden from guests');
        // an itemless guest render is the noindex shape (nothing worth caching)
        $this->assertSame('noindex', $guest->headers['X-Robots-Tag'] ?? '', 'empty page carries the header');
        $this->assertStringContainsString('name="robots"', $guest->body, 'and the meta tag');
        // members see the restricted item; the story's author sees the unvalidated one
        $this->assertStringContainsString('/story/view/after-hours', $this->client($this->memberId())->get('/challenges/view/gated')->body);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client($this->authorId())->get('/challenges/view/gated')->body);
    }

    public function test_confirm_and_remove_surfaces(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership) VALUES ('Mod Challenge', 'mod-challenge', 'Write it.', ?, 'moderated')", [$this->memberId()]);
        $author = $this->client($this->authorId());
        $author->postWithToken('/challenges/join/mod-challenge', ['story_slug' => 'the-rabbit-hole']);
        $itemId = (int) $db->one("SELECT ci.id FROM challenge_items ci JOIN challenges ch ON ch.id = ci.challenge_id WHERE ch.slug = 'mod-challenge'")['id'];
        // the story author is not the challenge owner: confirm draws the 404
        $this->assertSame(404, $author->postWithToken("/challenges/confirm/mod-challenge/{$itemId}", [])->status);
        // the owner confirms; the story appears to guests and the author is notified
        $owner = $this->client($this->memberId());
        $this->assertSame(302, $owner->postWithToken("/challenges/confirm/mod-challenge/{$itemId}", [])->status);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client()->get('/challenges/view/mod-challenge')->body);
        $this->assertStringContainsString('was added to a challenge', $author->get('/notifications')->body, 'the challenge_confirm kind renders');
        // a second confirm is a calm redirect (already confirmed, no second row)
        $this->assertSame(302, $owner->postWithToken("/challenges/confirm/mod-challenge/{$itemId}", [])->status);
        // remove: a stranger 404s, the story author may withdraw, the owner may remove
        $this->assertSame(404, $this->client($this->memberId())->postWithToken('/challenges/remove/ghost-challenge/the-rabbit-hole', [])->status);
        $db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('r@example.test', ?, 'Stranger', ?, ?)", [password_hash('x', PASSWORD_DEFAULT), date('c'), date('c')]);
        $strangerId = (int) $db->one("SELECT id FROM users WHERE penname = 'Stranger'")['id'];
        $this->assertSame(404, $this->client($strangerId)->postWithToken('/challenges/remove/mod-challenge/the-rabbit-hole', [])->status);
        $this->assertSame(302, $author->postWithToken('/challenges/remove/mod-challenge/the-rabbit-hole', [])->status, 'the story author withdraws');
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM challenge_items')['c']);
        // rejoin and remove as the owner
        $author->postWithToken('/challenges/join/mod-challenge', ['story_slug' => 'the-rabbit-hole']);
        $this->assertSame(302, $owner->postWithToken('/challenges/remove/mod-challenge/the-rabbit-hole', [])->status, 'the owner removes');
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM challenge_items')['c']);
    }

    public function test_index_lists_challenges_newest_first_with_counts(): void
    {
        $db = $this->db();
        $res = $this->client()->get('/challenges');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Community Challenge', $res->body, 'the seeded fixture lists');
        $this->assertStringContainsString('/challenges/view/community-challenge', $res->body);
        $this->assertStringContainsString('0 works', $res->body, 'the itemless fixture counts zero');
        // newest first
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership, created_at) VALUES ('Older', 'older-ch', '', ?, 'open', '2026-01-01T00:00:00Z')", [$this->memberId()]);
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership, created_at) VALUES ('Newer', 'newer-ch', '', ?, 'open', '2026-06-01T00:00:00Z')", [$this->memberId()]);
        $body = $this->client()->get('/challenges')->body;
        $this->assertLessThan(strpos($body, 'Older'), strpos($body, 'Newer'), 'newest first');
        // the count badge carries the confirmed-visible gates
        $this->client($this->authorId())->postWithToken('/challenges/join/newer-ch', ['story_slug' => 'the-rabbit-hole']);
        $this->assertStringContainsString('1 works', $this->client()->get('/challenges')->body);
    }

    public function test_seeded_challenge_fixture_and_force_reseed(): void
    {
        $db = $this->db();
        $row = $db->one("SELECT membership FROM challenges WHERE slug = 'community-challenge'");
        $this->assertNotNull($row, 'the fixture rides every seed');
        $this->assertSame('open', $row['membership']);
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) c FROM challenge_prompts cp JOIN challenges ch ON ch.id = cp.challenge_id WHERE ch.slug = 'community-challenge'")['c']);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM challenge_items')['c']);
        // force mode names all three tables explicitly (finding 15)
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership) VALUES ('Extra', 'extra-ch', '', ?, 'open')", [$this->authorId()]);
        $db->query("INSERT INTO challenge_prompts (challenge_id, position, prompt_text) VALUES ((SELECT id FROM challenges WHERE slug = 'extra-ch'), 1, 'X.')");
        $db->query("INSERT INTO challenge_items (challenge_id, story_id, position, confirmed) VALUES ((SELECT id FROM challenges WHERE slug = 'extra-ch'), (SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), 1, 1)");
        \App\Seeder::run($db, force: true);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM challenges')['c'], 'reseeds exactly the fixture');
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM challenge_prompts')['c']);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM challenge_items')['c']);
    }

    public function test_challenge_pages_fill_the_static_cache_and_write_paths_purge(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership) VALUES ('Cached', 'cached-ch', '', ?, 'open')", [$this->memberId()]);
        $this->client($this->authorId())->postWithToken('/challenges/join/cached-ch', ['story_slug' => 'the-rabbit-hole']);
        $cache = new \App\StaticCache\Cache($this->cacheDir);
        $req = new \Kip\Http\Request('GET', '/challenges/view/cached-ch', [], [], []);
        $cache->maybeStore($req, $this->app->handle($req));
        $this->assertNotNull($cache->serve($req), 'the whitelist accepted it, the fill worked');
        // the index fills too
        $reqIndex = new \Kip\Http\Request('GET', '/challenges', [], [], []);
        $cache->maybeStore($reqIndex, $this->app->handle($reqIndex));
        $this->assertNotNull($cache->serve($reqIndex));
        // a prompt write purges the page (the public blob changed) and the index
        $this->client($this->memberId())->postWithToken('/challenges/prompt/cached-ch', ['prompt_text' => 'Door.']);
        $this->assertNull($cache->serve($req), 'prompt write purges the challenge page');
        // an item write purges both surfaces (counts changed)
        $cache->maybeStore($req, $this->app->handle($req));
        $cache->maybeStore($reqIndex, $this->app->handle($reqIndex));
        $this->client($this->authorId())->postWithToken('/challenges/join/cached-ch', ['story_slug' => 'after-hours']);
        $this->assertNull($cache->serve($req), 'item write purges the challenge page');
        $this->assertNull($cache->serve($reqIndex), 'item write purges the index');
        // the itemless seeded challenge never fills (the noindex shape)
        $reqSeed = new \Kip\Http\Request('GET', '/challenges/view/community-challenge', [], [], []);
        $cache->maybeStore($reqSeed, $this->app->handle($reqSeed));
        $this->assertNull($cache->serve($reqSeed));
    }
}
