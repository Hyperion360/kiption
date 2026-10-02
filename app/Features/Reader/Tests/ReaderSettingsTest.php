<?php // app/Features/Reader/Tests/ReaderSettingsTest.php
namespace App\Features\Reader\Tests;
use App\Features\Reader\Prefs;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// C2: the reader settings slice. POST /reader/settings writes two cookies
// (theme + reader typography), both built only from whitelisted enums/ints;
// members get the theme write-through to user_prefs (auto stores paper, the
// column CHECK admits paper/sepia/night only). Cache-bypass economy: auto and
// all-default typography CLEAR their cookies instead of minting year-long
// ones, because a cookie-bearing request never hits the static cache.
final class ReaderSettingsTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberRowId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rs-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->memberRowId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        \App\Features::init($this->db, []);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function app(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-rs-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient($this->app());
        return $as === null ? $client : $client->actingAs($as);
    }

    /** @return list<string> the Set-Cookie leaves, scalar or list normalized */
    private function cookieLeaves(Response $res): array
    {
        $setCookie = $res->headers['Set-Cookie'] ?? [];
        return is_array($setCookie) ? $setCookie : [$setCookie];
    }

    private function memberTheme(): ?string
    {
        $row = $this->db->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberRowId]);
        return $row === null ? '(no row)' : $row['theme'];
    }

    public function test_guest_settings_sets_both_cookies_and_redirects(): void
    {
        $res = $this->client()->post('/reader/settings',
            ['theme' => 'sepia', 'size' => '21', 'typeface' => 'serif', 'spacing' => 'regular',
             'paragraphs' => 'indented', 'width' => 'medium', 'mode' => 'scroll', 'return_to' => '/browse']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('/browse', $res->headers['Location']);
        $this->assertSame([
            'theme=sepia; Max-Age=31536000; Path=/; SameSite=Lax',
            'reader=21-serif-regular-indented-medium-scroll; Max-Age=31536000; Path=/; SameSite=Lax',
        ], $this->cookieLeaves($res), 'one leaf per cookie, theme first, no HttpOnly (Cookie::pref: the enhancement layer rewrites both from document.cookie)');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM user_prefs')['c'],
            'guests stay cookie-only: no row is written');
    }

    public function test_garbage_theme_falls_back_to_auto_and_clears_the_theme_cookie(): void
    {
        $res = $this->client()->post('/reader/settings', ['theme' => 'hotdog']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location'], 'no return_to falls back to the root');
        $this->assertSame([
            'theme=; Max-Age=0; Path=/; SameSite=Lax',
            'reader=; Max-Age=0; Path=/; SameSite=Lax',
        ], $this->cookieLeaves($res), 'auto + all-default typography clear both cookies (cache-bypass economy)');
    }

    public function test_garbage_size_falls_back_to_19(): void
    {
        $res = $this->client()->post('/reader/settings',
            ['theme' => 'paper', 'size' => '99', 'mode' => 'pages']);
        $this->assertSame(302, $res->status);
        $leaves = implode(';', $this->cookieLeaves($res));
        $this->assertStringContainsString('reader=19-serif-regular-indented-medium-pages;', $leaves,
            'a junk size falls back to the 19px default while the valid mode rides through');
    }

    public function test_all_default_typography_clears_the_reader_cookie(): void
    {
        $res = $this->client()->post('/reader/settings',
            ['theme' => 'night', 'size' => '19', 'typeface' => 'serif', 'spacing' => 'regular',
             'paragraphs' => 'indented', 'width' => 'medium', 'mode' => 'scroll']);
        $this->assertSame(302, $res->status);
        $this->assertSame([
            'theme=night; Max-Age=31536000; Path=/; SameSite=Lax',
            'reader=; Max-Age=0; Path=/; SameSite=Lax',
        ], $this->cookieLeaves($res), 'saving the defaults clears the reader cookie, it does not mint one');
    }

    public function test_member_persists_theme_and_auto_stores_paper(): void
    {
        $me = $this->client($this->memberRowId);
        $res = $me->postWithToken('/reader/settings', ['theme' => 'sepia']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('sepia', $this->memberTheme(), 'the member write-through persists the row');
        // auto stores NULL (029) and clears the cookie: OS-default is the
        // absence of a cookie, and the login sync sets none for NULL.
        $res = $me->postWithToken('/reader/settings', ['theme' => 'auto']);
        $this->assertSame(302, $res->status);
        $this->assertNull($this->memberTheme());
        $this->assertContains('theme=; Max-Age=0; Path=/; SameSite=Lax', $this->cookieLeaves($res));
    }

    /** perusertheme off: the member's choice still lands in the cookie, but
     *  the user_prefs row is never written (testing specialist). */
    public function test_perusertheme_off_skips_the_member_row(): void
    {
        $this->db->query("INSERT OR REPLACE INTO feature_flags (key, enabled) VALUES ('perusertheme', 0)");
        \App\Features::init($this->db, []);
        $res = $this->client($this->memberRowId)->postWithToken('/reader/settings', ['theme' => 'sepia']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertContains('theme=sepia; Max-Age=31536000; Path=/; SameSite=Lax', $this->cookieLeaves($res));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM user_prefs WHERE user_id = ?', [$this->memberRowId])['c'],
            'no write-through while the flag is off');
    }

    public function test_unsafe_return_to_falls_back_to_the_root(): void
    {
        $res = $this->client()->post('/reader/settings', ['theme' => 'sepia', 'return_to' => 'https://evil.test/x']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location'], 'open redirects die in safeReturn');
    }

    public function test_header_injection_attempt_stores_nothing_and_redirects_clean(): void
    {
        $res = $this->client()->post('/reader/settings', ['theme' => "night\r\nX-Evil: 1", 'return_to' => '/browse']);
        $this->assertSame(302, $res->status, 'the whitelist absorbs the payload as garbage (auto)');
        $this->assertSame('/browse', $res->headers['Location']);
        $this->assertNotContains('X-Evil: 1', $this->cookieLeaves($res));
        $this->assertArrayNotHasKey('X-Evil', $res->headers);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM user_prefs')['c']);
    }

    public function test_guest_tokenless_post_is_a_plain_redirect(): void
    {
        // The settings POST is deliberately ungated (the kudos shape): a
        // cookie-set is not state, and a headerless guest passes the kernel's
        // same-origin proof path exactly like the kudos tests pin.
        $res = $this->client()->post('/reader/settings', ['theme' => 'sepia']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringContainsString('theme=sepia', implode(';', $this->cookieLeaves($res)));
    }

    public function test_member_cross_site_tokenless_post_is_rejected(): void
    {
        // The kernel CSRF gate still covers session-bearing requests on this
        // ungated route: a forged cross-site POST carries an Origin the host
        // never sent, and dies 403 before the controller.
        $me = $this->client($this->memberRowId);
        $res = $me->post('/reader/settings', ['theme' => 'night'], ['origin' => 'https://evil.test']);
        $this->assertSame(403, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM user_prefs')['c'],
            'the rejected forge writes nothing');
        // the member's own tokened post works
        $this->assertSame(302, $me->postWithToken('/reader/settings', ['theme' => 'night'])->status);
        $this->assertSame('night', $this->memberTheme());
    }

    public function test_prefs_current_parses_defaults_and_garbage(): void
    {
        $this->assertSame('19-serif-regular-indented-medium-scroll', Prefs::current(null)->cookieValue(),
            'a null request (the pre-envelope layout) reads all defaults');
        $garbage = new Request('GET', '/', [], [], ['reader' => '99-xx-bogus-flipped-huge-flipbook']);
        $this->assertSame('19-serif-regular-indented-medium-scroll', Prefs::current($garbage)->cookieValue(),
            'every out-of-enum segment falls back field by field');
        $this->assertSame('19-serif-regular-indented-medium-scroll', Prefs::current(new Request('GET', '/', [], [], []))->cookieValue());
        $valid = new Request('GET', '/', [], [], ['reader' => '21-sans-airy-spaced-wide-pages']);
        $this->assertSame('21-sans-airy-spaced-wide-pages', Prefs::current($valid)->cookieValue());
        $this->assertSame(21, Prefs::current($valid)->size);
    }

    public function test_wrong_segment_count_and_array_cookie_read_as_all_defaults(): void
    {
        // PHP parses Cookie: reader[]=x into an array in $_COOKIE; substr_count
        // would TypeError on it. Any non-string and any wrong dash count is
        // "no cookie": all defaults, never a 500 (red-team finding).
        foreach (['21-serif', 'a-b-c-d-e-f-g', '19-serif-regular-indented-medium'] as $raw) {
            $this->assertSame('19-serif-regular-indented-medium-scroll',
                Prefs::current(new Request('GET', '/', [], [], ['reader' => $raw]))->cookieValue(),
                "{$raw} has the wrong segment count and reads as defaults");
        }
        $this->assertSame('19-serif-regular-indented-medium-scroll',
            Prefs::current(new Request('GET', '/', [], [], ['reader' => ['x']]))->cookieValue());
    }

    public function test_prefs_data_attrs_are_empty_for_defaults(): void
    {
        $this->assertSame('', (new Prefs())->dataAttrs(),
            'all-default prefs emit nothing: cookieless cached pages stay byte-stable');
        $p = new Prefs(size: 21, typeface: 'sans', spacing: 'airy', paragraphs: 'spaced', width: 'wide', mode: 'pages');
        $this->assertSame(' data-size="21" data-typeface="sans" data-spacing="airy" data-paragraphs="spaced" data-width="wide" data-mode="pages"',
            $p->dataAttrs());
        // a single non-default field emits exactly its own attribute
        $this->assertSame(' data-mode="pages"', (new Prefs(mode: 'pages'))->dataAttrs());
    }

    /** Adversarial F2: the Text sheet's theme radio must default from the
     *  member's STORED row when no theme cookie rides the request; otherwise
     *  a typography-only save rewrites the stored theme from the auto default
     *  (which maps to paper on the row). */
    public function test_member_theme_radio_defaults_from_the_row_when_cookie_absent(): void
    {
        $this->db->query("INSERT INTO user_prefs (user_id, theme) VALUES (?, 'night') ON CONFLICT(user_id) DO UPDATE SET theme = 'night'",
            [$this->memberRowId]);
        $body = $this->client($this->memberRowId)->get('/story/read/the-rabbit-hole/1')->body;
        $this->assertStringContainsString('name="theme" value="night" checked', $body,
            'the stored row, not the cookie-absent auto default, is checked');
        // saving all-default typography with that radio keeps the row intact
        $this->client($this->memberRowId)->postWithToken('/reader/settings', [
            'theme' => 'night', 'size' => '19', 'typeface' => 'serif', 'spacing' => 'regular',
            'paragraphs' => 'indented', 'width' => 'medium', 'mode' => 'scroll',
            'return_to' => '/story/read/the-rabbit-hole/1',
        ]);
        $this->assertSame('night', $this->memberTheme());
    }

    /** The D1 desktop panel's Reset: one post returns every pref to its
     *  default and the member row to paper (auto's stored form); the
     *  default-equal economy then clears both cookies. */
    public function test_reset_restores_defaults_and_the_member_row(): void
    {
        $me = $this->client($this->memberRowId);
        $me->postWithToken('/reader/settings', [
            'theme' => 'night', 'size' => '23', 'typeface' => 'sans', 'spacing' => 'airy',
            'paragraphs' => 'spaced', 'width' => 'wide', 'mode' => 'pages',
            'return_to' => '/story/read/the-rabbit-hole/1',
        ]);
        $this->assertSame('night', $this->memberTheme());
        $res = $me->postWithToken('/reader/settings', ['reset' => '1', 'return_to' => '/story/read/the-rabbit-hole/1']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertNull($this->memberTheme(), 'reset stores Auto (NULL) on the row');
        $leaves = $this->cookieLeaves($res);
        $this->assertContains('theme=; Max-Age=0; Path=/; SameSite=Lax', $leaves, 'the theme cookie clears');
        $this->assertContains('reader=; Max-Age=0; Path=/; SameSite=Lax', $leaves, 'the reader cookie clears');
        $body = $me->get('/story/read/the-rabbit-hole/1')->body;
        $this->assertStringNotContainsString('data-size=', $body, 'default prefs emit no data attributes at all');
    }
}
