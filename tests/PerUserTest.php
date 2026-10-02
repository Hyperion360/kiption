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
// cookies only. Since the Kip 0.5 sync, TestClient keeps a real cookie jar:
// every Set-Cookie leaf (list or scalar) is absorbed in order and rides the
// same client's next request the way a browser replays it, so the jar cases
// below assert carriage, not just emission; the sync cases assert the
// Set-Cookie list shape on the response itself.
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
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
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
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
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
        $this->assertContains('lang=xx; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax',
            $this->cookieLeaves($res), 'login syncs the cookie from the pref as one list leaf');
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
        // The exact two-leaf list: Response::send() emits each leaf with
        // append semantics, so both cookies die beside the session cookie.
        $this->assertSame([
            'lang=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',
            'theme=; Max-Age=0; Path=/; SameSite=Lax',
        ], $res->headers['Set-Cookie'], 'logout clears the synced cookies as one list');
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

    /** The Set-Cookie leaves for a response, scalar or list: the multi-cookie
     *  sites (login, logout, prefs save) ride one map entry as a list, the
     *  single-cookie toggle sites stay scalar; this normalizes both. */
    private function cookieLeaves(\Kip\Http\Response $res): array
    {
        $setCookie = $res->headers['Set-Cookie'] ?? [];
        return is_array($setCookie) ? $setCookie : [$setCookie];
    }

    public function test_prefs_save_and_toggle_write_both_the_pref_and_the_cookie(): void
    {
        $me = $this->client($this->memberId());
        $res = $me->postWithToken('/account/prefs',
            ['theme' => 'sepia', 'lang' => '', 'bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '1',
             'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $db = $this->db();
        $this->assertSame('sepia', $db->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the prefs save stores the cross-device record');
        // The exact three-leaf list, one leaf per directive, in emission order.
        $this->assertSame([
            'toc=1; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax',
            'lang=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',
            'theme=sepia; Max-Age=31536000; Path=/; SameSite=Lax',
        ], $res->headers['Set-Cookie'], 'the save syncs every runtime cookie (ruling 5: every write point writes both)');
        // The Kip 0.5 jar: both set cookies ride the SAME client's next
        // requests, the way a browser replays them. The toc leaf redirects a
        // bare chapter read; the theme leaf renders the themed page.
        $redirect = $me->get('/story/read/the-rabbit-hole');
        $this->assertSame(302, $redirect->status, 'the toc cookie carried onto the next request');
        $this->assertSame('/story/view/the-rabbit-hole', $redirect->headers['Location'] ?? '');
        $this->assertStringContainsString('data-theme="sepia"', $me->get('/browse')->body,
            'the theme cookie carried onto the next request (cookie > OS, ruling 5)');
        $this->assertStringNotContainsString('data-theme=', $this->client()->get('/browse')->body,
            'a cookieless render stays OS-default (the byte-identity pin)');
        // The /reader/settings save while logged in ALSO writes the pref (one
        // source of truth); the cookies keep riding the same response. The
        // legacy /theme/{light,dark} routes were retired with the C6 footer
        // (their links were the only entry points); ReaderSettingsTest pins
        // the full leaf list, the CSRF shapes, and the fallback matrix.
        $toggle = $me->postWithToken('/reader/settings', ['theme' => 'night', 'return_to' => '/browse']);
        $this->assertSame(302, $toggle->status, $toggle->body);
        $this->assertSame('night', $db->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the member settings save persists beside its cookie');
        $this->assertStringContainsString('theme=night', implode(';', $this->cookieLeaves($toggle)));
        // A prefs-less member saving gets their row created, not a no-op
        // (the upsert doctrine, plan review finding 5).
        $db->query('DELETE FROM user_prefs WHERE user_id = ?', [$this->memberId()]);
        $this->assertSame(302, $me->postWithToken('/reader/settings', ['theme' => 'paper', 'return_to' => '/browse'])->status);
        $this->assertSame('paper', $db->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the settings save upserts the row for prefs-less members');
        // Guests: the cookie path unchanged (cookie-only, no row).
        $guest = $this->client();
        $saved = $guest->post('/reader/settings', ['theme' => 'night', 'return_to' => '/browse']);
        $this->assertSame(302, $saved->status, $saved->body);
        $this->assertStringContainsString('theme=night', implode(';', $this->cookieLeaves($saved)));
        $this->assertStringContainsString('data-theme="night"', $guest->get('/browse')->body,
            'the guest cookie carries onto the next request and themes it');
    }

    public function test_login_syncs_the_theme_cookie_from_the_pref(): void
    {
        // The seeded member carries no prefs row (see above), so the pref is
        // planted the way a real save would, not UPDATEd into nothing.
        $this->db()->query('INSERT INTO user_prefs (user_id, theme) VALUES (?, ?)', [$this->memberId(), 'night']);
        $client = $this->client();
        $res = $client->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status);
        $this->assertContains('theme=night; Max-Age=31536000; Path=/; SameSite=Lax',
            $this->cookieLeaves($res), 'login re-syncs a stale theme cookie from the pref');
        // The jar carries the leaf onto the same client's next request: the
        // render after login is themed, the browser replay made real.
        $this->assertStringContainsString('data-theme="night"', $client->get('/browse')->body,
            'the synced cookie rides the next request and themes it');
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
        $form = $me->get('/account/settings')->body;
        $this->assertStringContainsString('name="lang"', $form);
        $this->assertStringContainsString('name="theme"', $form);
        $this->assertStringContainsString('<option value="en"', $form, 'the select options come from Lang::installed()');
        \App\Features::toggle('peruserlang', false);
        $this->assertStringNotContainsString('name="lang"', $me->get('/account/settings')->body);
        \App\Features::toggle('peruserlang', true);
        \App\Features::toggle('perusertheme', false);
        $this->assertStringNotContainsString('name="theme"', $me->get('/account/settings')->body);
        \App\Features::toggle('perusertheme', true);
    }

    /** Ruling 4's verification pin (Task 3): no shipped stylesheet carries a
     *  physical directional property outside comments, so dir="rtl" flips the
     *  layout natively (flex order reverses, auto margins mirror through their
     *  logical spellings, text-align: center is direction-neutral). The pin
     *  covers every stylesheet under public/assets (style.css at public/ root
     *  was the pre-redesign orphan, deleted with the C6 shell): reader.css is
     *  the file the layout links, and print.css rides the whole-work print
     *  view. A future change that reintroduces margin-left or friends breaks
     *  this test before it ships a half-mirrored RTL page. */
    public function test_stylesheets_carry_no_physical_directional_properties(): void
    {
        $files = [
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
        /* The reader progressbar fill is the one place CSS has no logical
         * spelling: a sized background image anchors physically. The base
         * rule anchors the fill at the left; the dir=rtl override re-anchors
         * it at the right, so the fill grows from the inline-start edge in
         * either direction. Pin the pair: losing the rtl rule would leave a
         * left-growing bar on a right-reading page. */
        $reader = (string) preg_replace('#/\*.*?\*/#s', '',
            (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css'));
        $this->assertStringContainsString(') left center /', $reader,
            'the progress fill anchors at the physical left by default');
        $this->assertStringContainsString('html[dir="rtl"] .reader-progress::after', $reader,
            'dir=rtl re-anchors the progress fill');
        $this->assertStringContainsString(') right center /', $reader,
            'the rtl override anchors the fill at the mirrored inline-start edge');
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
    /** The reported bug (2026-10-02): logging in switched the site to dark.
     *  A prefs row created for any other reason used to carry the schema's
     *  default theme (001: dark, night after 026) and the login sync applied
     *  it. Since 029 such a row holds NULL and the login sets no theme cookie. */
    public function test_login_never_applies_a_theme_the_member_did_not_choose(): void
    {
        $this->db()->query('INSERT INTO user_prefs (user_id, lang) VALUES (?, ?)', [$this->memberId(), '']);
        $res = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status);
        foreach ($this->cookieLeaves($res) as $leaf) {
            $this->assertStringStartsNotWith('theme=', $leaf, 'no theme cookie for a member who never chose one');
        }
    }

    public function test_peruserlang_off_stops_the_login_sync_and_keeps_cookieless_renders_archive_lang(): void
    {
        $this->writePack();
        // Plant a row with a real theme choice beside the lang: the login still
        // syncs the theme cookie, proving the prefs row was read and only the
        // lang arm skipped. (A lang-only row carries NULL theme since 029.)
        $this->db()->query('INSERT INTO user_prefs (user_id, lang, theme) VALUES (?, ?, ?)', [$this->memberId(), 'xx', 'paper']);
        \App\Features::toggle('peruserlang', false);
        $res = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status);
        $this->assertNotContains('lang=xx; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax',
            $this->cookieLeaves($res), 'the login sync skips the lang cookie');
        $this->assertContains('theme=paper; Max-Age=31536000; Path=/; SameSite=Lax',
            $this->cookieLeaves($res), 'the theme arm still syncs: the row was read, only lang skipped');
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
        $this->assertContains('lang=xx; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax',
            $this->cookieLeaves($res), 'the sync restores with the flag');
    }

    /** The perusertheme off case: the same write-side semantics (no sync at
     *  the login), the form field group hides (each flag hides its own group;
     *  with both off neither renders), and the /theme toggle keeps writing
     *  its cookie because the cookie path is unflaggable guest core that
     *  predates the flag; only the member pref write-through skips. */
    public function test_perusertheme_off_skips_the_sync_but_the_toggle_keeps_its_cookie(): void
    {
        $this->db()->query('INSERT INTO user_prefs (user_id, theme) VALUES (?, ?)', [$this->memberId(), 'paper']);
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
        $form = $me->get('/account/settings')->body;
        $this->assertStringNotContainsString('name="theme"', $form);
        $this->assertStringContainsString('name="lang"', $form, 'the lang group is gated by peruserlang, not this flag');
        \App\Features::toggle('peruserlang', false);
        $this->assertStringNotContainsString('name="lang"', $me->get('/account/settings')->body, 'both field groups hide with both flags off');
        \App\Features::toggle('peruserlang', true);
        // (c) The settings save still writes its cookie (the unflaggable guest
        // core path); the member write-through alone skips.
        $toggle = $me->postWithToken('/reader/settings', ['theme' => 'night']);
        $this->assertSame(302, $toggle->status, $toggle->body);
        $this->assertStringContainsString('theme=night', implode(';', $this->cookieLeaves($toggle)), 'the settings cookie keeps riding');
        $this->assertSame('paper', $this->db()->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the write-through skips while the flag is off');
        // On recovery: the write-through and the login sync return.
        \App\Features::toggle('perusertheme', true);
        $this->assertSame(302, $me->postWithToken('/reader/settings', ['theme' => 'night'])->status);
        $this->assertSame('night', $this->db()->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'the member write-through persists again');
        $res = $this->client()->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $this->assertContains('theme=night; Max-Age=31536000; Path=/; SameSite=Lax',
            $this->cookieLeaves($res), 'the login sync restores with the flag');
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
            ['theme' => 'night', 'lang' => 'en', 'bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
             'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('en', $this->db()->one('SELECT lang FROM user_prefs WHERE user_id = ?', [$this->memberId()])['lang'],
            'a real pack choice stores in the row');
        $this->assertContains('lang=en; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax', $this->cookieLeaves($res),
            'the save syncs a lasting cookie for a non-empty lang choice');
        $this->assertSame(422, $me->postWithToken('/account/prefs',
            ['theme' => 'hotdog', 'lang' => '', 'bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
             'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => ''])->status,
            'junk theme rejects on its own check even with a valid lang');
        $this->assertSame('en', $this->db()->one('SELECT lang FROM user_prefs WHERE user_id = ?', [$this->memberId()])['lang'],
            'the 422 writes nothing');
    }
}
