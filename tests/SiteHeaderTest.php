<?php // tests/SiteHeaderTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// The site header and navigation: the Browse panel, the member account menu,
// staff entries and the badge counts. Markup pins only; the look is checked
// in a browser.
final class SiteHeaderTest extends TestCase
{
    private string $path = '';
    private string $navFile = '';
    private Database $db;
    private int $memberId = 0;
    private int $adminId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-head-') . '.sqlite';
        $this->navFile = tempnam(sys_get_temp_dir(), 'kiption-head-nav-') . '.json';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        \App\NavLinks::rebuild($this->db, $this->navFile);
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ['headadmin@e.test', password_hash('password123', PASSWORD_DEFAULT), 'headadmin', 'admin', 1, date('c'), date('c'), 'headadmin']);
        $this->adminId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        \App\Features::reset(); // the switched-off test must not leak its flags into later tests
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm', $this->navFile, \App\BrowsePanel::fileFor($this->navFile)] as $f) @unlink($f);
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'app_dir' => dirname(__DIR__) . '/app', 'env' => 'prod', 'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example', 'nav_file' => $this->navFile,
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    private function head(string $html): string
    {
        return substr($html, (int) strpos($html, '<header'), (int) strpos($html, '</header>') - (int) strpos($html, '<header'));
    }

    public function test_browse_panel_lists_categories_with_counts_ways_in_and_tags(): void
    {
        $body = $this->client()->get('/')->body;
        $panel = substr($body, (int) strpos($body, 'class="browse-panel"'));
        $panel = substr($panel, 0, (int) strpos($panel, '</details>'));
        $cat = $this->db->one('SELECT slug FROM categories ORDER BY position, name LIMIT 1');
        $this->assertStringContainsString('href="/browse/category/' . $cat['slug'] . '"', $panel);
        $this->assertStringContainsString('class="bp-n"', $panel, 'each category carries its story count');
        foreach (['/top', '/browse', '/browse/filter'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $panel, $href);
        }
        $this->assertStringContainsString('All Categories', $panel);
        $this->assertStringContainsString('Filter by Tags', $panel);
        $this->assertStringContainsString('Not Sure Where to Start?', $panel);
        $this->assertStringContainsString('class="bp-chips"', $panel);
    }

    public function test_browse_panel_degrades_when_the_artifact_is_missing(): void
    {
        @unlink(\App\BrowsePanel::fileFor($this->navFile));
        $panel = $this->client()->get('/')->body;
        $this->assertStringNotContainsString('bp-catlist', $panel);
        $this->assertStringContainsString('All Categories', $panel, 'the panel still offers the full list');
    }

    public function test_a_switched_off_destination_leaves_the_panel_and_the_footer(): void
    {
        \App\Features::init($this->db, ['toplists' => false]);
        $body = $this->client()->get('/')->body;
        $this->assertStringNotContainsString('href="/top"', $body);
    }

    public function test_member_header_has_outlined_post_inbox_and_the_account_menu(): void
    {
        $head = $this->head($this->client($this->memberId)->get('/')->body);
        $this->assertStringContainsString('class="head-post"', $head);
        $this->assertStringContainsString('<a class="nav-account" href="/account">Library</a>', $head, 'Library replaces Series for members');
        $this->assertStringContainsString('href="/notifications"', $head);
        $this->assertStringContainsString('<details class="nav-me" name="site-menus">', $head);
        foreach (['href="/account#your-stories"', 'href="/account/settings"', 'action="/auth/logout"'] as $needle) {
            $this->assertStringContainsString($needle, $head);
        }
        $this->assertStringContainsString('name="_token"', $head);
        $this->assertStringNotContainsString('href="/auth/login"', $head);
        $this->assertStringNotContainsString('href="/manage"', $head, 'no staff entries for a member');
        $this->assertStringNotContainsString('class="nav-join"', $head);
    }

    public function test_admin_menu_carries_the_archive_admin_entry(): void
    {
        $head = $this->head($this->client($this->adminId)->get('/')->body);
        $this->assertStringContainsString('href="/manage"', $head);
        $this->assertStringContainsString('href="/queue"', $head);
    }

    public function test_unread_inbox_count_rides_the_session_row_and_shows_a_badge(): void
    {
        $this->db->query('INSERT INTO notifications (user_id, kind, created_at) VALUES (?, ?, ?)', [$this->memberId, 'comment', gmdate('c')]);
        $head = $this->head($this->client($this->memberId)->get('/')->body);
        $this->assertStringContainsString('<b class="nav-count">', $head);
        $this->assertStringContainsString('aria-label="Inbox, 1 unread"', $head);
        $none = $this->head($this->client($this->adminId)->get('/')->body);
        $this->assertStringNotContainsString('nav-count', $none, 'no badge at zero');
    }

    public function test_guest_bytes_carry_no_member_state(): void
    {
        $body = $this->client()->get('/')->body;
        foreach (['class="nav-me"', 'nav-count', 'name="_token"', 'head-post', 'sheet-logout'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, $needle);
        }
    }

    public function test_the_phone_sheet_and_header_work_without_scripting(): void
    {
        $body = $this->client()->get('/')->body;
        $this->assertStringNotContainsString('<script', $this->head($body));
        $this->assertStringContainsString('<a class="nav-menu-link" href="#menu">', $body);
        $this->assertStringContainsString('<div id="menu" class="sheet"', $body);
        $this->assertSame(2, substr_count($body, 'name="site-menus"'), 'Browse and the theme popover share one name, so one opens at a time');
    }
}
