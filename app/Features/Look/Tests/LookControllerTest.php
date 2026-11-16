<?php // app/Features/Look/Tests/LookControllerTest.php
namespace App\Features\Look\Tests;
use App\Lang;
use App\Look\{Boot, Compiler, Store};
use Kip\{App, Database};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The colors and layout editor (/look): admin only, CSRF on every write,
 *  every value through App\Look\Rules, and the public stylesheet route. */
final class LookControllerTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;
    private int $modId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-look-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-look-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $ins = 'INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $this->db->query($ins, ['la@e.test', $hash, 'lookadmin', 'admin', 1, date('c'), date('c'), 'lookadmin']);
        $this->adminId = (int) $this->db->lastInsertId();
        $this->db->query($ins, ['lm@e.test', $hash, 'lookmember', 'member', 0, date('c'), date('c'), 'lookmember']);
        $this->memberId = (int) $this->db->lastInsertId();
        $this->db->query($ins, ['lmod@e.test', $hash, 'lookmod', 'moderator', 0, date('c'), date('c'), 'lookmod']);
        $this->modId = (int) $this->db->lastInsertId();
        Lang::reset();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        Boot::reset();
        Lang::reset();
        unset($this->db);
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function lookDir(): string
    {
        return $this->root . '/site-look';
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        $app = new App(\App\Settings::apply($extra + [
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
            'site_look_dir' => $this->lookDir(),
        ]));
        $c = new TestClient($app);
        return $as === null ? $c : $c->actingAs($as);
    }

    /** @return array<string,string> */
    private function form(array $over = []): array
    {
        return $over + ['accent' => '#8a2be2', 'bg' => '#ffffff', 'ink' => '#111111',
            'width' => 'wide', 'size' => 'large', 'density' => 'roomy', 'header' => 'tinted'];
    }

    public function test_every_route_bars_non_admins_and_guests(): void
    {
        foreach ([$this->memberId, $this->modId] as $who) {
            $c = $this->client($who);
            $this->assertSame(403, $c->get('/look')->status);
            foreach (['/look/preview', '/look/save', '/look/reset'] as $url) {
                $this->assertSame(403, $c->postWithToken($url, $this->form())->status, $url);
            }
        }
        $guest = $this->client();
        $this->assertSame(302, $guest->get('/look')->status);
        $this->assertNull(Store::get());
    }

    public function test_writes_need_post_and_a_csrf_token(): void
    {
        $c = $this->client($this->adminId);
        foreach (['/look/preview', '/look/save', '/look/reset'] as $url) {
            $this->assertSame(405, $c->get($url)->status, $url . ' is POST only');
            $this->assertContains($c->post($url, $this->form())->status, [403, 419], $url . ' needs a token');
        }
        $this->assertSame('', \App\Settings::get(Store::KEY));
    }

    public function test_the_screen_shows_the_form_and_both_panes(): void
    {
        $r = $this->client($this->adminId)->get('/look');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('<h1>Colors and Layout</h1>', $r->body);
        foreach (['accent', 'bg', 'ink', 'width', 'size', 'density', 'header'] as $f) {
            $this->assertStringContainsString('name="' . $f . '"', $r->body);
        }
        $this->assertStringContainsString('data-look-pane="light"', $r->body);
        $this->assertStringContainsString('data-look-pane="night"', $r->body);
        $this->assertStringContainsString('data-js-module="look"', $r->body);
        $this->assertSame(0, preg_match('~<script(?![^>]*\ssrc=)~', $r->body), 'no inline script');
    }

    public function test_a_save_stores_the_row_compiles_the_file_and_is_logged(): void
    {
        $c = $this->client($this->adminId);
        $r = $c->postWithToken('/look/save', $this->form());
        $this->assertSame(302, $r->status);
        $this->assertStringStartsWith('/look?saved=', (string) ($r->headers['Location'] ?? ''));
        $saved = Store::get();
        $this->assertSame('#8a2be2', $saved['accent']);
        $this->assertSame('wide', $saved['width']);
        $file = file_get_contents($this->lookDir() . '/look.css');
        $this->assertStringContainsString('/* look ' . Compiler::revision($saved) . ' */', $file);
        $this->assertStringContainsString('#8a2be2', $file);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM activity_log WHERE action = 'look.save'")['c']);
    }

    public function test_bad_input_changes_nothing_and_says_why(): void
    {
        $c = $this->client($this->adminId);
        $low = $c->postWithToken('/look/save', $this->form(['ink' => '#dddddd']));
        $this->assertSame(422, $low->status);
        $this->assertStringContainsString('to 1. It needs at least 4.5 to 1.', $low->body);
        $this->assertStringContainsString('value="#dddddd"', $low->body, 'the form keeps what was typed');
        $this->assertSame(422, $c->postWithToken('/look/save', $this->form(['accent' => 'red']))->status);
        $this->assertSame(422, $c->postWithToken('/look/save', $this->form(['width' => 'huge']))->status);
        $this->assertSame(422, $c->postWithToken('/look/save', ['accent' => ['x']] + $this->form())->status);
        $this->assertNull(Store::get());
        $this->assertFileDoesNotExist($this->lookDir() . '/look.css');
        // A markup payload re-renders only escaped: the raw string is nowhere in the page.
        $evil = $c->postWithToken('/look/save', $this->form(['accent' => '"><script>alert(1)</script>']));
        $this->assertSame(422, $evil->status);
        $this->assertStringNotContainsString('<script>alert(1)', $evil->body);
    }

    public function test_a_save_and_a_reset_purge_the_static_cache_and_log(): void
    {
        $static = $this->root . '/static';
        mkdir($static, 0775, true);
        file_put_contents($static . '/index.html', 'stale');
        $c = $this->client($this->adminId);
        $c->postWithToken('/look/save', $this->form());
        $this->assertFileDoesNotExist($static . '/index.html', 'the save purged the static cache');
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM activity_log WHERE action = 'look.save'")['c']);
        $c->postWithToken('/look/reset', ['confirmed' => '1']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM activity_log WHERE action = 'look.reset'")['c']);
        $this->assertNull(Store::get());
    }

    public function test_an_unwritable_folder_rolls_the_row_back(): void
    {
        file_put_contents($this->root . '/blocker', 'x'); // a file where the folder should be
        $c = $this->client($this->adminId, ['site_look_dir' => $this->root . '/blocker/site-look']);
        $r = $c->postWithToken('/look/save', $this->form());
        $this->assertSame(503, $r->status);
        $this->assertStringContainsString('Nothing was changed.', $r->body);
        $this->assertNull(Store::get());
        // A saved look survives a failed second save.
        $c2 = $this->client($this->adminId);
        $c2->postWithToken('/look/save', $this->form());
        $before = Store::get();
        $c3 = $this->client($this->adminId, ['site_look_dir' => $this->root . '/blocker/site-look']);
        $this->assertSame(503, $c3->postWithToken('/look/save', $this->form(['accent' => '#aa0000']))->status);
        $this->assertSame($before, Store::get());
    }

    public function test_preview_answers_json_without_saving(): void
    {
        $c = $this->client($this->adminId);
        $r = $c->postWithToken('/look/preview', $this->form() + ['fragment' => '1']);
        $this->assertSame(200, $r->status);
        $body = json_decode($r->body, true);
        $this->assertTrue($body['ok']);
        $this->assertSame('#8a2be2', $body['vars']['light']['--accent']);
        $this->assertArrayHasKey('--bg', $body['vars']['night']);
        $this->assertSame('wide', $body['presets']['width']);
        $this->assertNull(Store::get());
        $bad = $c->postWithToken('/look/preview', $this->form(['ink' => '#eeeeee']) + ['fragment' => '1']);
        $this->assertSame(422, $bad->status);
        $this->assertArrayHasKey('ink', json_decode($bad->body, true)['errors']);
        // No fragment: the whole page, painted from the posted values.
        $page = $c->postWithToken('/look/preview', $this->form());
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('data-width="wide"', $page->body);
        $this->assertNull(Store::get());
    }

    public function test_reset_asks_first_then_removes_the_row_and_file(): void
    {
        $c = $this->client($this->adminId);
        $c->postWithToken('/look/save', $this->form());
        $ask = $c->postWithToken('/look/reset', []);
        $this->assertSame(200, $ask->status);
        $this->assertStringContainsString('Go back to the built-in colors and layout?', $ask->body);
        $this->assertNotNull(Store::get(), 'asking changes nothing');
        $r = $c->postWithToken('/look/reset', ['confirmed' => '1']);
        $this->assertSame(302, $r->status);
        $this->assertNull(Store::get());
        $this->assertFileDoesNotExist($this->lookDir() . '/look.css');
    }

    public function test_the_stylesheet_route_serves_with_the_right_cache_header(): void
    {
        $c = $this->client();
        $this->assertSame(404, $c->get('/look/css')->status, 'nothing saved');
        $this->client($this->adminId)->postWithToken('/look/save', $this->form());
        $rev = Compiler::revision((array) Store::get());
        $fresh = $this->client()->get('/look/css', ['v' => $rev]);
        $this->assertSame(200, $fresh->status);
        $this->assertStringStartsWith('text/css', $fresh->headers['Content-Type']);
        $this->assertSame('nosniff', $fresh->headers['X-Content-Type-Options']);
        $this->assertSame('public, max-age=31536000, immutable', $fresh->headers['Cache-Control']);
        $this->assertSame(Compiler::css((array) Store::get()), $fresh->body, 'the route serves the compiled sheet, byte for byte');
        $stale = $this->client()->get('/look/css', ['v' => 'oldoldold0']);
        $this->assertSame('public, max-age=300', $stale->headers['Cache-Control']);
        $this->assertSame('public, max-age=300', $this->client()->get('/look/css')->headers['Cache-Control']);
    }

    public function test_a_row_that_got_in_by_hand_never_reaches_the_stylesheet(): void
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        foreach (['{"accent":"red;}body{display:none","bg":"#ffffff","ink":"#111111","width":"wide","size":"standard","density":"standard","header":"standard"}', 'not json', '[]'] as $raw) {
            $this->db->query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [Store::KEY, $raw]);
            \App\Features::reset();
            $r = $this->client()->get('/look/css', ['v' => 'p' . md5($raw)]);
            $this->assertSame(404, $r->status, $raw);
            $this->assertStringNotContainsString('display:none', $r->body);
        }
    }

    public function test_the_stylesheet_heals_a_missing_or_stale_file(): void
    {
        $this->client($this->adminId)->postWithToken('/look/save', $this->form());
        $file = $this->lookDir() . '/look.css';
        unlink($file);
        $this->assertStringContainsString('#8a2be2', $this->client()->get('/look/css')->body);
        $this->assertFileExists($file, 'rebuilt on demand');
        file_put_contents($file, "/* look stale */\nbody{display:none}");
        $body = $this->client()->get('/look/css', ['v' => 'again'])->body;
        $this->assertStringNotContainsString('display:none', $body);
        $this->assertStringContainsString('#8a2be2', file_get_contents($file));
        // An unwritable folder still serves the page's stylesheet.
        $c = $this->client(null, ['site_look_dir' => $this->root . '/nope/deeper']);
        file_put_contents($this->root . '/nope', 'x');
        $r = $c->get('/look/css');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('#8a2be2', $r->body);
    }
}
