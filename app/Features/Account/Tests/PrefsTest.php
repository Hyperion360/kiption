<?php // app/Features/Account/Tests/PrefsTest.php
namespace App\Features\Account\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class PrefsTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-prefs-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function newApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-prefs-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-prefs-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    /** The factory keeps the App instance as $this->app (the SeriesTest idiom,
     *  plan review finding 3). Since the Kip 0.5 sync, TestClient keeps a real
     *  cookie jar, so the toc assertion replays the saved cookie on the same
     *  client; the cookieless case still drives App::handle with an explicit
     *  empty jar. */
    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    public function test_prefs_form_saves_everything(): void
    {
        $me = $this->client($this->memberId());
        $res = $me->postWithToken('/account/prefs', [
            'bio' => 'I read *everything*.', 'is_beta' => '1', 'default_sort' => 'alpha',
            'toc_first' => '1', 'notify_review' => '0', 'notify_response' => '0',
            'notify_favorites' => '0', 'notify_favorite_digest' => '0',
        ]);
        $this->assertSame(302, $res->status, $res->body);
        $db = new \Kip\Database('sqlite:' . $this->path);
        $u = $db->one('SELECT bio, is_beta FROM users WHERE penname = ?', ['betafriend']);
        $this->assertSame('I read *everything*.', $u['bio']);
        $this->assertSame(1, (int) $u['is_beta']);
        $p = $db->one('SELECT * FROM user_prefs WHERE user_id = ?', [$this->memberId()]);
        $this->assertSame('alpha', $p['default_sort']);
        $this->assertSame(0, (int) $p['notify_review']);
        $body = $this->client()->get('/user/view/betafriend')->body;
        $this->assertStringContainsString('<em>everything</em>', $body); // bio renders markdown
        $this->assertStringContainsString('Beta reader', $body);
    }

    public function test_prefs_junk_coerces_and_bio_clamps(): void
    {
        $me = $this->client($this->memberId());
        $me->postWithToken('/account/prefs', ['bio' => str_repeat('x', 3000), 'is_beta' => '', 'default_sort' => 'sideways',
            'toc_first' => '', 'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        $db = new \Kip\Database('sqlite:' . $this->path);
        $this->assertSame(2000, strlen($db->one('SELECT bio FROM users WHERE penname = ?', ['betafriend'])['bio']));
        $this->assertSame('recent', $db->one('SELECT default_sort FROM user_prefs WHERE user_id = ?', [$this->memberId()])['default_sort']);
    }

    /** The C2 value plumbing: the account radios offer the new theme set.
     *  auto stores paper (the column CHECK admits paper/sepia/night only)
     *  and clears the cookie, the same cache-bypass economy the reader
     *  settings route applies; a real choice stores and syncs year-long. */
    public function test_theme_radios_store_new_values_and_auto_stores_paper(): void
    {
        $me = $this->client($this->memberId());
        $res = $me->postWithToken('/account/prefs', ['theme' => 'sepia', 'bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
            'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('sepia', $this->db->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme']);
        $this->assertContains('theme=sepia; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax', $this->cookieLeaves($res));
        $res = $me->postWithToken('/account/prefs', ['theme' => 'auto', 'bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
            'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('paper', $this->db->one('SELECT theme FROM user_prefs WHERE user_id = ?', [$this->memberId()])['theme'],
            'auto stores paper, never a legacy or out-of-CHECK value');
        $this->assertContains('theme=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax', $this->cookieLeaves($res),
            'auto clears the theme cookie instead of minting one');
        // the form radios carry the new value set
        $form = $me->get('/account/settings')->body;
        foreach (['paper', 'sepia', 'night', 'auto'] as $v) {
            $this->assertStringContainsString('name="theme" value="' . $v . '"', $form);
        }
    }

    /** @return list<string> the Set-Cookie leaves, scalar or list normalized */
    private function cookieLeaves(\Kip\Http\Response $res): array
    {
        $setCookie = $res->headers['Set-Cookie'] ?? [];
        return is_array($setCookie) ? $setCookie : [$setCookie];
    }

    public function test_toc_first_cookie_redirects_bare_read(): void
    {
        $me = $this->client($this->memberId());
        $res = $me->postWithToken('/account/prefs', ['bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '1',
            'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        // The save rides one list leaf even when it is the only directive
        // (the withAddedHeader chain, one shape for every count).
        $this->assertSame(['toc=1; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax'], $res->headers['Set-Cookie'] ?? null);
        // The Kip 0.5 jar replays the cookie on the same client's next
        // request, the browser's part: the bare read now redirects.
        $redirect = $me->get('/story/read/the-rabbit-hole');
        $this->assertSame(302, $redirect->status, 'the saved toc cookie carried onto the next request');
        $this->assertSame('/story/view/the-rabbit-hole', $redirect->headers['Location'] ?? '');
        // without the cookie: chapter 1 exactly as before
        $plain = $this->app->handle(new \Kip\Http\Request('GET', '/story/read/the-rabbit-hole', [], [], []));
        $this->assertSame(200, $plain->status);
    }

    public function test_notify_review_off_silences_only_that_recipient(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/account/prefs', ['bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
            'notify_review' => '0', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        $fan = $this->client($this->memberId());
        $fan->postWithToken('/kudos/add/the-rabbit-hole'); // ungated: still notifies
        $fan->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Silent?', 'rating' => '', 'guest_name' => '']);
        $inbox = $owner->get('/notifications')->body;
        $this->assertStringContainsString('left kudos', $inbox);
        $this->assertStringNotContainsString('reviewed', $inbox);
    }

    public function test_prefs_form_reflects_saved_notify_toggle_state(): void
    {
        $me = $this->client($this->memberId());
        // before any save there is no prefs row: the toggles fail-safe ON (COALESCE)
        $before = $me->get('/account/settings')->body;
        $this->assertStringContainsString('name="notify_review" value="1" checked', $before);
        $this->assertStringContainsString('name="notify_response" value="1" checked', $before);
        $this->assertStringContainsString('name="notify_favorites" value="1" checked', $before);
        $me->postWithToken('/account/prefs', ['bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
            'notify_review' => '0', 'notify_response' => '0', 'notify_favorites' => '0', 'notify_favorite_digest' => '']);
        $after = $me->get('/account/settings')->body;
        // the three inputs render without the checked attribute
        foreach (['notify_review', 'notify_response', 'notify_favorites'] as $field) {
            $this->assertStringContainsString('<input type="checkbox" name="' . $field . '" value="1">', $after);
            $this->assertStringNotContainsString('name="' . $field . '" value="1" checked', $after);
        }
    }

    public function test_notify_response_off_silences_reply_notification(): void
    {
        $fan = $this->client($this->memberId());
        $fan->postWithToken('/account/prefs', ['bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
            'notify_review' => '1', 'notify_response' => '0', 'notify_favorites' => '1', 'notify_favorite_digest' => '']);
        $fan->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Loved the descent.', 'rating' => '', 'guest_name' => '']);
        $reviewId = (int) $this->db->one(
            "SELECT id FROM reviews WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$this->memberId()])['id'];
        $this->client($this->authorId())->postWithToken('/review/reply/' . $reviewId, ['body' => 'Thanks for reading.']);
        $inbox = $fan->get('/notifications')->body;
        $this->assertStringNotContainsString('replied to a review', $inbox); // their pref, their silence
    }
}
