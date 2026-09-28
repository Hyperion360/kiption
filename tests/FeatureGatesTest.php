<?php // tests/FeatureGatesTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * Retrofits batch 1 (Task 3): the news, comments, contact, stats, and lists
 * flags. For each flag, with the DB row off: every gated route 404s, the
 * cross-links vanish, and toggling back on restores the surface (the
 * unedited suites pin the on-paths; these tests toggle off, assert, toggle
 * on, assert). Auth-attributed routes are driven as a logged-in member WITH
 * tokens (finding 8: the kernel's auth/CSRF ordering legitimately precedes
 * the flag 404 - a guest draws the 302, a tokenless POST the 403 - no leak,
 * the kernel order stays). The comments sub-flag case (finding 7's guard
 * order, news on + comments off) is pinned separately.
 *
 * Retrofits batch 2 (Task 4): the search, toplists, exports (whole work +
 * downloads), and feeds flags. Every gated surface here is auth-free, so the
 * off-cases drive as guests. The cross-link homes (finding 9): the home
 * SearchAction JSON-LD ( HomeController::index builds the entry), the layout
 * autodiscovery link, the profile author-feed link, the category feed link
 * (browse/recent's feedHref, gated at BrowseController::category), and the
 * story view's Whole/Download links.
 *
 * Retrofits batch 3 (Task 5): the digest CLI arm, the member directory, and
 * the beacon pin. The digest case drives bin/kip as a real subprocess (the
 * MailUsersTest idiom): the child resolves flags from its own config + this
 * temp DB (finding 2: no ambient static state crosses the process line), the
 * off row is pre-inserted here, and --mail-log points the transport at a
 * throwaway file so the no-mail pin never depends on the repo's app/mail.log.
 * The directory case guards both routes; the grep for cross-links found the
 * only /browse/authors hrefs in the tree are the directory view's own letter
 * nav (never rendered once the routes 404) and the already-gated Builder
 * block, so the controller guard is the whole retrofit. The beacon case pins
 * the recorded data-collection stance: flags gate the VIEW surfaces, never
 * the count.
 */
final class FeatureGatesTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminUserId = 0;
    private int $memberUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-fg1-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-fg1-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db); // plants the Welcome news row (id 1), demo-author, the-rabbit-hole
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('fg1admin@e.test', ?, 'fg1admin', 'admin', 1, ?, ?, 'fg1admin')",
            [$hash, date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('fg1member@e.test', ?, 'fg1member', ?, ?, 'fg1member')",
            [$hash, date('c'), date('c')]);
        $this->memberUserId = (int) $this->db->lastInsertId();
        // The member's public list holding the seeded story (the ListsTest
        // idiom) so /lists/view and every write op have a real target.
        $sid = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db->query('INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, ?, ?, ?, 1)',
            [$this->memberUserId, 'Comfort reads', 'comfort-reads', 'Stories for bad days.']);
        $this->db->query('INSERT INTO reading_list_items (list_id, story_id, position, note) VALUES ((SELECT id FROM reading_lists WHERE slug = ?), ?, 1, ?)',
            ['comfort-reads', $sid, 'Start here.']);
        \App\Features::init($this->db, []);
    }

    protected function tearDown(): void
    {
        // Finding 2: Features state is global; reset() drops the memo and the
        // DB handle so no later suite in this single phpunit process inherits
        // a memo pointing at this unlinking temp DB.
        \App\Features::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    /** The off switch through the runtime surface itself: one DB row plus the
     *  memo invalidation, exactly what the toggle board does. */
    private function flagOff(string $key): void
    {
        \App\Features::toggle($key, false);
    }

    private function flagOn(string $key): void
    {
        \App\Features::toggle($key, true);
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
            'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__) . '/app/Features',
            // purge writes land in the temp root, never the repo's public/cache
            'static_cache' => ['dir' => $this->root . '/static'],
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_news_off_404s_every_news_route_and_hides_the_operator_link(): void
    {
        $this->flagOff('news');
        $this->assertSame(404, $this->client()->get('/news')->status);
        $this->assertSame(404, $this->client()->get('/news/view/1')->status);
        // Finding 8: auth-attributed routes driven logged-in WITH tokens so
        // the flag 404 itself answers, past the kernel's auth/CSRF ordering.
        $admin = $this->client($this->adminUserId);
        $this->assertSame(404, $admin->get('/news/new')->status);
        $this->assertSame(404, $admin->postWithToken('/news/create', ['title' => 'Off', 'body' => 'No.'])->status);
        $this->assertSame(404, $admin->get('/news/edit/1')->status);
        $this->assertSame(404, $admin->postWithToken('/news/update/1', ['title' => 'Off', 'body' => 'No.'])->status);
        // Finding 7: the comment route guards 'news' FIRST - comments is still
        // on here, so a 404 proves the news guard fired before the sub-flag.
        $this->assertTrue(\App\Features::on('comments'), 'comments stays on: this pins the news-first guard order');
        $this->assertSame(404, $this->client($this->memberUserId)->postWithToken('/news/comment/1', ['body' => 'hi'])->status);
        // The layout operator Post-news link vanishes for an admin viewer.
        $home = $this->client($this->adminUserId)->get('/');
        $this->assertSame(200, $home->status);
        $this->assertStringNotContainsString('href="/news/new"', $home->body);
        // Back on: the surfaces and the operator link return.
        $this->flagOn('news');
        $this->assertSame(200, $this->client()->get('/news')->status);
        $this->assertSame(200, $this->client()->get('/news/view/1')->status);
        $this->assertSame(200, $admin->get('/news/new')->status);
        $this->assertStringContainsString('href="/news/new"', $this->client($this->adminUserId)->get('/')->body);
    }

    public function test_contact_off_404s_both_verbs_and_hides_the_profile_link(): void
    {
        $this->flagOff('contact');
        $member = $this->client($this->memberUserId);
        $this->assertSame(404, $member->get('/user/contact/demo-author')->status);
        $this->assertSame(404, $member->postWithToken('/user/contact/demo-author', ['body' => 'hello'])->status);
        // The profile Contact tab vanishes (it renders for logged-in viewers).
        $profile = $member->get('/user/view/demo-author');
        $this->assertSame(200, $profile->status);
        $this->assertStringNotContainsString('href="/user/contact/', $profile->body);
        // Back on: both verbs answer and the tab returns (the POST rides the
        // log mailer, asserting the send path restored, not just the form).
        $this->flagOn('contact');
        $this->assertSame(200, $member->get('/user/contact/demo-author')->status);
        $this->assertStringContainsString('href="/user/contact/', $member->get('/user/view/demo-author')->body);
        $this->assertSame(200, $member->postWithToken('/user/contact/demo-author', ['body' => 'Loved it.'])->status);
    }

    public function test_stats_off_404s_and_hides_the_account_link(): void
    {
        $this->flagOff('stats');
        $member = $this->client($this->memberUserId);
        $this->assertSame(404, $member->get('/stats')->status);
        $account = $member->get('/account');
        $this->assertSame(200, $account->status);
        $this->assertStringNotContainsString('href="/stats"', $account->body, 'the account Stats link hides');
        // Back on: the dashboard and its account link return.
        $this->flagOn('stats');
        $this->assertSame(200, $member->get('/stats')->status);
        $this->assertStringContainsString('href="/stats"', $member->get('/account')->body);
    }

    public function test_lists_off_404s_every_list_route_and_hides_the_story_link(): void
    {
        $this->flagOff('lists');
        $this->assertSame(404, $this->client()->get('/lists/view/comfort-reads')->status, 'the public list view 404s for a guest too');
        $member = $this->client($this->memberUserId);
        $this->assertSame(404, $member->get('/lists')->status);
        $this->assertSame(404, $member->get('/lists/new')->status);
        $this->assertSame(404, $member->get('/lists/edit/comfort-reads')->status);
        $this->assertSame(404, $member->postWithToken('/lists/create', ['title' => 'Sneaky'])->status);
        $this->assertSame(404, $member->postWithToken('/lists/update/comfort-reads', ['title' => 'Sneaky'])->status);
        $this->assertSame(404, $member->postWithToken('/lists/delete/comfort-reads')->status);
        $this->assertSame(404, $member->postWithToken('/lists/item/comfort-reads', ['story_slug' => 'the-rabbit-hole'])->status);
        $this->assertSame(404, $member->postWithToken('/lists/remove/comfort-reads/the-rabbit-hole')->status);
        $this->assertSame(404, $member->postWithToken('/lists/move/comfort-reads/1/up')->status);
        // The story view Reading-lists link vanishes.
        $story = $this->client()->get('/story/view/the-rabbit-hole');
        $this->assertSame(200, $story->status);
        $this->assertStringNotContainsString('href="/lists"', $story->body);
        // Back on: the surfaces return, and no write fired while off (the
        // guard precedes any repository call, so the rows are untouched).
        $this->flagOn('lists');
        $this->assertSame(200, $this->client()->get('/lists/view/comfort-reads')->status);
        $this->assertSame(200, $member->get('/lists')->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM reading_lists')['c'], 'no create or delete fired while off');
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM reading_list_items')['c'], 'no item add or remove fired while off');
        $this->assertStringContainsString('href="/lists"', $this->client()->get('/story/view/the-rabbit-hole')->body);
    }

    public function test_comments_subflag_hides_the_member_form_keeps_comments_and_counts(): void
    {
        // With news on, a member comment lands first so the off case has an
        // existing comment and a nonzero count to keep.
        $member = $this->client($this->memberUserId);
        $this->assertSame(302, $member->postWithToken('/news/comment/1', ['body' => 'First!'])->status);
        $this->flagOff('comments');
        $this->assertTrue(\App\Features::on('news'), 'news stays on: the sub-flag case');
        $item = $member->get('/news/view/1');
        $this->assertSame(200, $item->status, 'the item page renders with news on');
        $this->assertStringNotContainsString('action="/news/comment/1"', $item->body, 'the member form hides');
        $this->assertStringContainsString('First!', $item->body, 'existing comments stay');
        $this->assertStringContainsString('Comments (1)', $item->body, 'the item count stays');
        $this->assertStringContainsString('1 comment', $this->client()->get('/news')->body, 'the index count scalar stays');
        $this->assertSame(404, $member->postWithToken('/news/comment/1', ['body' => 'second'])->status, 'the comment POST 404s');
        // Back on: the member form returns (the POST itself is not retried;
        // the hourly one-comment throttle would 429 a honest second try).
        $this->flagOn('comments');
        $back = $member->get('/news/view/1');
        $this->assertSame(200, $back->status);
        $this->assertStringContainsString('action="/news/comment/1"', $back->body, 'the member form is back');
    }

    public function test_search_off_404s_and_suppresses_the_home_searchaction(): void
    {
        $this->flagOff('search');
        $this->assertSame(404, $this->client()->get('/search')->status, 'the search surface 404s (auth-free, guest-driven)');
        // The home page keeps its WebSite JSON-LD but drops the SearchAction
        // entry (finding 9a: the entry joins only when the flag is on).
        $home = $this->client()->get('/');
        $this->assertSame(200, $home->status);
        $this->assertStringNotContainsString('SearchAction', $home->body, 'the home JSON-LD suppresses SearchAction');
        $this->assertStringContainsString('"@type":"WebSite"', $home->body, 'the WebSite node itself stays');
        // Back on: the surface and the JSON-LD entry return.
        $this->flagOn('search');
        $this->assertSame(200, $this->client()->get('/search')->status);
        $this->assertStringContainsString('SearchAction', $this->client()->get('/')->body);
    }

    public function test_toplists_off_404s_the_hub(): void
    {
        $this->flagOff('toplists');
        $this->assertSame(404, $this->client()->get('/top')->status);
        // Back on: the hub returns.
        $this->flagOn('toplists');
        $this->assertSame(200, $this->client()->get('/top')->status);
    }

    public function test_exports_off_404s_whole_and_downloads_and_hides_the_story_links(): void
    {
        $this->flagOff('exports');
        $this->assertSame(404, $this->client()->get('/story/whole/the-rabbit-hole')->status);
        $this->assertSame(404, $this->client()->get('/story/download/the-rabbit-hole/html')->status);
        $this->assertSame(404, $this->client()->get('/story/download/the-rabbit-hole/epub')->status);
        // The story view drops its Whole and Download (html/epub) links.
        $story = $this->client()->get('/story/view/the-rabbit-hole');
        $this->assertSame(200, $story->status);
        $this->assertStringNotContainsString('href="/story/whole/', $story->body, 'the Whole link hides');
        $this->assertStringNotContainsString('href="/story/download/', $story->body, 'both Download links hide');
        // Back on: the exports and their links return.
        $this->flagOn('exports');
        $this->assertSame(200, $this->client()->get('/story/whole/the-rabbit-hole')->status);
        $this->assertSame(200, $this->client()->get('/story/download/the-rabbit-hole/html')->status);
        $this->assertSame(200, $this->client()->get('/story/download/the-rabbit-hole/epub')->status);
        $back = $this->client()->get('/story/view/the-rabbit-hole');
        $this->assertStringContainsString('href="/story/whole/', $back->body);
        $this->assertStringContainsString('href="/story/download/', $back->body);
    }

    public function test_feeds_off_404s_every_feed_route_and_hides_every_feed_link(): void
    {
        $this->flagOff('feeds');
        $this->assertSame(404, $this->client()->get('/feed')->status);
        $this->assertSame(404, $this->client()->get('/rss')->status);
        $this->assertSame(404, $this->client()->get('/feed/author/demo-author')->status);
        $this->assertSame(404, $this->client()->get('/feed/category/general')->status);
        // The layout autodiscovery link vanishes from every rendered page.
        $home = $this->client()->get('/');
        $this->assertSame(200, $home->status);
        $this->assertStringNotContainsString('href="/feed"', $home->body, 'the layout autodiscovery link hides');
        // The profile author-feed link hides (finding 9b's homes).
        $profile = $this->client()->get('/user/view/demo-author');
        $this->assertSame(200, $profile->status);
        $this->assertStringNotContainsString('href="/feed/author/', $profile->body);
        // The category feed link hides (gated at the controller: no feedHref).
        $category = $this->client()->get('/browse/category/general');
        $this->assertSame(200, $category->status);
        $this->assertStringNotContainsString('href="/feed/category/', $category->body);
        // Back on: every route and link returns.
        $this->flagOn('feeds');
        $this->assertSame(200, $this->client()->get('/feed')->status);
        $this->assertSame(200, $this->client()->get('/rss')->status);
        $this->assertSame(200, $this->client()->get('/feed/author/demo-author')->status);
        $this->assertSame(200, $this->client()->get('/feed/category/general')->status);
        $this->assertStringContainsString('href="/feed"', $this->client()->get('/')->body);
        $this->assertStringContainsString('href="/feed/author/', $this->client()->get('/user/view/demo-author')->body);
        $this->assertStringContainsString('href="/feed/category/', $this->client()->get('/browse/category/general')->body);
    }

    /** @return array{0: int, 1: string} exit code, stdout+stderr (the MailUsersTest idiom) */
    private function kip(string $args): array
    {
        $cmd = sprintf('KIP_DB_DSN=%s %s %s %s 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'),
            $args);
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_digest_off_prints_disabled_and_never_mails(): void
    {
        // A digest-eligible member with a pending notification (the DigestTest
        // plant shapes): a guardless run WOULD mail this member, which is what
        // makes the empty log and the untouched marker meaningful.
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('fgdigest@e.test', ?, 'fgdigestfan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $fan = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO user_prefs (user_id, notify_favorite_digest) VALUES (?, 1)', [$fan]); // Digest INNER JOINs user_prefs; raw INSERTs create none
        $this->db->query("INSERT INTO notifications (user_id, kind, story_title) VALUES (?, 'update', 'The Rabbit Hole')", [$fan]);
        $this->flagOff('digest');
        $log = $this->root . '/digest-mail.log';
        [$code, $out] = $this->kip('digest:send --mail-log=' . escapeshellarg($log));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('digest feature is disabled', $out);
        $this->assertFileDoesNotExist($log, 'no digest mail fired while the flag is off');
        $this->assertNull($this->db->one('SELECT digest_sent_at FROM user_prefs WHERE user_id = ?', [$fan])['digest_sent_at'],
            'no marker advanced: the member query never ran');
    }

    public function test_releases_off_prints_disabled_but_the_schedule_input_still_stores(): void
    {
        // The flag gates the RELEASE ARM, never the authoring input (the README
        // row's recorded semantics): off means no auto-release job, while a
        // scheduled chapter still stores and simply waits.
        $this->flagOff('releases');
        $log = $this->root . '/release-mail.log';
        [$code, $out] = $this->kip('release:due --mail-log=' . escapeshellarg($log));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('releases feature is disabled', $out);
        $this->assertFileDoesNotExist($log, 'no release mail fired while the flag is off');
        $author = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $res = $this->client($author)->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'Held', 'content' => 'Words.', 'publish_at' => '2099-01-01T00:00:00Z']);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT validated, publish_at FROM chapters WHERE title = 'Held'");
        $this->assertSame(0, (int) $row['validated']);
        $this->assertSame('2099-01-01T00:00:00Z', $row['publish_at'], 'the schedule input still stores with the arm off');
    }

    public function test_roundrobin_off_closes_the_member_gate_and_on_restores_it(): void
    {
        // The flag gates the EXPANSION only (the README row's recorded
        // semantics): off restores the exact pre-rr gate, so story-side actors
        // keep every chapter path while the member gate reopens when it flips
        // back on.
        $this->db->query("UPDATE stories SET round_robin = 1 WHERE slug = 'the-rabbit-hole'");
        $member = $this->client($this->memberUserId);
        $this->flagOff('roundrobin');
        $this->assertSame(404, $member->get('/chapter/new/the-rabbit-hole')->status, 'the add-chapter form closes');
        $this->assertSame(404, $member->postWithToken('/chapter/create/the-rabbit-hole', ['title' => 'Nope', 'content' => 'Words.'])->status,
            'the member create closes');
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM chapters WHERE title = 'Nope'")['c'],
            'no write fired while off');
        // Story-side actors ignore the flag entirely (the admin branch is
        // SQL-side and the rr clause is additive).
        $this->assertSame(200, $this->client($this->adminUserId)->get('/chapter/new/the-rabbit-hole')->status,
            'the story-side path stays open with the flag off');
        // Back on: the expansion restores for the same member.
        $this->flagOn('roundrobin');
        $this->assertSame(200, $member->get('/chapter/new/the-rabbit-hole')->status);
        $res = $member->postWithToken('/chapter/create/the-rabbit-hole', ['title' => 'Now', 'content' => 'Words.']);
        $this->assertSame(302, $res->status, $res->body);
    }

    public function test_directory_off_404s_both_author_routes_but_not_profiles(): void
    {
        $this->flagOff('directory');
        $this->assertSame(404, $this->client()->get('/browse/authors')->status, 'the directory index 404s');
        $this->assertSame(404, $this->client()->get('/browse/authors/b')->status, 'the letter variant 404s');
        // Profiles stay reachable: they are core (the never-list), not the
        // directory surface, and the sitemap-authors segment lists profile
        // URLs, so it stays honest too.
        $profile = $this->client()->get('/user/view/demo-author');
        $this->assertSame(200, $profile->status);
        // Back on: both surfaces return.
        $this->flagOn('directory');
        $this->assertSame(200, $this->client()->get('/browse/authors')->status);
        $this->assertSame(200, $this->client()->get('/browse/authors/b')->status);
    }

    public function test_beacon_still_counts_with_stats_and_analytics_off(): void
    {
        // The recorded data-collection stance: flags gate VIEW surfaces, never
        // the beacon itself; page_stats stays aggregate-only and keeps counting.
        $this->flagOff('stats');
        $this->flagOff('analytics');
        $res = $this->client()->get('/beacon/read/1/3'); // story 1, chapter id 3 on the seed
        $this->assertSame(200, $res->status);
        $this->assertSame(1, (int) $this->db->one("SELECT reads FROM page_stats WHERE story_id = 1 AND chapter_id = 3 AND day = strftime('%Y-%m-%d', 'now')")['reads'],
            'the chapter row counted with both view flags off');
        $this->assertSame(1, (int) $this->db->one("SELECT reads FROM page_stats WHERE story_id = 1 AND chapter_id = 0 AND day = strftime('%Y-%m-%d', 'now')")['reads'],
            'the chapter_id=0 story rollup counted too');
    }

    /** Retrofits batch 4 (M4 batch 2, Task 4's close-out cases): pms, mute,
     *  and wrangling. pms: every action 404s and the layout's Messages link
     *  hides. mute: both toggles 404, the buttons and the account block hide,
     *  and a PLANTED mute row stops filtering (finding 9: off disables the
     *  filtering everywhere, not just the buttons). wrangling: all three
     *  actions 404 flag-first, ahead of the SQL admin gate. */

    public function test_pms_off_404s_every_message_route_and_hides_the_layout_link(): void
    {
        $this->flagOff('pms');
        $member = $this->client($this->memberUserId);
        $this->assertSame(404, $member->get('/messages')->status, 'the inbox');
        $this->assertSame(404, $member->get('/messages/view/demo-author')->status, 'the thread');
        $this->assertSame(404, $member->get('/messages/new/demo-author')->status, 'the compose form');
        $this->assertSame(404, $member->postWithToken('/messages/send/demo-author', ['body' => 'Sneaky.'])->status, 'the send');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM messages')['c'], 'no send fired while off');
        // The layout member link hides beside Notifications.
        $account = $member->get('/account');
        $this->assertSame(200, $account->status);
        $this->assertStringNotContainsString('href="/messages"', $account->body, 'the layout Messages link hides');
        // Back on: the surfaces and the link return.
        $this->flagOn('pms');
        $this->assertSame(200, $member->get('/messages')->status);
        $this->assertSame(200, $member->get('/messages/view/demo-author')->status);
        $this->assertStringContainsString('href="/messages"', $member->get('/account')->body);
    }

    public function test_mute_off_404s_the_toggles_hides_the_buttons_and_stops_filtering(): void
    {
        // The mute plants first (flag on), so the off case has a live row that
        // MUST stop filtering the moment the flag drops (finding 9).
        $this->assertSame(302, $this->client($this->memberUserId)->postWithToken('/mute/add/demo-author')->status);
        $this->flagOff('mute');
        $member = $this->client($this->memberUserId);
        $this->assertSame(404, $member->postWithToken('/mute/add/demo-author')->status);
        $this->assertSame(404, $member->postWithToken('/mute/remove/demo-author')->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM muted')['c'], 'no toggle fired while off');
        // The buttons hide on the profile and the directory; the account block hides.
        $this->assertStringNotContainsString('/mute/add/', $member->get('/user/view/demo-author')->body);
        $this->assertStringNotContainsString('/mute/add/', $member->get('/browse/authors')->body);
        $this->assertStringNotContainsString(\App\Lang::t('account.muted'), $member->get('/account')->body);
        // The planted row stops filtering: listings and search show the author again.
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $member->get('/browse/recent')->body);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $member->get('/search', ['q' => 'rabbit'])->body);
        // Back on: the filter resumes (the row is still planted) and the buttons return.
        $this->flagOn('mute');
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $member->get('/browse/recent')->body);
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $member->get('/search', ['q' => 'rabbit'])->body);
        $this->assertStringContainsString('/mute/add/', $member->get('/user/view/demo-author')->body);
    }

    public function test_wrangling_off_404s_all_three_actions(): void
    {
        $this->flagOff('wrangling');
        $admin = $this->client($this->adminUserId);
        $this->assertSame(404, $admin->get('/wrangling')->status, 'the index');
        $this->assertSame(404, $admin->postWithToken('/wrangling/merge', ['synonym_id' => 2, 'canonical_id' => 1])->status, 'the merge');
        $this->assertSame(404, $admin->postWithToken('/wrangling/unmerge/2')->status, 'the unmerge');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM story_tags')['c'], 'no write fired while off');
        $this->assertNull($this->db->one('SELECT canonical_id FROM tags WHERE id = 2')['canonical_id'], 'no retirement fired while off');
        // Back on: the admin index returns.
        $this->flagOn('wrangling');
        $this->assertSame(200, $admin->get('/wrangling')->status);
    }
}
