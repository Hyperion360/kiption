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
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
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
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
            'app_dir' => $this->root . '/app',
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
}
