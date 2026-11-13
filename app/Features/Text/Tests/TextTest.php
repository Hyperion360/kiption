<?php // app/Features/Text/Tests/TextTest.php
namespace App\Features\Text\Tests;
use App\Lang;
use Kip\{App, Database};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The site text editor (/text): admin only, CSRF on every write, and every
 *  stored string goes through App\SiteText\Rules. */
final class TextTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;
    private int $modId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-text-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-text-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $ins = 'INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $this->db->query($ins, ['ta@e.test', $hash, 'textadmin', 'admin', 1, date('c'), date('c'), 'textadmin']);
        $this->adminId = (int) $this->db->lastInsertId();
        $this->db->query($ins, ['tm@e.test', $hash, 'textmember', 'member', 0, date('c'), date('c'), 'textmember']);
        $this->memberId = (int) $this->db->lastInsertId();
        $this->db->query($ins, ['tmod@e.test', $hash, 'textmod', 'moderator', 0, date('c'), date('c'), 'textmod']);
        $this->modId = (int) $this->db->lastInsertId();
        Lang::reset();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        Lang::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        Lang::useSiteText($this->root . '/site-text');
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
            'site_text_dir' => $this->root . '/site-text',
        ]));
        $c = new TestClient($app);
        return $as === null ? $c : $c->actingAs($as);
    }

    private function upload(string $contents, ?int $size = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kiption-text-up-');
        file_put_contents($tmp, $contents);
        return ['name' => 'x.json', 'type' => 'application/json', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => $size ?? strlen($contents)];
    }

    public function test_every_route_bars_non_admins_and_guests(): void
    {
        $gets = ['/text', '/text/group/nav', '/text/search?q=log', '/text/languages', '/text/export'];
        $posts = ['/text/save', '/text/addlanguage', '/text/removelanguage', '/text/import'];
        foreach ([$this->memberId, $this->modId] as $who) {
            $c = $this->client($who);
            foreach ($gets as $url) {
                $this->assertSame(403, $c->get(str_contains($url, '?') ? strstr($url, '?', true) : $url, str_contains($url, '?') ? ['q' => 'log'] : [])->status, $url);
            }
            foreach ($posts as $url) {
                $this->assertSame(403, $c->postWithToken($url, ['lang' => 'en', 'key' => 'nav.login', 'value' => 'Hi', 'code' => 'fr', 'name' => 'F', 'dir' => 'ltr'])->status, $url);
            }
        }
        $guest = $this->client();
        foreach ($gets as $url) {
            $this->assertSame(302, $guest->get(str_contains($url, '?') ? strstr($url, '?', true) : $url)->status, $url);
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM site_text')['c']);
    }

    public function test_writes_need_post_and_a_csrf_token(): void
    {
        $c = $this->client($this->adminId);
        foreach (['/text/save', '/text/addlanguage', '/text/removelanguage', '/text/import'] as $url) {
            $this->assertSame(405, $c->get($url)->status, $url . ' is POST only');
            $this->assertContains($c->post($url, ['lang' => 'en', 'key' => 'nav.login', 'value' => 'Hi'])->status, [403, 419], $url . ' needs a token');
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM site_text')['c']);
    }

    public function test_the_index_lists_groups_and_a_group_lists_its_strings(): void
    {
        $c = $this->client($this->adminId);
        $home = $c->get('/text');
        $this->assertSame(200, $home->status);
        $this->assertStringContainsString('/text/group/nav', $home->body);
        $group = $c->get('/text/group/nav');
        $this->assertSame(200, $group->status);
        $this->assertStringContainsString('values[nav.login]', $group->body);
        $this->assertStringContainsString('Log in', $group->body);
        $this->assertSame(404, $c->get('/text/group/nosuchgroup')->status);
        $this->assertSame(404, $c->get('/text', ['lang' => 'zz'])->status);
    }

    public function test_groups_show_a_readable_name_and_keep_their_code_in_the_address(): void
    {
        $c = $this->client($this->adminId);
        $home = $c->get('/text')->body;
        $this->assertStringContainsString('<a href="/text/group/adminmembers?lang=en"><span class="manage-name">Members admin</span>', $home);
        $this->assertStringContainsString('<a href="/text/group/foot?lang=en"><span class="manage-name">Footer</span>', $home);
        $this->assertStringNotContainsString('>adminmembers<', $home, 'codes never stand in for a name');
        $this->assertStringContainsString('<h3>What visitors and readers see</h3>', $home);
        $this->assertStringContainsString('<h3>What admins and moderators see</h3>', $home);
        $this->assertStringContainsString('<h1>Members admin</h1>', $c->get('/text/group/adminmembers')->body);
    }

    public function test_every_group_has_a_name_and_a_section(): void
    {
        // A new feature brings a new group of strings. This fails until that
        // group gets a text.group.<code> name and a place in a section.
        $base = \App\Lang::base();
        foreach ($base as $key => $text) {
            if (!is_string($text) || $key[0] === '_') continue;
            $code = \App\Features\Text\TextController::groupOfKey($key);
            $this->assertArrayHasKey('text.group.' . $code, $base, "the group $code has no name");
        }
        $home = $this->client($this->adminId)->get('/text')->body;
        $this->assertStringNotContainsString('<h3>Other</h3>', $home, 'a group is missing from the sections');
    }

    public function test_a_save_that_cannot_reach_the_live_site_is_not_a_success(): void
    {
        file_put_contents($this->root . "/blocker", "x"); // a file where the folder should go
        $c = $this->client($this->adminId, ["site_text_dir" => $this->root . "/blocker/site-text"]);
        try {
            $status = $c->postWithToken("/text/save", ["lang" => "en", "key" => "nav.login", "value" => "Sign in"])->status;
        } catch (\RuntimeException $e) {
            $status = 500;
        }
        $this->assertNotSame(302, $status, "the owner must not be told the save went live");
    }

    public function test_save_changes_the_site_and_an_empty_value_reverts(): void
    {
        $c = $this->client($this->adminId);
        $res = $c->postWithToken('/text/save', ['lang' => 'en', 'key' => 'nav.login', 'value' => 'Sign in']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('Sign in', $this->db->one("SELECT value FROM site_text WHERE lang = 'en' AND key = 'nav.login'")['value']);
        $this->assertStringContainsString('Sign in', (string) file_get_contents($this->root . '/site-text/en.php'));
        $this->assertStringContainsString('Sign in', $this->client()->get('/')->body, 'the public page shows the new text');
        $this->assertStringContainsString('Sign in', $c->get('/text/group/nav')->body);

        $c->postWithToken('/text/save', ['lang' => 'en', 'key' => 'nav.login', 'value' => '']);
        $this->assertNull($this->db->one("SELECT value FROM site_text WHERE key = 'nav.login'"));
        $this->assertFileDoesNotExist($this->root . '/site-text/en.php', 'no owner text left, so no file');
        $this->assertStringNotContainsString('Sign in', $this->client()->get('/')->body);
    }

    public function test_a_group_form_saves_many_strings_at_once(): void
    {
        $c = $this->client($this->adminId);
        $res = $c->postWithToken('/text/save', ['lang' => 'en', 'values' => ['nav.login' => 'Enter', 'nav.browse' => 'Look around']]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM site_text')['c']);
    }

    public function test_unsafe_text_and_bad_keys_get_422_and_store_nothing(): void
    {
        $c = $this->client($this->adminId);
        $bad = [
            ['nav.login', '<script>alert(1)</script>'],
            ['nav.login', "Line\nbreak"],
            ['nope.nope', 'x'],
            ['_dir', 'rtl'],
            ['nav.login', str_repeat('a', 2000)],
        ];
        foreach ($bad as [$key, $value]) {
            $this->assertSame(422, $c->postWithToken('/text/save', ['lang' => 'en', 'key' => $key, 'value' => $value])->status, $key);
        }
        $this->assertSame(422, $c->postWithToken('/text/save', ['lang' => 'zz', 'key' => 'nav.login', 'value' => 'x'])->status);
        $this->assertSame(422, $c->postWithToken('/text/save', ['lang' => 'en', 'values' => ['nav.login' => ['a']]])->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM site_text')['c']);
    }

    public function test_an_error_message_never_echoes_markup(): void
    {
        $c = $this->client($this->adminId);
        $res = $c->postWithToken('/text/save', ['lang' => 'en', 'values' => ['<img src=x onerror=alert(1)>' => 'x']]);
        $this->assertSame(422, $res->status);
        $this->assertStringNotContainsString('<img', $res->body);
    }

    public function test_search_finds_by_key_and_by_text(): void
    {
        $c = $this->client($this->adminId);
        $this->assertStringContainsString('values[nav.login]', $c->get('/text/search', ['q' => 'nav.login'])->body);
        $this->assertStringContainsString('values[nav.login]', $c->get('/text/search', ['q' => 'LOG IN'])->body);
        $this->assertSame(422, $c->get('/text/search', ['q' => ''])->status);
        $this->assertSame(422, $c->get('/text/search', ['q' => str_repeat('a', 101)])->status);
        $res = $c->get('/text/search', ['q' => 'zzzznomatch']);
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('values[', $res->body);
        $xss = $c->get('/text/search', ['q' => '<script>x</script>']);
        $this->assertStringNotContainsString('<script>x', $xss->body);
    }

    public function test_add_and_remove_a_language(): void
    {
        $c = $this->client($this->adminId);
        $this->assertSame(302, $c->postWithToken('/text/addlanguage', ['code' => 'fr', 'name' => 'Francais', 'dir' => 'ltr'])->status);
        $this->assertFileExists($this->root . '/site-text/fr.php');
        $this->assertStringContainsString('Francais', $c->get('/text/languages')->body);
        $this->assertSame(302, $c->postWithToken('/text/save', ['lang' => 'fr', 'key' => 'nav.login', 'value' => 'Connexion'])->status);
        $this->assertSame(422, $c->postWithToken('/text/addlanguage', ['code' => 'fr', 'name' => 'Again', 'dir' => 'ltr'])->status, 'duplicate');
        $this->assertSame(422, $c->postWithToken('/text/addlanguage', ['code' => 'FR1', 'name' => 'x', 'dir' => 'ltr'])->status);
        $this->assertSame(422, $c->postWithToken('/text/addlanguage', ['code' => 'de', 'name' => '<b>x', 'dir' => 'ltr'])->status);
        $this->assertSame(422, $c->postWithToken('/text/addlanguage', ['code' => 'de', 'name' => 'Deutsch', 'dir' => 'sideways'])->status);
        $this->assertSame(422, $c->postWithToken('/text/removelanguage', ['code' => 'en'])->status, 'built-in packs stay');
        $this->assertSame(302, $c->postWithToken('/text/removelanguage', ['code' => 'fr'])->status);
        $this->assertFileDoesNotExist($this->root . '/site-text/fr.php');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM site_text')['c'], 'its text went with it');
    }

    public function test_the_default_language_cannot_be_removed(): void
    {
        $this->db->query("INSERT INTO site_languages (code, name, dir) VALUES ('fr', 'Francais', 'ltr')");
        $c = $this->client($this->adminId, ['ui_lang' => 'fr']);
        $this->assertSame(422, $c->postWithToken('/text/removelanguage', ['code' => 'fr'])->status);
    }

    public function test_export_is_a_json_download_of_every_string(): void
    {
        $c = $this->client($this->adminId);
        $c->postWithToken('/text/save', ['lang' => 'en', 'key' => 'nav.login', 'value' => 'Sign in']);
        $res = $c->get('/text/export', ['lang' => 'en']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('application/json', $res->headers['Content-Type'] ?? '');
        $this->assertStringContainsString('attachment; filename="kiption-text-en.json"', $res->headers['Content-Disposition'] ?? '');
        $data = json_decode($res->body, true);
        $this->assertSame('Sign in', $data['nav.login']);
        $this->assertSame('Browse', $data['nav.browse']);
        $this->assertArrayNotHasKey('_dir', $data);
        $this->assertSame(404, $c->get('/text/export', ['lang' => '../etc'])->status);
    }

    public function test_import_applies_a_valid_file(): void
    {
        $c = $this->client($this->adminId);
        $res = $c->postWithFile('/text/import', ['lang' => 'en'], 'file', $this->upload(json_encode(['nav.login' => 'Sign in', 'nav.browse' => 'Look'])));
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM site_text')['c']);
    }

    public function test_import_rejects_bad_files_with_422_and_stores_nothing(): void
    {
        $c = $this->client($this->adminId);
        $cases = [
            'not json' => 'hello',
            'a list' => json_encode(['a', 'b']),
            'an empty object' => '{}',
            'a non-string value' => json_encode(['nav.login' => 5]),
            'a nested value' => json_encode(['nav.login' => ['x']]),
            'unsafe text' => json_encode(['nav.browse' => 'ok', 'nav.login' => '<b>x</b>']),
            'unknown key' => json_encode(['no.such' => 'x']),
        ];
        foreach ($cases as $label => $body) {
            $this->assertSame(422, $c->postWithFile('/text/import', ['lang' => 'en'], 'file', $this->upload($body))->status, $label);
        }
        $many = [];
        for ($i = 0; $i < 5001; $i++) { $many['k' . $i] = 'x'; }
        $this->assertSame(422, $c->postWithFile('/text/import', ['lang' => 'en'], 'file', $this->upload(json_encode($many)))->status, 'too many entries');
        $this->assertSame(422, $c->postWithFile('/text/import', ['lang' => 'en'], 'file', $this->upload('{"a":"b"}', 1048577))->status, 'declared size over 1 MB');
        $big = json_encode(['nav.login' => str_repeat('a', 1100000)]);
        $this->assertSame(422, $c->postWithFile('/text/import', ['lang' => 'en'], 'file', $this->upload($big))->status, 'real size over 1 MB');
        $this->assertSame(422, $c->postWithToken('/text/import', ['lang' => 'en'])->status, 'no file');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM site_text')['c']);
    }

    public function test_the_menu_shows_the_link_to_admins_only(): void
    {
        $this->assertStringContainsString('href="/text"', $this->client($this->adminId)->get('/manage')->body);
        $this->assertStringNotContainsString('href="/text"', $this->client($this->memberId)->get('/')->body);
        $this->assertStringNotContainsString('href="/text"', $this->client()->get('/')->body);
    }

    public function test_an_import_checks_the_language_once_not_once_per_string(): void
    {
        $keys = array_slice(array_keys(array_filter(Lang::base(), fn($v, $k) => $k[0] !== '_' && strpbrk((string) $v, "{<>\"\r\n") === false, ARRAY_FILTER_USE_BOTH)), 0, 40);
        $map = array_fill_keys($keys, 'Imported text');
        $lookups = 0;
        $this->db->onQuery(function (string $sql) use (&$lookups): void {
            if (stripos($sql, 'FROM site_languages') !== false) $lookups++;
        });
        $res = (new \App\SiteText\Store($this->db))->import('en', $map);
        $this->assertNull($res['error']);
        $this->assertSame(40, $res['saved']);
        $this->assertLessThanOrEqual(1, $lookups, "import looked up the language list {$lookups} times");
    }
}
