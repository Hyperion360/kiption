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

    /** A member who muted an author never sees that author on the home
     *  shelves, as on /browse/recent (qa-full /review, review). Guests, whose
     *  render is the cached one, are unaffected. */
    public function test_member_mutes_hide_the_author_from_home(): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $reader = (int) $db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $db->query("INSERT INTO muted (user_id, author_id) SELECT ?, author_id FROM stories WHERE slug = 'the-rabbit-hole'", [$reader]);
        $guest = $this->appOver($db)->handle(new Request('GET', '/', [], [], []))->body;
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $guest);
        $member = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $member);
    }

    /** Context from history (the Magic Ink batch): a returning reader's
     *  home leads with their Continue card - the seeded resume clamp lands
     *  on chapter II at 50 percent - while a guest's render (the cached
     *  bytes) carries no card at all. A reader with no history sees none. */
    public function test_member_home_leads_with_their_continue_card(): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $reader = (int) $db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $storyId = (int) $db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $db->query('INSERT INTO reading_history (user_id, story_id, last_position, marked_at, updated_at) VALUES (?, ?, 2, NULL, ?)',
            [$reader, $storyId, '2026-10-21T10:00:00Z']);
        $guest = $this->appOver($db)->handle(new Request('GET', '/', [], [], []))->body;
        $this->assertStringNotContainsString('id="home-continue"', $guest, 'guests get no Continue card');
        $member = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringContainsString('id="home-continue"', $member);
        $this->assertStringContainsString('href="/story/read/the-rabbit-hole/2"', $member, 'the resume clamp lands on chapter II');
        $this->assertStringContainsString('Chapter II · 50%', $member, 'percent from the validated word counts');
        $noHistory = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, 1))->body;
        $this->assertStringNotContainsString('id="home-continue"', $noHistory, 'a reader with no history sees no card');
    }

    /** The Continue card's negative branches (the qa-full /review pins): a
     *  resume position below every validated chapter clamps to no card, a
     *  muted author's story is not promoted first, and a history row on an
     *  unvalidated story never surfaces. */
    public function test_continue_card_honors_clamp_mutes_and_gates(): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $reader = (int) $db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $storyId = (int) $db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];

        // clamp: history at position 1 with chapter 1 unvalidated -> resume NULL -> no card
        $db->query('UPDATE chapters SET validated = 0 WHERE story_id = ? AND position = 1', [$storyId]);
        $db->query('INSERT INTO reading_history (user_id, story_id, last_position, updated_at) VALUES (?, ?, 1, ?)',
            [$reader, $storyId, '2026-10-20T10:00:00Z']);
        $clamped = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringNotContainsString('id="home-continue"', $clamped, 'resume NULL clamps to no card');

        // fall-through: an older USABLE history row behind the unusable
        // newest one promotes instead of blanking the card
        $db->query("INSERT INTO users (email, password_hash, penname, profile_slug) VALUES ('third@x.test', 'h', 'thirdauthor', 'thirdauthor')");
        $thirdAuthor = (int) $db->one("SELECT id FROM users WHERE penname = 'thirdauthor'")['id'];
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id, validated, language, is_original) VALUES (?, ?, ?, 1, 1, ?, 1)',
            ['Third Tale', 'third-tale', $thirdAuthor, 'en']);
        $thirdStory = (int) $db->one("SELECT id FROM stories WHERE slug = 'third-tale'")['id'];
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 1, ?, ?, 1, 100)',
            [$thirdStory, 'One', 'Still readable.']);
        $db->query("INSERT INTO reading_history (user_id, story_id, last_position, updated_at) VALUES (?, ?, 1, '2026-10-19T10:00:00Z')",
            [$reader, $thirdStory]);
        $fall = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringContainsString('href="/story/read/third-tale/1"', $fall, 'the next-best usable row promotes');

        // mute: the card obeys the same page rule as the shelves
        $db->query('UPDATE chapters SET validated = 1 WHERE story_id = ?', [$storyId]);
        $db->query('INSERT INTO muted (user_id, author_id) SELECT ?, author_id FROM stories WHERE slug = ?', [$reader, 'the-rabbit-hole']);
        $db->query('INSERT INTO muted (user_id, author_id) SELECT ?, author_id FROM stories WHERE slug = ?', [$reader, 'third-tale']);
        $muted = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringNotContainsString('id="home-continue"', $muted, 'a muted author is not promoted first');

        // gate: history on an unvalidated story never surfaces (the usable
        // older row behind it promotes, per the fall-through above)
        $db->query('DELETE FROM muted WHERE user_id = ?', [$reader]);
        $db->query('UPDATE stories SET validated = 0 WHERE id = ?', [$storyId]);
        $gated = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringNotContainsString('/story/read/the-rabbit-hole', $gated, 'unvalidated stories stay invisible to the card');
        $this->assertStringContainsString('href="/story/read/third-tale/1"', $gated, 'the gated story falls through to the usable row');
    }

    /** The freshness boundary (the qa-full /review pins): a member render
     *  marks updates inside the 48-hour window and never outside it; a
     *  guest render - the cached bytes - never claims fresh at all. */
    public function test_fresh_dot_marks_only_the_48_hour_window_for_members(): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $setAge = fn (string $offset): bool =>
            (bool) $db->query("UPDATE stories SET updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now', ?) WHERE slug = 'the-rabbit-hole'", [$offset]);
        $setAge('-47 hours');
        $member = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, 1))->body;
        $this->assertStringContainsString('fresh-dot', $member, '47 hours is inside the window');
        $guest = $this->appOver($db)->handle(new Request('GET', '/', [], [], []))->body;
        $this->assertStringNotContainsString('fresh-dot', $guest, 'the cached guest bytes never lie fresh');
        $setAge('-49 hours');
        $outside = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, 1))->body;
        $this->assertStringNotContainsString('fresh-dot', $outside, '49 hours is outside the window');
        $setAge('+2 hours');
        $future = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, 1))->body;
        $this->assertStringNotContainsString('fresh-dot', $future, 'a future-dated import never holds the mark open');
    }

    /** Saved-for-later never leads Continue (the 2026-10-27 review pass): a
     *  marked reading_history row is the Account shelf's saved list, so
     *  marking an unread story must not displace the story actually being
     *  read; the newest UNMARKED row promotes instead, and when every row is
     *  marked the card is absent. A restricted story is a different gate: it
     *  members may read, so its history row still leads the card. */
    public function test_marked_rows_never_lead_but_restricted_stories_do(): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $reader = (int) $db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $storyId = (int) $db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $db->query("INSERT INTO users (email, password_hash, penname, profile_slug) VALUES ('second@x.test', 'h', 'secondauthor', 'secondauthor')");
        $secondAuthor = (int) $db->one("SELECT id FROM users WHERE penname = 'secondauthor'")['id'];
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id, validated, language, is_original) VALUES (?, ?, ?, 1, 1, ?, 1)',
            ['Second Tale', 'second-tale', $secondAuthor, 'en']);
        $secondStory = (int) $db->one("SELECT id FROM stories WHERE slug = 'second-tale'")['id'];
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 1, ?, ?, 1, 100)',
            [$secondStory, 'One', 'Saved for later.']);

        // the NEWEST row is marked (saved-for-later); the older real read promotes
        $db->query("INSERT INTO reading_history (user_id, story_id, last_position, marked_at, updated_at) VALUES (?, ?, 2, NULL, '2026-10-20T10:00:00Z')",
            [$reader, $storyId]);
        $db->query("INSERT INTO reading_history (user_id, story_id, last_position, marked_at, updated_at) VALUES (?, ?, 1, '2026-10-22T10:00:00Z', '2026-10-22T10:00:00Z')",
            [$reader, $secondStory]);
        $promoted = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringContainsString('href="/story/read/the-rabbit-hole/2"', $promoted, 'the marked newest row does not displace the real read');
        $this->assertStringNotContainsString('/story/read/second-tale', $promoted, 'the saved story stays on its shelf');

        // every row marked: the card is absent entirely
        $db->query('UPDATE reading_history SET marked_at = ? WHERE user_id = ?', ['2026-10-23T10:00:00Z', $reader]);
        $shelved = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringNotContainsString('id="home-continue"', $shelved, 'an all-saved shelf shows no Continue card');

        // restricted is a different gate: a member may read it, so it leads
        $db->query('UPDATE reading_history SET marked_at = NULL WHERE user_id = ? AND story_id = ?', [$reader, $storyId]);
        $db->query('UPDATE stories SET is_restricted = 1 WHERE id = ?', [$storyId]);
        $restricted = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringContainsString('href="/story/read/the-rabbit-hole/2"', $restricted, 'a restricted story a member is reading still leads the card');
    }

    /** The fold's admin arm (the adversarial pass caught the under-bind):
     *  with the mute flag OFF the unmuted member shape binds the continue
     *  arm AND the admin arm, and an admin member's home render must carry
     *  the operator block. pdo_sqlite swallows a short bind list as a NULL
     *  match, so only an admin-with-flag-off render can catch it. */
    public function test_an_admin_members_home_carries_the_operator_block(): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $db->query("UPDATE users SET role = 'admin' WHERE penname = 'Demo Author'");
        $admin = (int) $db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $db->query("INSERT OR REPLACE INTO feature_flags (key, enabled) VALUES ('mute', 0)");
        \App\Features::init($db, []);
        try {
            $body = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $admin))->body;
            $this->assertStringContainsString('href="/queue"', $body, 'the operator block rides the folded admin arm');
        } finally {
            \App\Features::reset();
        }
    }

    /** A real read supersedes the save-for-later mark (the Red Team pass):
     *  every read-side filter excludes marked rows, so a story marked
     *  before its first read, then actually read, must clear the mark and
     *  lead Continue again instead of stranding on the saved shelf. */
    public function test_reading_a_marked_story_returns_it_to_continue(): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $reader = (int) $db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $storyId = (int) $db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $db->query('INSERT INTO reading_history (user_id, story_id, last_position, marked_at) VALUES (?, ?, 1, ?)',
            [$reader, $storyId, '2026-10-20T10:00:00Z']);
        (new \App\Repositories\EngagementRepository($db))->recordProgress($reader, $storyId, 2);
        $row = $db->one('SELECT marked_at, last_position FROM reading_history WHERE user_id = ? AND story_id = ?', [$reader, $storyId]);
        $this->assertNull($row['marked_at'], 'the read supersedes the mark');
        $this->assertSame(2, (int) $row['last_position'], 'the furthest read keeps its MAX semantics');
        $body = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, $reader))->body;
        $this->assertStringContainsString('href="/story/read/the-rabbit-hole/2"', $body, 'the read story leads Continue again');
    }

    /** A session shaped like a real login: the id AND the password epoch a
     *  login stamps (App\Viewer::id treats an epoch-less session as revoked,
     *  which is the point of the check, so the fixture carries one). */
    private function memberSession(Database $db, int $userId): Session
    {
        $store = [];
        $session = new Session($store);
        $session->set('user_id', $userId);
        $hash = (string) $db->one('SELECT password_hash FROM users WHERE id = ?', [$userId])['password_hash'];
        $session->set('pwd_epoch', substr($hash, 0, \Kip\Auth::EPOCH_LEN));
        return $session;
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
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $res = $this->appOver($db)->handle(new Request('GET', '/', [], [], ['kip_session' => 'x']), $this->memberSession($db, 1));
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
