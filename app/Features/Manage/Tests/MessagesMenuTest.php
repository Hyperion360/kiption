<?php // app/Features/Manage/Tests/MessagesMenuTest.php
namespace App\Features\Manage\Tests;
use App\Lang;
use App\SiteText\Store;
use Kip\{App, Database};
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Owner messages (/help /rules /terms and the notices), the settings block
 *  for footer, picture and maintenance, the /manage page and the word filter. */
final class MessagesMenuTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-mm-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-mm-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $ins = 'INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $this->db->query($ins, ['ma@e.test', $hash, 'mmadmin', 'admin', 1, date('c'), date('c'), 'mmadmin']);
        $this->adminId = (int) $this->db->lastInsertId();
        $this->db->query($ins, ['mm@e.test', $hash, 'mmmember', 'member', 0, date('c'), date('c'), 'mmmember']);
        $this->memberId = (int) $this->db->lastInsertId();
        Lang::reset();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        \App\Maintenance::reset();
        \App\Features\Wordfilter\WordFilter::forget();
        Lang::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function config(array $extra = []): array
    {
        return \App\Settings::apply($extra + [
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
        ]);
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        Lang::useSiteText($this->root . '/site-text');
        $c = new TestClient(new App($this->config($extra)));
        return $as === null ? $c : $c->actingAs($as);
    }

    private function say(string $key, string $text): void
    {
        $res = $this->client($this->adminId)->postWithToken('/text/save', ['lang' => 'en', 'key' => $key, 'value' => $text]);
        $this->assertSame(302, $res->status, $res->body);
        Lang::reset();
    }

    // D.1 messages

    public function test_help_rules_terms_render_the_message_and_404_while_empty(): void
    {
        foreach (['help', 'rules', 'terms'] as $page) {
            $this->assertSame(404, $this->client()->get('/' . $page)->status, $page . ' is empty');
            $this->say('msg.' . $page, "# Hello {$page}\n\nSome **bold** words.\n\n- one\n- two");
            $res = $this->client()->get('/' . $page);
            $this->assertSame(200, $res->status);
            $this->assertStringContainsString('<strong>bold</strong>', $res->body);
            $this->assertStringContainsString('<li>one</li>', $res->body);
            $this->assertStringContainsString('<h2>Hello ' . $page . '</h2>', $res->body);
        }
    }

    public function test_a_message_with_markup_is_refused(): void
    {
        $this->assertNotNull((new Store($this->db))->put('en', 'msg.help', 'hi <script>alert(1)</script>'));
    }

    public function test_welcome_message_shows_on_the_home_page(): void
    {
        $this->assertStringNotContainsString('Welcome to our corner', $this->client()->get('/')->body);
        $this->say('msg.welcome', 'Welcome to our corner');
        $this->assertStringContainsString('Welcome to our corner', $this->client()->get('/')->body);
    }

    public function test_footer_links_appear_only_for_written_messages(): void
    {
        $this->assertStringNotContainsString('href="/rules"', $this->client()->get('/')->body);
        $this->say('msg.rules', 'Be kind.');
        $this->assertStringContainsString('href="/rules"', $this->client()->get('/')->body);
    }

    public function test_signup_terms_link_points_at_the_terms_message(): void
    {
        $this->say('msg.terms', 'Our terms.');
        $this->assertStringContainsString('href="/terms"', $this->client()->get('/auth/register')->body);
    }

    // D.2 settings block

    public function test_footer_picture_and_links_save_and_bad_links_are_refused(): void
    {
        $a = $this->client($this->adminId);
        $ok = $a->postWithToken('/settings/save', [
            'site_name' => 'Kiption', 'registration_mode' => 'verify', 'validation_required' => '0', 'items_per_page' => '20', 'footer_present' => '1', 'footer_copyright' => 'The Owner',
            'footer_copyright_url' => 'https://owner.example', 'footer_links' => "About | /page/about\nContact | https://x.example/c",
            'og_image' => '',
        ]);
        $this->assertSame(302, $ok->status, $ok->body);
        $this->assertStringContainsString('The Owner', $this->client($this->adminId)->get('/settings')->body);
        $bad = $this->client($this->adminId)->postWithToken('/settings/save', ['site_name' => 'Kiption', 'registration_mode' => 'verify', 'validation_required' => '0', 'items_per_page' => '20', 'footer_present' => '1', 'footer_links' => 'no separator here']);
        $this->assertSame(422, $bad->status);
        $bad = $this->client($this->adminId)->postWithToken('/settings/save', ['site_name' => 'Kiption', 'registration_mode' => 'verify', 'validation_required' => '0', 'items_per_page' => '20', 'footer_present' => '1', 'footer_links' => 'x | javascript:alert(1)']);
        $this->assertSame(422, $bad->status);
    }

    // D.3 maintenance

    public function test_maintenance_blocks_visitors_and_lets_admins_in_with_a_banner(): void
    {
        $this->client($this->adminId)->postWithToken('/settings/save', ['site_name' => 'Kiption', 'registration_mode' => 'verify', 'validation_required' => '0', 'items_per_page' => '20', 'footer_present' => '1', 'maintenance_on' => '1']);
        $cfg = $this->config();
        $data = [];
        $this->assertTrue($cfg['maintenance_on']);
        $visitor = \App\Maintenance::gate($cfg, new Request('GET', '/', [], [], []), new \Kip\Session($data));
        $this->assertSame(503, $visitor->status);
        $this->assertNull(\App\Maintenance::gate($cfg, new Request('GET', '/auth/login', [], [], []), new \Kip\Session($data)));
        $home = $this->client($this->adminId)->get('/');
        $this->assertSame(200, $home->status);
        $this->assertStringContainsString('maintenance-banner', $home->body);
        $this->assertStringNotContainsString('maintenance-banner', $this->client($this->memberId)->get('/')->body);
    }

    // D.4 word filter

    public function test_a_listed_word_blocks_a_review_and_a_longer_word_does_not(): void
    {
        $this->db->query("INSERT OR IGNORE INTO word_filter (word) VALUES ('casino bonus')");
        \App\Features\Wordfilter\WordFilter::forget();
        $c = $this->client($this->memberId);
        $res = $c->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Get a Casino   Bonus now', 'rating' => '', 'guest_name' => '']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('casino bonus', $res->body);
        $this->assertSame(302, $this->client($this->memberId)->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Nice casino bonuses scene', 'rating' => '', 'guest_name' => ''])->status);
        $this->assertFalse(\App\Features\Wordfilter\WordFilter::matches('ass', 'a class act'));
        $this->assertTrue(\App\Features\Wordfilter\WordFilter::matches('ass', 'what an ass.'));
    }

    public function test_the_filter_is_not_wired_into_story_text(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/Story/*.php') as $f) {
            $this->assertStringNotContainsString('WordFilter', (string) file_get_contents($f));
        }
    }

    public function test_the_owner_edits_and_clears_the_list(): void
    {
        $a = $this->client($this->adminId);
        $this->assertSame(200, $a->get('/wordfilter')->status);
        $this->assertSame(302, $a->postWithToken('/wordfilter/save', ['words' => "Banana Split\n\nbanana split\nzorp"])->status);
        $this->assertSame(['banana split', 'zorp'], \App\Features\Wordfilter\WordFilter::words($this->db));
        $m = $this->client($this->memberId);
        $this->assertSame(422, $m->postWithToken('/review/add/the-rabbit-hole', ['body' => 'zorp!', 'rating' => '', 'guest_name' => ''])->status);
        $this->assertSame(302, $this->client($this->adminId)->postWithToken('/wordfilter/save', ['words' => ''])->status);
        $this->assertSame([], \App\Features\Wordfilter\WordFilter::words($this->db));
        $this->assertSame(302, $this->client($this->memberId)->postWithToken('/review/add/the-rabbit-hole', ['body' => 'zorp!', 'rating' => '', 'guest_name' => ''])->status);
    }

    public function test_the_filter_screen_is_admin_only_and_needs_a_token(): void
    {
        $this->assertSame(403, $this->client($this->memberId)->get('/wordfilter')->status);
        $this->assertSame(403, $this->client($this->memberId)->postWithToken('/wordfilter/save', ['words' => 'x'])->status);
        $this->assertSame(403, $this->client($this->adminId)->post('/wordfilter/save', ['words' => 'x'])->status);
        $this->assertNotEmpty(\App\Features\Wordfilter\WordFilter::words($this->db), 'the starter list is installed');
    }

    // D.5 / D.6 manage

    public function test_manage_lists_the_screens_for_admins_only(): void
    {
        $this->assertSame(403, $this->client($this->memberId)->get('/manage')->status);
        $this->assertSame(302, $this->client()->get('/manage')->status);
        $res = $this->client($this->adminId)->get('/manage');
        $this->assertSame(200, $res->status);
        foreach (['/queue', '/images', '/wordfilter', '/text/group/msg', '/settings', '/adminmembers', '/mailusers', '/categories', '/tags', '/templates', '/profilefields', '/signupcheck', '/activity'] as $url) {
            $this->assertStringContainsString('href="' . $url . '"', $res->body, $url);
        }
        $this->assertStringContainsString('href="/admin"', $res->body);
        $off = $this->client($this->adminId, ['admin' => ['enabled' => false]])->get('/manage');
        $this->assertStringNotContainsString('href="/admin"', $off->body);
    }

    public function test_the_menu_points_admins_at_manage_instead_of_the_old_links(): void
    {
        $body = $this->client($this->adminId)->get('/')->body;
        $this->assertStringContainsString('href="/manage"', $body);
        $this->assertStringNotContainsString('href="/admin"', $body);
        $this->assertStringNotContainsString('href="/news/new"', $body);
    }

    public function test_owner_screens_carry_a_way_back_to_the_menu(): void
    {
        $trail = \App\Features\Manage\ManageController::trail(...);
        $urls = static fn (string $path): array => array_column($trail($path), 'url');
        $this->assertSame([], $urls('/manage'), 'the menu itself needs no way back');
        $this->assertSame(['/manage'], $urls('/tags'));
        $this->assertSame(['/manage', '/tags'], $urls('/tags/edit/3'));
        $this->assertSame(['/manage', '/text'], $urls('/text/group/home'));
        $this->assertSame(['/manage'], $urls('/text/group/msg'));
        $this->assertSame(['/manage', '/pages/manage'], $urls('/page/edit/about'));
        $this->assertSame(['/manage', '/news'], $urls('/news/edit/1'));
        $this->assertSame(['/manage', '/queue'], $urls('/report/remove/7'), 'removing reported content belongs to the queue');
        foreach (['/', '/news', '/news/view/1', '/page/view/about', '/account', '/browse', '/report/story/x'] as $public) {
            $this->assertSame([], $urls($public), $public . ' is not an owner screen');
        }
    }
}
