<?php // tests/GuestPostLinkTest.php
namespace App\Tests;

use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Session;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** "Post a story" is offered to guests, but the form opens only for a member
 *  with a confirmed account: a guest is sent to sign in (or create an account
 *  first) and lands on the form afterwards. */
final class GuestPostLinkTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private string $mode = 'verify';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-guestpost-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-guestpost-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('gp@e.test', ?, 'gpmember', ?, ?, 'gpmember')", [$hash, date('c'), date('c')]);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('gpnew@e.test', ?, 'gpnew', NULL, ?, 'gpnew')", [$hash, date('c')]);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        \App\SiteName::reset();
        \App\Posting::init([]);
        $store = [];
        \App\SessionGuard::valid(new Database('sqlite:' . $this->path), new Session($store));
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function config(): array
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        $config = \App\Settings::apply([
            'env' => 'prod', 'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'registration_mode' => $this->mode, 'validation_required' => false, 'items_per_page' => 20,
            'nav_file' => $this->root . '/nav.json', 'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__) . '/app/Features',
            'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'],
            'signup_min_seconds' => 0,
        ]);
        \App\Posting::init($config); // what public/index.php does after the settings overlay
        return $config;
    }

    private function client(): TestClient
    {
        $store = [];
        \App\SessionGuard::valid(new Database('sqlite:' . $this->path), new Session($store)); // a guest request: no role
        return new TestClient(new App($this->config()));
    }

    private function signIn(TestClient $client): \Kip\Http\Response
    {
        return $client->postWithToken('/auth/attempt', ['email' => 'gp@e.test', 'password' => 'password123']);
    }

    public function test_guests_see_post_a_story_on_public_pages_when_posting_is_open(): void
    {
        foreach (['/', '/browse'] as $path) {
            $html = $this->client()->get($path)->body;
            $this->assertStringContainsString('href="/story/new"', $html, $path);
            $this->assertLessThan(strpos($html, 'href="/auth/login"', (int) strpos($html, 'id="menu"')), strpos($html, 'href="/story/new"', (int) strpos($html, 'id="menu"')), 'before Sign in');
        }
    }

    public function test_the_desktop_header_carries_the_guest_link_because_guests_have_no_menu_there(): void
    {
        $html = $this->client()->get('/browse')->body;
        $head = substr($html, 0, (int) strpos($html, '</header>'));
        $this->assertStringContainsString('<a href="/story/new" class="nav-post">', $head);
        $this->assertLessThan(strpos($head, 'class="nav-account"'), strpos($head, 'class="nav-post"'), 'before Log in');
        $id = (int) $this->db->one("SELECT id FROM users WHERE email = 'gp@e.test'")['id'];
        $this->assertStringNotContainsString('nav-post', $this->client()->actingAs($id)->get('/browse')->body);
    }

    public function test_no_guest_link_when_posting_is_paused(): void
    {
        \App\Features::init($this->db, []);
        \App\Settings::put('submissions_open', '0');
        $this->assertStringNotContainsString('href="/story/new"', $this->client()->get('/browse')->body);
    }

    public function test_no_guest_link_when_registration_is_closed(): void
    {
        $this->mode = 'closed';
        $this->assertStringNotContainsString('href="/story/new"', $this->client()->get('/browse')->body);
    }

    public function test_invite_mode_still_offers_the_link(): void
    {
        $this->mode = 'invite';
        $this->assertStringContainsString('href="/story/new"', $this->client()->get('/browse')->body);
    }

    public function test_the_cached_guest_page_loses_the_link_once_a_save_purges_it(): void
    {
        $dir = $this->root . '/static';
        $cache = new \App\StaticCache\Cache($dir);
        $req = new \Kip\Http\Request('GET', '/browse', [], [], []);
        $app = new App($this->config());
        $cache->maybeStore($req, $app->handle($req));
        $this->assertStringContainsString('href="/story/new"', $cache->serve($req)->body);
        // The owner pauses posting on the board; the save runs the purge set.
        \App\Settings::put('submissions_open', '0');
        \App\CachePurge::run($app, $this->db);
        $this->assertNull($cache->serve($req), 'the old copy is gone');
        $cache->maybeStore($req, (new App($this->config()))->handle($req));
        $this->assertStringNotContainsString('href="/story/new"', $cache->serve($req)->body);
    }

    public function test_guest_opening_the_form_is_sent_to_sign_in_with_the_reason(): void
    {
        $client = $this->client();
        $res = $client->get('/story/new');
        $this->assertSame(303, $res->status);
        $this->assertSame('/auth/login?to=post', $res->headers['Location']);
        $this->assertSame('private, no-store', $res->headers['Cache-Control']);
        $this->assertSame('/story/new', $client->session->get('after_signin'));
    }

    public function test_guest_posting_to_create_is_still_refused(): void
    {
        $before = (int) $this->db->one('SELECT COUNT(*) c FROM stories')['c'];
        $res = $this->client()->post('/story/create', ['title' => 'Nope', 'rating_id' => '1']);
        $this->assertContains($res->status, [302, 303, 403]);
        $this->assertSame($before, (int) $this->db->one('SELECT COUNT(*) c FROM stories')['c']);
    }

    public function test_closed_registration_sends_a_guest_to_plain_login_without_a_create_account_line(): void
    {
        $this->mode = 'closed';
        $client = $this->client();
        $res = $client->get('/story/new');
        $this->assertSame('/auth/login', $res->headers['Location']);
        $html = $client->get('/auth/login', ['to' => 'post'])->body;
        $this->assertStringNotContainsString('/auth/register', $html);
    }

    public function test_login_explains_and_links_to_registration_that_keeps_the_reason(): void
    {
        $html = $this->client()->get('/auth/login', ['to' => 'post'])->body;
        $this->assertStringContainsString('Sign in to post your story.', $html);
        $this->assertStringContainsString('href="/auth/register?to=post"', $html);
        $this->assertStringNotContainsString('Sign in to post your story', $this->client()->get('/auth/login')->body);
    }

    public function test_invite_mode_login_says_new_accounts_need_an_invitation(): void
    {
        $this->mode = 'invite';
        $html = $this->client()->get('/auth/login', ['to' => 'post'])->body;
        $this->assertStringContainsString('invitation', $html);
    }

    public function test_register_form_carries_the_reason_and_sends_the_new_member_back_to_sign_in_with_it(): void
    {
        $client = $this->client();
        $this->assertStringContainsString('name="to" value="post"', $client->get('/auth/register', ['to' => 'post'])->body);
        $this->assertStringNotContainsString('name="to"', $client->get('/auth/register')->body);
        $form = ['penname' => 'brandnew', 'email' => 'bn@e.test', 'password' => 'password123', 'confirm' => 'password123'];
        $res = $client->postWithToken('/auth/store', $form + ['to' => 'post']);
        $this->assertSame('/auth/login?to=post', $res->headers['Location']);
        $res = $this->client()->postWithToken('/auth/store', ['penname' => 'second', 'email' => 'sc@e.test', 'to' => 'elsewhere'] + $form);
        $this->assertSame('/auth/login', $res->headers['Location'], 'only the literal post is honoured');
    }

    public function test_signing_in_after_the_redirect_opens_the_story_form_once(): void
    {
        $client = $this->client();
        $client->get('/story/new');
        $res = $this->signIn($client);
        $this->assertSame('/story/new', $res->headers['Location']);
        $this->assertNull($client->session->get('after_signin'), 'consumed');
        $client->postWithToken('/auth/logout');
        $this->assertSame('/', $this->signIn($client)->headers['Location']);
    }

    public function test_the_magic_link_path_consumes_it_too(): void
    {
        $client = $this->client();
        $client->get('/story/new');
        $token = (new \App\LoginLinks($this->db))->request('gp@e.test', '127.0.0.1');
        $this->assertNotNull($token);
        $res = $client->postWithToken('/auth/enter', ['t' => $token]);
        $this->assertSame('/story/new', $res->headers['Location']);
        $this->assertNull($client->session->get('after_signin'));
    }

    public function test_signing_in_without_the_reason_goes_home(): void
    {
        $this->assertSame('/', $this->signIn($this->client())->headers['Location']);
    }

    public function test_an_unconfirmed_member_is_still_refused(): void
    {
        $client = $this->client();
        $client->get('/story/new');
        $res = $client->postWithToken('/auth/attempt', ['email' => 'gpnew@e.test', 'password' => 'password123']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('verify your email first', $res->body);
        $this->assertNull($client->session->get('user_id'));
    }

    public function test_a_signed_in_member_still_gets_the_form(): void
    {
        $id = (int) $this->db->one("SELECT id FROM users WHERE email = 'gp@e.test'")['id'];
        $this->assertSame(200, $this->client()->actingAs($id)->get('/story/new')->status);
    }
}
