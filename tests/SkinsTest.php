<?php // tests/SkinsTest.php
namespace App\Tests;

use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use Kip\View;
use PHPUnit\Framework\TestCase;

/** Operator skins: a directory of view overrides under app/skins/{name}/views
 *  that wins over the app defaults template-by-template, wired into the boot
 *  seam as the framework's views_override key. Pins the resolution order
 *  (skin file wins, app default serves the miss), the board's purge on a skin
 *  switch (cached pages are skinned pages), and the seam's tolerance of a
 *  missing or malformed skin name (fall back to the app defaults, never a
 *  fatal page). */
final class SkinsTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-skins-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-skins-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        // The temp app tree carries the shipped skins by symlink: client tests
        // resolve real skin templates while every write the app makes (the
        // board purge's framework-cache unlink above all) stays in the temp
        // root, never the repo checkout.
        symlink(dirname(__DIR__) . '/app/skins', $this->root . '/app/skins');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    /** The repo's real app tree: the shipped skins live there. */
    private static function appDir(): string
    {
        return dirname(__DIR__) . '/app';
    }

    public function test_override_dir_resolves_only_real_skin_directories(): void
    {
        $appDir = self::appDir();
        $classic = \App\Skins::overrideDir(['app_dir' => $appDir, 'skin' => 'classic']);
        $this->assertSame(realpath($appDir . '/skins/classic/views'), $classic);
        $this->assertFileExists($classic . '/layout.php');
        $this->assertFileExists(realpath($appDir . '/skins/manuscript/views') . '/story/_chapter.php', 'the manuscript chapter override ships');
        // default is the explicit no-override sentinel
        $this->assertSame('', \App\Skins::overrideDir(['app_dir' => $appDir, 'skin' => 'default']));
        // a well-formed name with no directory (Settings::put cannot know the
        // filesystem) reads as default, never a fatal render
        $this->assertSame('', \App\Skins::overrideDir(['app_dir' => $appDir, 'skin' => 'ghostskin']), 'missing skin dir falls back to defaults');
        // junk names never reach the filesystem as a path
        $this->assertSame('', \App\Skins::overrideDir(['app_dir' => $appDir, 'skin' => '../config']));
        $this->assertSame('', \App\Skins::overrideDir(['app_dir' => $appDir, 'skin' => 'Big Skin!']));
        // no app_dir configured: nothing to resolve from
        $this->assertSame('', \App\Skins::overrideDir(['skin' => 'classic']));
    }

    public function test_layout_resolves_from_the_skin_and_misses_fall_through(): void
    {
        \App\Lang::setCurrent('en');
        $appDir = self::appDir();
        $config = ['app_dir' => $appDir, 'skin' => 'classic'];
        $view = new View($appDir . '/views', $appDir . '/Features', \App\Skins::overrideDir($config));
        // The skin's layout.php wins: its own body class and stylesheet link.
        $html = $view->render('layout', ['title' => 'Skin probe', 'loggedIn' => false, 'content' => '']);
        $this->assertStringContainsString('skin-classic', $html);
        $this->assertStringContainsString('/assets/skins/classic.css', $html);
        // A template the skin does NOT carry resolves from the app default:
        // maintenance.php exists only in app/views and renders standalone.
        $maintenance = $view->render('maintenance');
        $this->assertStringContainsString('Scheduled maintenance', $maintenance);
        $this->assertStringNotContainsString('skin-classic', $maintenance, 'the miss is served unskinned');
        // With no skin wired, the app's own layout serves and carries no skin markers.
        $plain = (new View($appDir . '/views', $appDir . '/Features', ''))
            ->render('layout', ['title' => 'Plain probe', 'loggedIn' => false, 'content' => '']);
        $this->assertStringContainsString('/assets/reader.css', $plain);
        $this->assertStringNotContainsString('/assets/skins/', $plain);
    }

    public function test_skinned_render_uses_the_default_view_the_skin_does_not_override(): void
    {
        $this->seed();
        // The series index is overridden by no skin: under manuscript it still
        // renders, inside the manuscript layout.
        $res = $this->client(null, ['skin' => 'manuscript'])->get('/series');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('skins/manuscript.css', (string) $res->body, 'layout resolved from the skin');
        $this->assertStringContainsString('<h1>Series</h1>', (string) $res->body, 'series index served by the app default');
        // The manuscript chapter override carries the machine-read contract
        // (chapter unit, h-entry, unit-state, beacon) plus its own opening.
        $read = $this->client(null, ['skin' => 'manuscript'])->get('/story/read/the-rabbit-hole/1');
        $this->assertSame(200, $read->status);
        $this->assertStringContainsString('<div class="chapter-unit" data-read-url="/story/read/the-rabbit-hole/1"', (string) $read->body);
        $this->assertStringContainsString('<article class="h-entry"', (string) $read->body);
        $this->assertStringContainsString('template class="unit-state"', (string) $read->body);
        $this->assertStringContainsString('src="/beacon/read/', (string) $read->body);
        $this->assertStringContainsString('manuscript-opening', (string) $read->body, 'the chapter view is the skin\'s own');
    }

    public function test_skin_switch_on_the_board_purges_the_static_layer(): void
    {
        $this->seed();
        $cacheDir = $this->root . '/static';
        mkdir($cacheDir, 0775, true);
        $static = new \App\StaticCache\Cache($cacheDir);
        $homeReq = new Request('GET', '/', [], [], []);
        $static->maybeStore($homeReq, new Response('cached default-skin home', 200));
        $this->assertNotNull($static->serve($homeReq));
        // The board write carries the skin field; every save purges (the Task 3
        // precedent), because a cached page is a skinned page.
        $this->assertSame(302, $this->client($this->adminUserId)->postWithToken('/settings/save', [
            'site_name' => 'Skinned Archive', 'registration_mode' => 'verify',
            'validation_required' => '1', 'items_per_page' => '20', 'powered_by' => '1',
            'skin' => 'classic',
        ])->status);
        $this->assertNull($static->serve($homeReq), 'the default-skin cache file did not survive the switch');
        $this->assertSame([], array_values(array_diff(scandir($cacheDir) ?: [], ['.', '..'])), 'cache dir empty after the board save');
        $this->assertSame('classic', $this->db->one('SELECT value FROM settings WHERE key = ?', ['skin'])['value']);
        // A fresh boot (the entrypoint seam) now renders classic pages.
        $this->assertStringContainsString('skins/classic.css', (string) $this->client()->get('/')->body);
    }

    public function test_a_malformed_skin_still_renders_default_pages(): void
    {
        $this->seed();
        // Settings::put blocks the shape at write time; the boot seam still
        // tolerates a row planted by other means (direct SQL, a restored DB).
        $this->db->query("INSERT INTO settings (key, value) VALUES ('skin', 'No Such/Skin')");
        \App\Features::init($this->db, []);
        $home = $this->client();
        $this->assertSame(200, $home->get('/')->status);
        $this->assertStringNotContainsString('/assets/skins/', (string) $home->get('/browse')->body, 'junk skin: default views, no fatal');
    }

    public function test_pages_build_renders_skinned_pages(): void
    {
        $this->seed();
        $dir = $this->root . '/pages';
        $config = $this->config(['skin' => 'classic']);
        $config['views_override'] = \App\Skins::overrideDir($config); // the bin/kip boot seam, mirrored
        // Builder::build constructs its own App from $config, so the override
        // key propagates: the pre-rendered layer is skinned, not default.
        $this->assertGreaterThan(0, \App\StaticCache\Builder::build($config, $dir));
        $hit = (new \App\StaticCache\Cache($dir))->serve(new Request('GET', '/', [], [], []));
        $this->assertNotNull($hit, 'the home page was pre-rendered');
        $this->assertStringContainsString('skins/classic.css', $hit->body);
    }

    /** The seed plus the admin fixture (the SettingsTest idiom). */
    private function seed(): void
    {
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('skinadmin@e.test', ?, 'skinadmin', 'admin', 1, ?, ?, 'skinadmin')",
            [$hash, date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
    }

    /** The test App config: views/features point at the REAL app tree (the
     *  shipped skin templates render), while app_dir is the temp root's app
     *  (with the skins symlinked in), so the app's writes, the board purge
     *  included, never touch a repo file. */
    private function config(array $extra = []): array
    {
        return $extra + [
            'env' => 'prod',
            'views' => self::appDir() . '/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'registration_mode' => 'verify', 'validation_required' => true, 'items_per_page' => 20,
            'powered_by' => true, 'skin' => 'default',
            'nav_file' => self::appDir() . '/nav.json',
            'app_dir' => $this->root . '/app',
            'features_dir' => self::appDir() . '/Features',
            'public_dir' => $this->root . '/public',
            'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'],
        ];
    }

    /** The entrypoint seam, mirrored: Features::init, Settings::apply, then
     *  the skins override dir, exactly the three lines of public/index.php. */
    private function client(?int $as = null, array $extra = []): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        $config = \App\Settings::apply($this->config($extra));
        $config['views_override'] = \App\Skins::overrideDir($config);
        $client = new TestClient(new App($config));
        return $as === null ? $client : $client->actingAs($as);
    }
}
