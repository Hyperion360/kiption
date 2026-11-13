<?php // app/Features/Manage/Tests/AdminShellTest.php
namespace App\Features\Manage\Tests;
use App\Lang;
use Kip\{App, Database};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** What every owner screen carries around its own content: the list of
 *  admin screens with the current one marked, and the phone tab bar. */
final class AdminShellTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-shell-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-shell-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $ins = 'INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $this->db->query($ins, ['ma@e.test', $hash, 'mmadmin', 'admin', 1, date('c'), date('c'), 'mmadmin']);
        $this->adminId = (int) $this->db->lastInsertId();
        $this->db->query($ins, ['mm@e.test', $hash, 'mmmember', 'member', 0, date('c'), date('c'), 'mmmember']);
        $this->memberId = (int) $this->db->lastInsertId();
        Lang::reset();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        \App\Maintenance::reset();
        \App\Features\Wordfilter\WordFilter::forget();
        Lang::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function config(array $extra = []): array
    {
        return \App\Settings::apply($extra + [
            'env' => 'prod',
            'views' => dirname(__DIR__, 3) . '/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example', 'ui_lang' => 'en',
            'nav_file' => $this->root . '/nav.json',
            'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__, 2),
            'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'],
            'site_text_dir' => $this->root . '/site-text',
        ]);
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        Lang::useSiteText($this->root . '/site-text');
        $c = new TestClient(new App($this->config($extra)));
        return $as === null ? $c : $c->actingAs($as);
    }

    public function test_every_owner_screen_lists_the_admin_screens_and_marks_the_current_one(): void
    {
        $body = $this->client($this->adminId)->get('/tags')->body;
        $this->assertStringContainsString('<nav id="admin-nav" class="admin-nav"', $body);
        foreach (['manage.group_stories', 'manage.group_pages', 'manage.group_members', 'manage.group_look', 'manage.group_settings', 'manage.group_advanced'] as $group) {
            $this->assertStringContainsString('>' . Lang::t($group) . '</p>', $body, $group);
        }
        foreach (array_column(\App\Features\Manage\ManageController::LINKS, 1) as $url) {
            $this->assertStringContainsString('<li><a href="' . $url . '"', $body, $url);
        }
        $this->assertStringContainsString('<li><a href="/tags" aria-current="page">', $body);
        $this->assertSame(1, substr_count($body, 'aria-current="page"'), 'one current screen, and Tags is not a phone tab');
    }

    public function test_a_sub_screen_marks_the_list_it_belongs_to(): void
    {
        $c = $this->client($this->adminId);
        $this->assertStringContainsString('<li><a href="/text" aria-current="page">', $c->get('/text/group/home')->body);
        $this->assertStringContainsString('<li><a href="/page/new" aria-current="page">', $c->get('/page/new')->body);
        $this->assertStringContainsString('class="admin-trail"', $c->get('/text/group/home')->body, 'a sub-screen keeps the way back to its list');
        $this->assertStringNotContainsString('class="admin-trail"', $c->get('/tags')->body, 'a listed screen needs no way back: the list is on screen');
    }

    public function test_the_phone_tab_bar_is_plain_links_and_marks_its_own_screens(): void
    {
        $c = $this->client($this->adminId);
        $home = $c->get('/manage')->body;
        $this->assertStringContainsString('<nav class="admin-tabs"', $home);
        $this->assertStringContainsString('<a href="#admin-nav">', $home, 'Menu opens the full list without scripting');
        $this->assertStringContainsString('<a class="admin-nav-home" href="/manage" aria-current="page">', $home);
        $this->assertMatchesRegularExpression('#<nav class="admin-tabs".*<a href="/manage" aria-current="page">#s', $home);
        $this->assertMatchesRegularExpression('#<nav class="admin-tabs".*<a href="/settings" aria-current="page">#s', $c->get('/settings')->body);
        $this->assertStringNotContainsString('<script>', $home);
    }

    public function test_only_admins_on_owner_screens_get_the_admin_list(): void
    {
        $this->assertStringNotContainsString('admin-nav', $this->client($this->adminId)->get('/')->body, 'reading pages stay reading pages');
        $this->assertStringNotContainsString('admin-nav', $this->client($this->memberId)->get('/')->body);
        $this->assertStringNotContainsString('admin-nav', $this->client()->get('/news')->body);
    }

    public function test_the_list_follows_feature_switches_and_the_database_switch(): void
    {
        $this->assertStringContainsString('<li><a href="/admin"', $this->client($this->adminId)->get('/manage')->body);
        $this->assertStringNotContainsString('href="/admin"', $this->client($this->adminId, ['admin' => ['enabled' => false]])->get('/manage')->body);
        \App\AdminNav::init(['admin' => ['enabled' => false]]);
        $this->assertStringNotContainsString('href="/admin"', $this->client($this->adminId)->get('/tags')->body);
        \App\AdminNav::reset();
        $c = $this->client($this->adminId);
        \App\Features::toggle('wrangling', false);
        $this->assertStringNotContainsString('<li><a href="/wrangling"', $c->get('/categories')->body);
    }
}
