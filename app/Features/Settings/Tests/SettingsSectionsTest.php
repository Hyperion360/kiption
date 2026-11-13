<?php // app/Features/Settings/Tests/SettingsSectionsTest.php
namespace App\Features\Settings\Tests;
use App\Lang;
use Kip\{App, Database};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The settings page is a set of short forms, one per section, each saved
 *  on its own. */
final class SettingsSectionsTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-sections-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-sections-' . uniqid('', true);
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
        \App\SiteName::reset();
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

    private function setting(string $key): string
    {
        \App\Features::init($this->db, []);
        return \App\Settings::get($key);
    }

    public function test_every_setting_sits_in_exactly_one_section(): void
    {
        $all = array_merge(...array_values(\App\Features\Settings\SettingsController::SECTIONS));
        sort($all);
        $keys = array_keys(\App\Settings::KEYS);
        sort($keys);
        $this->assertSame($keys, $all);
    }

    public function test_the_page_has_one_form_per_section_with_its_own_save(): void
    {
        $body = $this->client($this->adminId)->get('/settings')->body;
        foreach (array_keys(\App\Features\Settings\SettingsController::SECTIONS) as $name) {
            $this->assertStringContainsString('<a href="#s-' . $name . '">', $body);
            $this->assertStringContainsString('<section class="settings-section" id="s-' . $name . '">', $body);
            $this->assertStringContainsString('<input type="hidden" name="section" value="' . $name . '">', $body);
        }
        $this->assertSame(count(\App\Features\Settings\SettingsController::SECTIONS), substr_count($body, 'action="/settings/save#s-'));
        foreach (array_keys(\App\Settings::KEYS) as $key) {
            $this->assertSame(1, substr_count($body, 'name="' . $key . '"'), $key . ' has one field');
        }
    }

    public function test_saving_one_section_leaves_the_switches_of_the_others_alone(): void
    {
        $c = $this->client($this->adminId);
        \App\Settings::put('reviews_on', '1');
        \App\Settings::put('guest_reviews', '1');
        \App\Settings::put('series_on', '1');
        \App\Settings::put('maintenance_on', '0');
        $res = $c->postWithToken('/settings/save', ['section' => 'basics', 'site_name' => 'Night Shelf', 'slogan' => 'Read late', 'items_per_page' => '15']);
        $this->assertSame('/settings?saved=1#s-basics', $res->headers['Location'] ?? '');
        $this->assertSame('Night Shelf', \App\Settings::all()['site_name'] ?? '');
        foreach (['reviews_on', 'guest_reviews', 'series_on', 'submissions_open', 'profiles_public'] as $switch) {
            $this->assertSame('1', $this->setting($switch), $switch . ' was not in the saved section');
        }
        $this->assertSame('0', $this->setting('maintenance_on'));
    }

    public function test_a_refused_section_saves_nothing_at_all(): void
    {
        $c = $this->client($this->adminId);
        $before = $this->setting('site_name');
        $res = $c->postWithToken('/settings/save', ['section' => 'basics', 'site_name' => 'Half Saved', 'slogan' => 'ok', 'items_per_page' => '99999']);
        $this->assertSame(422, $res->status);
        $this->assertSame($before, $this->setting('site_name'), 'one bad field keeps the good ones from saving too');
    }

    public function test_a_field_sent_as_a_list_is_refused_not_crashed(): void
    {
        $c = $this->client($this->adminId);
        $this->assertSame(422, $c->postWithToken('/settings/save', ['section' => 'basics', 'site_name' => ['a', 'b'], 'slogan' => 'ok', 'items_per_page' => '15'])->status);
        $this->assertSame(200, $c->get('/activity', ['who' => ['x'], 'kind' => ['y']])->status);
    }

    public function test_an_unticked_box_in_the_saved_section_is_off(): void
    {
        $c = $this->client($this->adminId);
        $c->postWithToken('/settings/save', ['section' => 'reviews', 'reviews_on' => '1']);
        $this->assertSame('1', $this->setting('reviews_on'));
        $this->assertSame('0', $this->setting('guest_reviews'));
        $this->assertSame('0', $this->setting('rating_only'));
        $this->assertSame('1', $this->setting('series_on'), 'another section');
    }

    public function test_a_refused_save_keeps_what_was_typed_and_says_what_to_fix(): void
    {
        $c = $this->client($this->adminId);
        $res = $c->postWithToken('/settings/save', ['section' => 'stories', 'submissions_open' => '1', 'min_words' => '900', 'max_words' => '100',
            'default_sort' => 'alpha', 'adult_rta' => '']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('role="alert">' . \App\Lang::t('settings.err_words') . '</p>', $res->body);
        $this->assertStringContainsString('name="min_words" type="number" min="0" max="100000" value="900"', $res->body);
        $this->assertStringContainsString('<option value="alpha" selected>', $res->body);
        $this->assertSame('0', $this->setting('min_words'), 'nothing was saved');

        $bad = $c->postWithToken('/settings/save', ['section' => 'footer', 'footer_copyright' => 'Mine', 'footer_copyright_url' => '', 'footer_links' => 'no bar here', 'og_image' => '']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('role="alert">', $bad->body);
        $this->assertStringContainsString('>no bar here</textarea>', $bad->body);
    }

    public function test_only_the_owner_saves_a_section(): void
    {
        $this->assertSame(403, $this->client($this->memberId)->postWithToken('/settings/save', ['section' => 'maintenance', 'maintenance_on' => '1'])->status);
        $this->assertNotSame('1', $this->setting('maintenance_on'));
    }

    public function test_settings_say_what_they_are_holding_back_right_now(): void
    {
        $body = $this->client($this->adminId)->get('/settings')->body;
        $this->assertStringContainsString(Lang::t('settings.waiting_members_none'), $body);
        $this->assertStringContainsString(Lang::t('settings.maintenance_now_off'), $body);
        $this->assertStringContainsString(Lang::t('maintenance.body'), $body);
        $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['wait@e.test', 'x', 'waiting', 'member', 0, date('c'), 'waiting']);
        $body = $this->client($this->adminId)->get('/settings')->body;
        $this->assertStringContainsString(Lang::t('settings.waiting_members_one', ['n' => 1]), $body);
        $this->assertStringContainsString('<a href="/queue">' . Lang::t('settings.waiting_link') . '</a>', $body);
    }
}
