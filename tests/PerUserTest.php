<?php // tests/PerUserTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// M4 final: the per-user language (with pack-declared RTL) and the per-user
// theme share the cookie-sync seam (phase 12d ruling 1): the prefs row is the
// source of truth, the 'lang'/'theme' cookies are the runtime cache, and the
// login/prefs/toggle write points sync both while the render path reads
// cookies only. TestClient keeps no cookie jar (PrefsTest finding 2), so the
// render cases apply the cookie's value the way public/index.php does; the
// sync cases assert the Set-Cookie contract on the response itself.
final class PerUserTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private string $packFile = '';
    private Database $db;
    private int $memberRowId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-pu-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-pu-mail-') . '.log';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->memberRowId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        // The public/index.php init, pointed at this throwaway DB.
        \App\Features::init($this->db, []);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        \App\Lang::setCurrent('en'); // the LangTest discipline: no pack state leaks into later suites
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
        @unlink($this->packFile);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function memberId(): int
    {
        return $this->memberRowId;
    }

    private function app(): App
    {
        return new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-pu-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient($this->app());
        return $as === null ? $client : $client->actingAs($as);
    }

    /** The test-only RTL pack (ruling 3: no RTL pack ships; the mechanism is
     *  the deliverable). Rides addPackPath, the third-party seam. */
    private function writePack(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kiption-pu-pack-');
        $this->packFile = $tmp . '.php';
        file_put_contents($this->packFile,
            "<?php return ['_dir' => 'rtl', 'nav.browse' => 'Explorar', 'nav.recent' => 'Recientes'];");
        unlink($tmp);
        \App\Lang::addPackPath('xx', $this->packFile);
    }

    public function test_member_renders_in_their_language_and_guests_do_not(): void
    {
        $this->writePack();
        $db = $this->db();
        // The seeded member has no prefs row (the seeder only registers users
        // through register() with one); the login SELECT must skip missing
        // rows silently, so the test plants the row the way a real save would.
        $db->query('INSERT INTO user_prefs (user_id, lang) VALUES (?, ?)', [$this->memberId(), 'xx']);
        $member = $this->client($this->memberId());
        // The render reads the cookie, and the test plays the index.php seam's
        // part: setCurrent from the cookie value the login sync (pinned below)
        // put in the browser.
        \App\Lang::setCurrent('xx');
        $body = $member->get('/browse')->body;
        $this->assertStringContainsString('Explorar', $body, 'the member renders in their pack');
        $this->assertStringContainsString('dir="rtl"', $body, 'the RTL pack flips the document direction');
        $this->assertStringContainsString('lang="xx"', $body);
        // byte-identity: the cookieless render keeps the config default, no dir
        \App\Lang::setCurrent('en');
        $guest = $this->client()->get('/browse')->body;
        $this->assertStringContainsString('Browse', $guest);
        $this->assertStringNotContainsString('dir="rtl"', $guest);
        $this->assertStringNotContainsString('Explorar', $guest);
        // an empty pref = the archive default (no cookie lands, en resumes)
        $db->query('UPDATE user_prefs SET lang = ? WHERE user_id = ?', ['', $this->memberId()]);
        $this->assertStringContainsString('Browse', $member->get('/browse')->body);
        // a bogus stored value degrades to en strings, never a 500 (the empty
        // pack doctrine: a missing file is pure en fallback)
        $db->query('UPDATE user_prefs SET lang = ? WHERE user_id = ?', ['zz', $this->memberId()]);
        \App\Lang::setCurrent('zz');
        $bogus = $member->get('/browse')->body;
        $this->assertStringContainsString('Browse', $bogus, 'an unknown pack code falls back to en strings');
        // LOGIN SYNC: the successful attempt response carries the lang cookie from the pref
        \App\Lang::setCurrent('en');
        $db->query('UPDATE user_prefs SET lang = ? WHERE user_id = ?', ['xx', $this->memberId()]);
        $res = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status);
        $setCookie = $res->headers['Set-Cookie'] ?? '';
        $cookieLine = is_array($setCookie) ? implode(';', $setCookie) : $setCookie;
        $this->assertStringContainsString('lang=xx', $cookieLine, 'login syncs the cookie from the pref');
        $this->assertStringContainsString('Max-Age=31536000', $cookieLine, 'the lang cookie lasts like the theme cookie');
        // a prefs-less member logs in with no cookie directives at all
        $db->query('DELETE FROM user_prefs WHERE user_id = ?', [$this->memberId()]);
        $bare = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertSame(302, $bare->status);
        $this->assertArrayNotHasKey('Set-Cookie', $bare->headers, 'no prefs row, no cookie sync');
    }

    public function test_logout_clears_the_synced_cookies(): void
    {
        $res = $this->client($this->memberId())->postWithToken('/auth/logout');
        $this->assertSame(302, $res->status);
        $setCookie = $res->headers['Set-Cookie'] ?? '';
        $cookieLine = is_array($setCookie) ? implode(';', $setCookie) : $setCookie;
        $this->assertStringContainsString('lang=; Max-Age=0', $cookieLine, 'logout clears the lang cookie');
        $this->assertStringContainsString('theme=; Max-Age=0', $cookieLine, 'logout clears the theme cookie');
    }

    public function test_lang_dir_and_installed(): void
    {
        \App\Lang::setCurrent('en');
        $this->assertNull(\App\Lang::dir(), 'en declares no direction');
        $this->writePack();
        \App\Lang::setCurrent('xx');
        $this->assertSame('rtl', \App\Lang::dir());
        $this->assertContains('en', \App\Lang::installed(), 'installed lists the app/lang packs');
        $this->assertNotContains('xx', \App\Lang::installed(), 'addPackPath registrations are not app/lang files');
    }

    /** The Set-Cookie line for a response, scalar or list (the Task-1 seam:
     *  multiple cookies ride one map entry as a list). */
    private function cookieLine(\Kip\Http\Response $res): string
    {
        $setCookie = $res->headers['Set-Cookie'] ?? '';
        return is_array($setCookie) ? implode(';', $setCookie) : $setCookie;
    }

    public function test_prefs_save_and_toggle_write_both_the_pref_and_the_cookie(): void
    {
        $me = $this->client($this->memberId());
        $res = $me->postWithToken('/account/prefs',
            ['theme' => 'light', 'lang' => '', 'bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
             'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $db = $this->db();
        $this->assertSame('light', $db->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the prefs save stores the cross-device record');
        $this->assertStringContainsString('theme=light', $this->cookieLine($res),
            'the save syncs the runtime cookie too (ruling 5: every write point writes both)');
        // The render is cookie > OS (ruling 5): TestClient keeps no cookie jar,
        // so the request plays the browser's part and carries the cookie the
        // save just synced (the ThemeTest Request idiom).
        $body = $this->app()->handle(new Request('GET', '/browse', [], [], ['theme' => 'light']))->body;
        $this->assertStringContainsString('data-theme="light"', $body, 'a render carrying the synced cookie is light');
        $this->assertStringNotContainsString('data-theme="light"', $this->client()->get('/browse')->body,
            'a cookieless render stays OS-default (the byte-identity pin)');
        // The /theme toggle while logged in ALSO writes the pref (one source of
        // truth); the guest cookie keeps riding the same response.
        $toggle = $me->get('/theme/dark', ['return_to' => '/browse']);
        $this->assertSame(302, $toggle->status);
        $this->assertSame('dark', $db->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the member toggle persists beside its cookie');
        $this->assertStringContainsString('theme=dark', $this->cookieLine($toggle));
        // A prefs-less member toggling gets their row created, not a no-op
        // (the upsert doctrine, plan review finding 5).
        $db->query('DELETE FROM user_prefs WHERE user_id = ?', [$this->memberId()]);
        $this->assertSame(302, $me->get('/theme/light', ['return_to' => '/browse'])->status);
        $this->assertSame('light', $db->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the toggle upserts the row for prefs-less members');
        // Guests: the cookie path unchanged (the pre-existing ThemeTest pins it
        // unedited; the guest response keeps the scalar Set-Cookie shape).
        $guest = $this->app()->handle(new Request('GET', '/theme/dark', ['return_to' => '/browse'], [], []));
        $this->assertSame(302, $guest->status);
        $this->assertStringContainsString('theme=dark', $this->cookieLine($guest));
    }

    public function test_login_syncs_the_theme_cookie_from_the_pref(): void
    {
        // The seeded member carries no prefs row (see above), so the pref is
        // planted the way a real save would, not UPDATEd into nothing.
        $this->db()->query('INSERT INTO user_prefs (user_id, theme) VALUES (?, ?)', [$this->memberId(), 'dark']);
        $res = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('theme=dark', $this->cookieLine($res), 'login re-syncs a stale theme cookie from the pref');
    }

    public function test_prefs_form_validates_and_flags_off_hide_fields(): void
    {
        $me = $this->client($this->memberId());
        $this->assertSame(422, $me->postWithToken('/account/prefs',
            ['theme' => 'hotdog', 'lang' => 'zz', 'bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
             'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => ''])->status,
            'junk theme and unregistered lang both reject');
        $this->assertNull($this->db()->one('SELECT * FROM user_prefs WHERE user_id = ?', [$this->memberId()]),
            'the 422 fires before the upsert (the schema CHECK is only the backstop)');
        // The form lists installed packs and the theme radios; off hides both field groups
        $form = $me->get('/account')->body;
        $this->assertStringContainsString('name="lang"', $form);
        $this->assertStringContainsString('name="theme"', $form);
        $this->assertStringContainsString('<option value="en"', $form, 'the select options come from Lang::installed()');
        \App\Features::toggle('peruserlang', false);
        $this->assertStringNotContainsString('name="lang"', $me->get('/account')->body);
        \App\Features::toggle('peruserlang', true);
        \App\Features::toggle('perusertheme', false);
        $this->assertStringNotContainsString('name="theme"', $me->get('/account')->body);
        \App\Features::toggle('perusertheme', true);
    }
}
