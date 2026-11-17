<?php // tests/MenuLinksTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;

use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Session;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The site menu on every public page: "Post a story" for members, the staff
 *  group for moderators and admins, and neither in anything a guest can be
 *  served from a cache. Roles come from SessionGuard, which public/index.php
 *  runs before the app; these tests run it the same way before each request. */
#[Medium]
final class MenuLinksTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $modId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-menu-');
        $this->root = sys_get_temp_dir() . '/kiption-menu-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $hash = \App\Tests\Support\Hashes::of('password123');
        foreach ([['menuadmin', 'admin', 1], ['menumod', 'moderator', 0], ['menumember', 'member', 0]] as [$name, $role, $is]) {
            $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$name . '@e.test', $hash, $name, $role, $is, date('c'), date('c'), $name]);
            $id = (int) $this->db->lastInsertId();
            match ($role) { 'admin' => $this->adminId = $id, 'moderator' => $this->modId = $id, default => $this->memberId = $id };
        }
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        \App\SiteName::reset();
        $this->guestRole();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function guestRole(): void
    {
        $store = [];
        \App\SessionGuard::valid(new Database('sqlite:' . $this->path), new Session($store));
    }

    private function client(?int $as = null): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        $config = \App\Settings::apply([
            'env' => 'prod', 'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'registration_mode' => 'verify', 'validation_required' => false, 'items_per_page' => 20,
            'nav_file' => $this->root . '/nav.json', 'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__) . '/app/Features',
            'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'],
        ]);
        $client = new TestClient(new App($config));
        return $as === null ? $client : $client->actingAs($as);
    }

    /** One page as the front controller serves it: the session guard first, then the app. */
    private function page(string $path, ?int $as = null): string
    {
        $client = $this->client($as);
        if ($as === null) $this->guestRole();
        else \App\SessionGuard::enforce(new Database('sqlite:' . $this->path), $client->session);
        return $client->get($path)->body;
    }

    public function test_signed_in_admin_sees_staff_links_on_a_public_page(): void
    {
        foreach (['/browse', '/', '/story/view/the-rabbit-hole'] as $path) {
            $html = $this->page($path, $this->adminId);
            $this->assertStringContainsString('href="/manage"', $html, $path);
            $this->assertStringContainsString('href="/queue"', $html, $path);
            $this->assertStringContainsString('href="/story/new"', $html, $path);
        }
    }

    public function test_moderator_sees_queue_but_not_manage(): void
    {
        $html = $this->page('/', $this->modId);
        $this->assertStringContainsString('href="/queue"', $html);
        $this->assertStringNotContainsString('href="/manage"', $html);
    }

    public function test_member_sees_post_a_story_and_no_staff_links(): void
    {
        $html = $this->page('/story/view/the-rabbit-hole', $this->memberId);
        $this->assertStringContainsString('href="/story/new"', $html);
        $this->assertStringNotContainsString('href="/queue"', $html);
        $this->assertStringNotContainsString('href="/manage"', $html);
        $menu = substr($html, (int) strpos($html, 'id="menu"'));
        $this->assertStringContainsString('class="sheet-post"', $menu, 'the Post button sits in the phone sheet');
    }

    public function test_post_link_hidden_from_members_while_posting_is_paused_but_not_from_admins(): void
    {
        \App\Features::init($this->db, []);
        \App\Settings::put('submissions_open', '0');
        $this->assertStringNotContainsString('href="/story/new"', $this->page('/browse', $this->memberId));
        $this->assertStringContainsString('href="/story/new"', $this->page('/browse', $this->adminId));
    }

    public function test_guest_page_never_carries_staff_links(): void
    {
        $html = $this->page('/browse');
        $this->assertStringNotContainsString('href="/manage"', $html);
        $this->assertStringNotContainsString('href="/queue"', $html);
    }

    public function test_a_session_cookie_with_no_live_session_shows_no_staff_links(): void
    {
        $client = $this->client();
        $client->cookie(session_name(), bin2hex(random_bytes(16)));
        \App\SessionGuard::enforce(new Database('sqlite:' . $this->path), $client->session); // what index.php does for a cookied request
        $html = $client->get('/browse')->body;
        $this->assertStringNotContainsString('href="/manage"', $html);
        $this->assertStringNotContainsString('href="/queue"', $html);
    }

    public function test_admin_home_and_account_page_offer_post_a_story(): void
    {
        $this->assertStringContainsString('class="admin-quick"', $this->page('/manage', $this->adminId));
        // Always, not only when the member has no stories yet.
        $demo = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $html = $this->page('/account', $demo);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'href="/story/new"'), 'menu link plus the page link');
    }

    public function test_every_skin_menu_renders_the_same_links(): void
    {
        foreach (['classic', 'manuscript'] as $skin) {
            $layout = (string) file_get_contents(dirname(__DIR__) . "/app/skins/{$skin}/views/layout.php");
            $this->assertStringContainsString("render('menu_post')", $layout, $skin);
            $this->assertStringContainsString("render('menu_staff')", $layout, $skin);
            $this->assertStringNotContainsString('href="/manage"', $layout, $skin);
        }
    }
}
