<?php // tests/PrefsTest.php
namespace App\Tests;
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
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-prefs-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-prefs-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    /** The factory keeps the App instance as $this->app (the SeriesTest idiom,
     *  plan review finding 3): the toc assertions drive App::handle directly
     *  because TestClient headers never reach Request->cookies (finding 2). */
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

    public function test_toc_first_cookie_redirects_bare_read(): void
    {
        $me = $this->client($this->memberId());
        $res = $me->postWithToken('/account/prefs', ['bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '1',
            'notify_review' => '', 'notify_response' => '', 'notify_favorites' => '', 'notify_favorite_digest' => '']);
        $this->assertStringContainsString('toc=1', $res->headers['Set-Cookie'] ?? '');
        // ThemeTest idiom (plan review finding 2): a Cookie HEADER never reaches
        // Request->cookies under TestClient; the fifth Request constructor arg is
        // the cookie jar. Drive the app directly.
        $redirect = $this->app->handle(new \Kip\Http\Request('GET', '/story/read/the-rabbit-hole', [], [], ['toc' => '1']));
        $this->assertSame(302, $redirect->status);
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
        $before = $me->get('/account')->body;
        $this->assertStringContainsString('name="notify_review" value="1" checked', $before);
        $this->assertStringContainsString('name="notify_response" value="1" checked', $before);
        $this->assertStringContainsString('name="notify_favorites" value="1" checked', $before);
        $me->postWithToken('/account/prefs', ['bio' => '', 'is_beta' => '', 'default_sort' => 'recent', 'toc_first' => '',
            'notify_review' => '0', 'notify_response' => '0', 'notify_favorites' => '0', 'notify_favorite_digest' => '']);
        $after = $me->get('/account')->body;
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
