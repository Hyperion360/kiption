<?php // app/Features/Settings/Tests/SettingsTest.php
namespace App\Features\Settings\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Http\{Request, Response};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The operator settings board (/settings): DB-held values that ride the SAME
 *  boot statement as the feature flags and overlay config.php at boot, so
 *  every existing $app->config(...) read point picks them up with zero
 *  downstream edits. The client() helper mirrors the entrypoint seam
 *  (Features::init then Settings::apply) because TestClient builds App
 *  directly and never runs public/index.php. */
#[Medium]
final class SettingsTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminUserId = 0;
    private int $memberUserId = 0;
    private int $moderatorUserId = 0;
    private ?App $lastApp = null;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-settings-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-settings-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        \App\CachePurge::$cdnTransport = null;
        \App\Lang::reset(); // the language tests add owner languages
        \App\Attribution::reset(); // the footer-seam test must not leak into later suites
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    private function db(): Database
    {
        return $this->db;
    }

    public function test_settings_override_config_for_every_reader(): void
    {
        $this->seed();
        $admin = $this->client($this->adminUserId);
        $this->assertSame(302, $admin->postWithToken('/settings/save', [
            'site_name' => 'Renamed Archive', 'registration_mode' => 'invite',
            'validation_required' => '0', 'items_per_page' => '50', 'powered_by' => '0',
            'adult_rta' => 'RTA-5042-1996-1400-1577-RTA', 'ui_lang' => 'en', 'footer_present' => '1',
        ])->status);
        // Rows landed for every key the board writes (skin included: an
        // absent field defaults to 'default', never 'leave unchanged').
        $this->assertSame(count(\App\Settings::KEYS), (int) $this->db()->one('SELECT COUNT(*) c FROM settings')['c']);
        $this->assertSame('RTA-5042-1996-1400-1577-RTA',
            (string) $this->db()->one('SELECT value FROM settings WHERE key = ?', ['adult_rta'])['value']);
        // A FRESH client (the entrypoint seam: init then apply) sees the new
        // values on read points that never learned settings exist: the layout
        // reads site_name, AuthController reads registration_mode.
        $client = $this->client();
        $this->assertStringContainsString('Renamed Archive', (string) $client->get('/')->body);
        $this->assertStringContainsString('invite', (string) $client->get('/auth/register')->body);
        // The board re-renders the stored values (DB row wins over config).
        $board = $this->client($this->adminUserId)->get('/settings');
        $this->assertSame(200, $board->status);
        $this->assertStringContainsString('Renamed Archive', (string) $board->body);
    }

    public function test_a_save_purges_the_cdn_after_the_response(): void
    {
        $this->seed();
        $calls = [];
        \App\CachePurge::$cdnTransport = function (string $url, string $body, string $token) use (&$calls): bool {
            $calls[] = [$url, $body, $token];
            return true;
        };
        $admin = $this->client($this->adminUserId, ['cdn' => [
            'enabled' => true, 'zone_id' => 'zone123', 'api_token' => 'sekrit', 'purge_host' => 'archive.example',
        ]]);
        $this->assertSame(302, $admin->postWithToken('/settings/save', [
            'site_name' => 'Purged', 'registration_mode' => 'open', 'items_per_page' => '20',
        ])->status);
        $this->lastApp->runDeferred(); // TestClient drains the queue itself; this proves nothing is left over
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('/zones/zone123/purge_cache', $calls[0][0]);
        $this->assertSame('{"prefixes":["archive.example"]}', $calls[0][1]);
        $this->assertSame('sekrit', $calls[0][2]);
    }

    public function test_a_save_without_a_cdn_queues_nothing(): void
    {
        $this->seed();
        $calls = [];
        \App\CachePurge::$cdnTransport = function () use (&$calls): bool { $calls[] = 1; return true; };
        $admin = $this->client($this->adminUserId);
        $admin->postWithToken('/settings/save', ['site_name' => 'Quiet', 'registration_mode' => 'open', 'items_per_page' => '20']);
        $this->lastApp->runDeferred();
        $this->assertSame([], $calls);
    }

    public function test_the_board_lists_installed_languages_and_saves_the_default(): void
    {
        $this->seed();
        $this->db()->query("INSERT INTO site_languages (code, name, dir) VALUES ('fr', 'Francais', 'ltr')");
        \App\SiteText\Compiler::compile($this->db(), $this->root . '/site-text'); // also points Lang at the folder
        $admin = $this->client($this->adminUserId, ['site_text_dir' => $this->root . '/site-text']);
        $board = $admin->get('/settings');
        $this->assertStringContainsString('<option value="en" selected>English</option>', $board->body);
        $this->assertStringContainsString('<option value="fr">Francais</option>', $board->body);
        $base = ['site_name' => 'Kiption', 'registration_mode' => 'open', 'items_per_page' => '20'];
        $this->assertSame(302, $admin->postWithToken('/settings/save', $base + ['ui_lang' => 'fr'])->status);
        $this->assertSame('fr', $this->db()->one("SELECT value FROM settings WHERE key = 'ui_lang'")['value']);
        $this->assertStringContainsString('<option value="fr" selected>Francais</option>',
            $this->client($this->adminUserId, ['site_text_dir' => $this->root . '/site-text'])->get('/settings')->body);
        // A form that leaves the field out keeps the saved choice.
        $this->assertSame(302, $admin->postWithToken('/settings/save', $base)->status);
        $this->assertSame('fr', $this->db()->one("SELECT value FROM settings WHERE key = 'ui_lang'")['value']);
    }

    public function test_an_unknown_default_language_is_refused(): void
    {
        $this->seed();
        $admin = $this->client($this->adminUserId);
        $res = $admin->postWithToken('/settings/save', ['site_name' => 'Kiption', 'registration_mode' => 'open', 'items_per_page' => '20', 'ui_lang' => 'zz']);
        $this->assertSame(422, $res->status);
        $this->assertNull($this->db()->one("SELECT value FROM settings WHERE key = 'ui_lang'"));
        $this->assertSame(422, $admin->postWithToken('/settings/save', ['site_name' => 'Kiption', 'registration_mode' => 'open', 'items_per_page' => '20', 'ui_lang' => '<b>'])->status);
    }

    public function test_non_admin_member_moderator_and_guest_are_barred(): void
    {
        $this->seed();
        // The SQL admin gate (the FeaturesController idiom): members and
        // moderators 403, guests hit the auth redirect. A barred POST writes
        // no row.
        $this->assertSame(403, $this->client($this->memberUserId)->get('/settings')->status);
        $this->assertSame(403, $this->client($this->moderatorUserId)->get('/settings')->status);
        $this->assertSame(302, $this->client()->get('/settings')->status);
        $this->assertSame(403, $this->client($this->memberUserId)
            ->postWithToken('/settings/save', ['site_name' => 'Nope'])->status);
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM settings')['c'], 'no row from a barred POST');
    }

    public function test_settings_ride_the_single_boot_query(): void
    {
        // The real pin: one STATEMENT resolves flags+settings together. Instance
        // listener, the QueryBudgetTest idiom (onQuery is an instance method).
        $boot = sys_get_temp_dir() . '/settings-boot-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database('sqlite:' . $boot);
        $db->query('CREATE TABLE feature_flags (key TEXT PRIMARY KEY, enabled INTEGER)');
        $db->query('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
        $count = 0;
        $db->onQuery(function () use (&$count): void { $count++; });
        \App\Features::init($db, []);
        \App\Features::on('search');
        \App\Settings::all(); // force both memo paths
        $this->assertSame(1, $count, 'one round trip for flags AND settings');
        unset($db);
        @unlink($boot);
    }

    public function test_unmigrated_database_degrades_to_flags_only(): void
    {
        // The git-pull window: feature_flags exists, settings does not. The
        // UNION fold falls back to flags-only instead of fataling every page.
        $partial = sys_get_temp_dir() . '/settings-partial-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database('sqlite:' . $partial);
        $db->query('CREATE TABLE feature_flags (key TEXT PRIMARY KEY, enabled INTEGER)');
        \App\Features::init($db, []);
        $this->assertTrue(\App\Features::on('news'), 'flags still resolve');
        $this->assertSame([], \App\Settings::all(), 'settings read empty, no throw');
        $this->assertSame(['site_name' => 'Kiption'], \App\Settings::apply(['site_name' => 'Kiption']),
            'apply layers nothing over config');
        // A database with NEITHER table (fresh install, pre-migrate): apply must
        // return the config unchanged, never fatal the CLI arms that exist to
        // fix exactly that install.
        $empty = sys_get_temp_dir() . '/settings-empty-' . bin2hex(random_bytes(4)) . '.sqlite';
        \App\Features::init(new Database('sqlite:' . $empty), []);
        $this->assertSame(['site_name' => 'Kiption'], \App\Settings::apply(['site_name' => 'Kiption']));
        unset($db);
        @unlink($partial); @unlink($empty);
    }

    public function test_overrides_coerce_to_the_configs_own_type_and_skip_keys_the_config_lacks(): void
    {
        \App\Features::init($this->db(), []);
        $config = ['site_name' => 'Kiption', 'registration_mode' => 'verify',
                   'validation_required' => true, 'items_per_page' => 20];
        $this->assertSame([], \App\Settings::overrides($config), 'no rows: nothing overlays');
        \App\Settings::put('site_name', 'Cave Archive');
        \App\Settings::put('registration_mode', 'approval');
        \App\Settings::put('validation_required', '0');
        \App\Settings::put('items_per_page', '50');
        \App\Settings::put('powered_by', '1');
        // The config lacks powered_by (the app-prep footer lane owns it): the
        // row is stored but never overlays until the config carries the key.
        \App\Settings::put('skin', 'classic'); // the exemption: skin overlays even unmigrated config
        $this->assertSame([
            'site_name' => 'Cave Archive', 'registration_mode' => 'approval',
            'validation_required' => false, 'items_per_page' => 50, 'skin' => 'classic',
        ], \App\Settings::overrides($config));
        // Bool overlays follow the config's own type both ways.
        \App\Settings::put('validation_required', '1');
        $this->assertTrue(\App\Settings::overrides($config)['validation_required']);
        // A config that CARRIES powered_by flips with the stored row (the
        // override activates the moment the footer config lands).
        $withFooter = $config + ['powered_by' => false];
        $this->assertTrue(\App\Settings::overrides($withFooter)['powered_by']);
        // A row the config has no key for is skipped (unknown key ignored):
        // direct SQL plants one the board can never write, and a re-init
        // forces the resolution to read it (a stale memo must not mask the skip).
        $this->db()->query("INSERT INTO settings (key, value) VALUES ('not_a_config_key', 'x')");
        \App\Features::init($this->db(), []);
        $this->assertArrayNotHasKey('not_a_config_key', \App\Settings::overrides($config));
        // put() itself refuses keys outside KEYS, never an insert.
        $this->expectException(\InvalidArgumentException::class);
        \App\Settings::put('no_such_setting', 'x');
    }

    /** Pin: the sending and storage caps are operator config, never board
     *  rows. A site administrator may cap outbound mail and uploads; the
     *  settings board must never be able to move those keys. */
    public function test_the_cap_keys_are_never_board_settings(): void
    {
        foreach (['mail_daily_cap', 'mail_monthly_cap', 'storage_cap_bytes'] as $cap) {
            $this->assertArrayNotHasKey($cap, \App\Settings::KEYS, "{$cap} must stay config.php territory");
        }
    }

    /** A stray or restored settings row named like a cap key must lose: the
     *  board could never have written it, so the overlay skips any key
     *  outside KEYS before it looks at the config. */
    public function test_a_stray_settings_row_cannot_change_a_cap(): void
    {
        \App\Features::init($this->db(), []);
        $config = ['mail_daily_cap' => 5];
        \App\Features::putSetting('mail_daily_cap', '9999');
        \App\Features::init($this->db(), []); // a stale memo must not mask the row
        $this->assertSame(5, \App\Settings::apply($config)['mail_daily_cap'],
            'a settings row the board can never write must not override a cap');
        $this->assertArrayNotHasKey('mail_daily_cap', \App\Settings::overrides(['mail_daily_cap' => 5]));
    }

    public function test_put_validates_every_key_kind(): void
    {
        \App\Features::init($this->db(), []);
        $bad = [
            ['site_name', ''], ['site_name', str_repeat('x', 61)],
            ['registration_mode', 'casual'],
            ['validation_required', '2'],
            ['items_per_page', '0'], ['items_per_page', '101'], ['items_per_page', 'ten'],
            ['skin', str_repeat('x', 61)], // skin rides the skin kind: [a-z0-9-], 1-30 chars
            ['skin', 'Big Skin'], ['skin', '../views'],
            ['adult_rta', str_repeat('x', 61)], // the label kind: empty or 1-60 chars
        ];
        foreach ($bad as [$key, $value]) {
            try {
                \App\Settings::put($key, $value);
                $this->fail("{$key} = " . var_export($value, true) . ' must be rejected');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM settings')['c'], 'no rejected value landed');
        // The label kind's off state is a legal write (unlike string keys,
        // which refuse ''): flipping the RTA label back off stores ''.
        \App\Features::init($this->db(), []);
        \App\Settings::put('adult_rta', '');
        $this->assertSame('', (string) $this->db()->one('SELECT value FROM settings WHERE key = ?', ['adult_rta'])['value']);
    }

    public function test_save_rejects_out_of_range_values_with_422(): void
    {
        $this->seed();
        $admin = $this->client($this->adminUserId);
        // The save loop writes keys in order and reports every offender: the
        // three valid fields land, items_per_page (out of the 1-100 range)
        // does not, and the response names it.
        $res = $admin->postWithToken('/settings/save', [
            'site_name' => 'Fine Name', 'registration_mode' => 'open',
            'validation_required' => '1', 'items_per_page' => '999', 'powered_by' => '0',
        ]);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('items_per_page', (string) $res->body);
        $this->assertSame('Fine Name', (string) $this->db()->one('SELECT value FROM settings WHERE key = ?', ['site_name'])['value']);
        $this->assertNull($this->db()->one('SELECT value FROM settings WHERE key = ?', ['items_per_page']));
        // Absent bools read as explicit '0' (a checkbox never POSTs unchecked);
        // an absent site_name is invalid (text keys default to '').
        $this->assertSame(422, $admin->postWithToken('/settings/save', [])->status);
    }

    public function test_save_purges_both_cache_layers(): void
    {
        $this->seed();
        $cacheDir = $this->root . '/static';
        mkdir($cacheDir, 0775, true);
        // Layer 1: a stored static '/' page (the StaticCacheTest idiom).
        $static = new \App\StaticCache\Cache($cacheDir);
        $homeReq = new Request('GET', '/', [], [], []);
        $static->maybeStore($homeReq, new Response('cached home page', 200));
        $this->assertNotNull($static->serve($homeReq));
        // Layer 2: a framework page-cache row through a cookieless guest GET.
        $this->assertSame('MISS', $this->client()->get('/browse')->headers['X-Kip-Cache'] ?? null);
        $this->assertFileExists($this->root . '/app/cache.sqlite');
        // The admin save (verbatim flag-board purge set): a renamed site must
        // not survive in any cached page.
        $this->assertSame(302, $this->client($this->adminUserId)
            ->postWithToken('/settings/save', ['site_name' => 'Purged Name', 'registration_mode' => 'verify',
                'validation_required' => '1', 'items_per_page' => '20', 'powered_by' => '0'])->status);
        $this->assertNull($static->serve($homeReq), 'static file unlinked');
        $this->assertFileExists($this->root . '/app/cache.sqlite', 'live cache database remains available');
        $cacheDb = new Database('sqlite:' . $this->root . '/app/cache.sqlite');
        $this->assertSame(0, (int) $cacheDb->one('SELECT COUNT(*) c FROM pages')['c'], 'framework cache rows cleared');
    }

    /** The footer seam, pinned end to end: a stored powered_by row must govern
     *  the rendered footer. The board's own save writes exactly this row
     *  (reproduced over HTTP 2026-10-04: the toggle saved green while the
     *  footer kept rendering, because Attribution::init read the config-file
     *  value before Settings::apply layered the row over it). */
    public function test_a_stored_powered_by_row_governs_the_footer(): void
    {
        $this->seed();
        \App\Features::init($this->db(), []);
        \App\Settings::put('powered_by', '0');
        // The entrypoint order, mirrored: apply overlays the row, THEN the
        // footer seam consumes the merged config.
        $config = \App\Settings::apply($this->config() + ['powered_by' => true, 'powered_by_url' => 'https://cloud.kiption.com']);
        \App\Attribution::init($config);
        $html = (new \Kip\View($this->config()['views']))
            ->render('layout', ['content' => '', 'navFile' => '', 'title' => 'Seam']);
        $this->assertStringNotContainsString('Powered by Kiption', $html, 'the stored row turns the footer off');
        \App\Attribution::reset();
    }

    /** The suite cannot execute the front controller (the PerUserTest
     *  source-probe idiom), so the ORDER is pinned textually: in both
     *  entrypoints Attribution::init must run AFTER Settings::apply, or the
     *  footer reads only the config-file value and the board's row is inert. */
    public function test_both_entrypoints_init_attribution_after_the_settings_overlay(): void
    {
        foreach (['public/index.php', 'bin/kip'] as $entry) {
            $src = (string) preg_replace('#/\*.*?\*/#s', '',
                (string) file_get_contents(dirname(__DIR__, 4) . '/' . $entry));
            $init = strpos($src, '\App\Attribution::init(');
            $apply = strpos($src, '\App\Settings::apply(');
            $this->assertNotFalse($init, "{$entry} must keep the Attribution::init seam");
            $this->assertNotFalse($apply, "{$entry} must keep the Settings::apply seam");
            $this->assertGreaterThan($apply, $init, "{$entry} inits Attribution after the settings overlay");
        }
    }

    /** The archive name seam: a stored site_name row must reach the header,
     *  footer and home heading, not only <title> (QA 2026-11-12: a Cloud
     *  archive named on /settings still showed "Kiption" in all three). The
     *  name is operator text, so it renders escaped. */
    public function test_a_stored_site_name_governs_the_visible_brand(): void
    {
        $this->seed();
        \App\Features::init($this->db(), []);
        \App\Settings::put('site_name', 'The <Smallville> Gazette');
        $config = \App\Settings::apply($this->config() + ['site_name' => 'Kiption']);
        \App\SiteName::init($config);
        try {
            $html = (new \Kip\View($this->config()['views']))
                ->render('layout', ['content' => '', 'navFile' => '', 'title' => 'Seam']);
            $this->assertStringContainsString('<a class="brand" href="/">The &lt;Smallville&gt; Gazette</a>', $html, 'header brand');
            $this->assertStringContainsString('<p class="foot-brand"><a href="/">The &lt;Smallville&gt; Gazette</a>', $html, 'footer brand');
            $this->assertStringNotContainsString('<Smallville>', $html, 'operator text is escaped');
            $home = (string) file_get_contents(dirname(__DIR__, 2) . '/Home/views/index.php');
            $this->assertStringContainsString('<h1><?= $this->e(\\App\\SiteName::get()) ?></h1>', $home, 'home heading reads the archive name');
        } finally {
            \App\SiteName::reset();
        }
        $this->assertSame('Kiption', \App\SiteName::get(), 'reset restores the default');
    }

    /** Same textual pin as the Attribution seam: SiteName must init after the
     *  settings overlay in both entrypoints, or pages:build bakes the
     *  config-file name into cached pages. */
    public function test_both_entrypoints_init_the_site_name_after_the_settings_overlay(): void
    {
        foreach (['public/index.php', 'bin/kip'] as $entry) {
            $src = (string) preg_replace('#/\*.*?\*/#s', '',
                (string) file_get_contents(dirname(__DIR__, 4) . '/' . $entry));
            $init = strpos($src, '\App\SiteName::init(');
            $apply = strpos($src, '\App\Settings::apply(');
            $this->assertNotFalse($init, "{$entry} must init the SiteName seam");
            $this->assertGreaterThan((int) $apply, $init, "{$entry} inits SiteName after the settings overlay");
        }
    }

    /** config.php's own comment promises every POST-bearing first segment is
     *  throttled; /settings/save is the board's POST surface. Since the
     *  collapse onto the '*' fallback (kip 0.5.1), an explicit settings
     *  entry is optional: the fallback throttles it at the same budget, so
     *  the contract is "one or the other, with a real limit". */
    public function test_the_shipped_config_throttles_the_settings_save(): void
    {
        $config = require dirname(__DIR__, 4) . '/config.php';
        $bucket = $config['rate_limit']['settings'] ?? $config['rate_limit']['*'] ?? null;
        $this->assertIsArray($bucket, 'rate_limit must throttle /settings/save: an explicit settings entry or the star fallback');
        $this->assertGreaterThan(0, (int) ($bucket['max'] ?? 0));
        $this->assertGreaterThan(0, (int) ($bucket['window'] ?? 0));
    }

    /** The seed plus the admin/member/moderator fixtures (the FeaturesTest idiom). */
    private function seed(): void
    {
        \App\Seeder::run($this->db);
        $hash = \App\Tests\Support\Hashes::of('password123');
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('setadmin@e.test', ?, 'setadmin', 'admin', 1, ?, ?, 'setadmin')",
            [$hash, date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('setmember@e.test', ?, 'setmember', ?, ?, 'setmember')",
            [$hash, date('c'), date('c')]);
        $this->memberUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, email_verified_at, approved_at, profile_slug) VALUES ('setmod@e.test', ?, 'setmoderator', 'moderator', ?, ?, 'setmoderator')",
            [$hash, date('c'), date('c')]);
        $this->moderatorUserId = (int) $this->db->lastInsertId();
    }

    /** The test App config: static_cache + app_dir point INSIDE the temp root,
     *  so the board purge never touches the repo's own cache files. */
    private function config(array $extra = []): array
    {
        return $extra + [
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            // The four config keys the board governs, carried like the real
            // config.php does: overrides only overlay keys the config HAS.
            'registration_mode' => 'verify', 'validation_required' => true, 'items_per_page' => 20,
            'nav_file' => $this->root . '/nav.json',
            'ui_lang' => 'en',
            'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__, 4) . '/app/Features',
            'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'],
        ];
    }

    /** The entrypoint seam, mirrored: Features::init from the same DSN, then
     *  Settings::apply layered over the config before App is built. */
    private function client(?int $as = null, array $extra = []): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        $this->lastApp = new App(\App\Settings::apply($this->config($extra)));
        $client = new TestClient($this->lastApp);
        return $as === null ? $client : $client->actingAs($as);
    }
}
