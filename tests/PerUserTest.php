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

    /** Ruling 4's verification pin (Task 3): no shipped stylesheet carries a
     *  physical directional property outside comments, so dir="rtl" flips the
     *  layout natively (flex order reverses, auto margins mirror through their
     *  logical spellings, text-align: center is direction-neutral). The pin
     *  covers every stylesheet under public/, not just the one the ruling's
     *  probe read: reader.css is the file the layout links (the probe's
     *  style.css is not referenced by any view), and print.css rides the
     *  whole-work print view. A future change that reintroduces margin-left
     *  or friends breaks this test before it ships a half-mirrored RTL page. */
    public function test_stylesheets_carry_no_physical_directional_properties(): void
    {
        $files = [
            'public/style.css' => dirname(__DIR__) . '/public/style.css',
            'public/assets/reader.css' => dirname(__DIR__) . '/public/assets/reader.css',
            'public/assets/print.css' => dirname(__DIR__) . '/public/assets/print.css',
        ];
        foreach ($files as $label => $file) {
            $this->assertFileExists($file);
            // Comments are stripped first: prose mentioning a property is
            // fine, declarations are not.
            $code = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression(
                '/margin-(left|right)|padding-(left|right)|border-(left|right)/i',
                $code,
                $label . ': physical box directional properties break the RTL mirror; use margin-inline-start and friends'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/text-align\s*:\s*(left|right)/i',
                $code,
                $label . ': physical text alignment breaks the RTL mirror; use text-align: start'
            );
        }
    }

    /** The peruserlang off case (Task 3, the seam ruling folded into ruling
     *  1): the off semantics are WRITE-SIDE plus cookieless archive-lang, not
     *  a render-side purge. The stale-cookie window is documented, never
     *  asserted here: the index.php seam consults only the config-shipped
     *  flag default (moving it below Features::init would put the flags DB
     *  in front of the maintenance 503, a framework-level page that must
     *  never open it), so a browser already carrying lang=xx keeps rendering
     *  that pack until its next logout or login; new logins stop syncing
     *  the cookie the moment the flag drops, which is what this case pins. */
    public function test_peruserlang_off_stops_the_login_sync_and_keeps_cookieless_renders_archive_lang(): void
    {
        $this->writePack();
        // Plant the row the way a real save would. The lang-only INSERT lets
        // the theme column take its schema default 'dark', which sharpens the
        // case: the login still syncs the theme cookie, proving the prefs row
        // was read and only the lang arm skipped.
        $this->db()->query('INSERT INTO user_prefs (user_id, lang) VALUES (?, ?)', [$this->memberId(), 'xx']);
        \App\Features::toggle('peruserlang', false);
        $res = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status);
        $this->assertStringNotContainsString('lang=', $this->cookieLine($res), 'the login sync skips the lang cookie');
        $this->assertStringContainsString('theme=dark', $this->cookieLine($res), 'the theme arm still syncs: the row was read, only lang skipped');
        // The cookieless render keeps the archive language, and the stored
        // pref is inert data, never deleted (the toggle-on restore contract).
        \App\Lang::setCurrent('en');
        $this->assertStringContainsString('lang="en"', $this->client()->get('/browse')->body);
        $this->assertStringNotContainsString('dir="rtl"', $this->client()->get('/browse')->body);
        $this->assertSame('xx', $this->db()->one('SELECT lang FROM user_prefs WHERE user_id = ?', [$this->memberId()])['lang'],
            'flag-off stores the pref, never deletes it');
        // On recovery the sync returns.
        \App\Features::toggle('peruserlang', true);
        $res = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('lang=xx', $this->cookieLine($res), 'the sync restores with the flag');
    }

    /** The perusertheme off case: the same write-side semantics (no sync at
     *  the login), the form field group hides (each flag hides its own group;
     *  with both off neither renders), and the /theme toggle keeps writing
     *  its cookie because the cookie path is unflaggable guest core that
     *  predates the flag; only the member pref write-through skips. */
    public function test_perusertheme_off_skips_the_sync_but_the_toggle_keeps_its_cookie(): void
    {
        $this->db()->query('INSERT INTO user_prefs (user_id, theme) VALUES (?, ?)', [$this->memberId(), 'light']);
        \App\Features::toggle('perusertheme', false);
        $me = $this->client($this->memberId());
        // (a) The login carries no theme sync. The row's lang column defaults
        //  to '' (no lang directive either), so the response is the plain
        //  redirect with no cookie directives at all.
        $res = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status);
        $this->assertArrayNotHasKey('Set-Cookie', $res->headers, 'no prefs sync at all while the flag is off');
        // (b) The form hides the theme group while the lang group stays (its
        //  own flag is still on); with both flags off both groups hide.
        $form = $me->get('/account')->body;
        $this->assertStringNotContainsString('name="theme"', $form);
        $this->assertStringContainsString('name="lang"', $form, 'the lang group is gated by peruserlang, not this flag');
        \App\Features::toggle('peruserlang', false);
        $this->assertStringNotContainsString('name="lang"', $me->get('/account')->body, 'both field groups hide with both flags off');
        \App\Features::toggle('peruserlang', true);
        // (c) The toggle still writes its cookie (the unflaggable guest core
        //  path); the member write-through alone skips.
        $toggle = $me->get('/theme/dark', ['return_to' => '/browse']);
        $this->assertSame(302, $toggle->status);
        $this->assertStringContainsString('theme=dark', $this->cookieLine($toggle), 'the toggle cookie keeps riding');
        $this->assertSame('light', $this->db()->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the write-through skips while the flag is off');
        // On recovery: the write-through and the login sync return.
        \App\Features::toggle('perusertheme', true);
        $this->assertSame(302, $me->get('/theme/dark', ['return_to' => '/browse'])->status);
        $this->assertSame('dark', $this->db()->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the member toggle persists again');
        $res = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertStringContainsString('theme=dark', $this->cookieLine($res), 'the login sync restores with the flag');
    }

    /** The seam's flag consult (the Task-2 ruling): the index.php lang-cookie
     *  seam runs before Features::init (moving it below would put the flags DB
     *  in front of the maintenance 503, a framework-level page that must never
     *  open it), so the only flag it can consult is the config-shipped
     *  peruserlang default. The suite cannot execute the front controller, so
     *  this pins the guard textually (the CSS pin's source-probe idiom):
     *  without the consult, a config-off archive still honors a member's
     *  cookie, behavior the README does not ship ("it consults only the
     *  config-shipped default"; the stale-cookie window is the RUNTIME
     *  flag-off case, kept live in the off test above). */
    public function test_the_index_seam_consults_the_config_peruserlang_default(): void
    {
        $src = (string) preg_replace('#/\*.*?\*/#s', '',
            (string) file_get_contents(dirname(__DIR__) . '/public/index.php'));
        $start = strpos($src, 'setCurrent($cookieLang)');
        $this->assertNotFalse($start, 'the seam must keep applying the lang cookie');
        $guard = (string) substr($src, max(0, $start - 500), 500);
        $this->assertStringContainsString(
            "\$config['features']['peruserlang']",
            $guard,
            'the seam applies the lang cookie only when the config peruserlang default is on');
    }

    /** The save-side sync's primary arm and the theme reject standing alone:
     *  the combined-junk case above dies on the lang check first, so neither
     *  the non-empty lang cookie nor the junk-theme 422 ever executes there. */
    public function test_prefs_save_syncs_a_nonempty_lang_and_junk_theme_alone_rejects(): void
    {
        $me = $this->client($this->memberId());
        $res = $me->postWithToken('/account/prefs',
            ['theme' => 'dark', 'lang' => 'en', 'bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
             'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('en', $this->db()->one('SELECT lang FROM user_prefs WHERE user_id = ?', [$this->memberId()])['lang'],
            'a real pack choice stores in the row');
        $this->assertStringContainsString('lang=en; Max-Age=31536000', $this->cookieLine($res),
            'the save syncs a lasting cookie for a non-empty lang choice');
        $this->assertSame(422, $me->postWithToken('/account/prefs',
            ['theme' => 'hotdog', 'lang' => '', 'bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
             'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => ''])->status,
            'junk theme rejects on its own check even with a valid lang');
        $this->assertSame('en', $this->db()->one('SELECT lang FROM user_prefs WHERE user_id = ?', [$this->memberId()])['lang'],
            'the 422 writes nothing');
    }
}
