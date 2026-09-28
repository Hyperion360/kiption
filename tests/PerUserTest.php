<?php // tests/PerUserTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
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

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-pu-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
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
}
