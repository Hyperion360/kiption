<?php // app/Features/Settings/Tests/SettingsTest.php
namespace App\Features\Settings\Tests;
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
final class SettingsTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminUserId = 0;
    private int $memberUserId = 0;
    private int $moderatorUserId = 0;

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
        ])->status);
        // Rows landed for every key the board writes.
        $this->assertSame(5, (int) $this->db()->one('SELECT COUNT(*) c FROM settings')['c']);
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

    public function test_put_validates_every_key_kind(): void
    {
        \App\Features::init($this->db(), []);
        $bad = [
            ['site_name', ''], ['site_name', str_repeat('x', 61)],
            ['registration_mode', 'casual'],
            ['validation_required', '2'],
            ['items_per_page', '0'], ['items_per_page', '101'], ['items_per_page', 'ten'],
            ['skin', str_repeat('x', 61)], // skin rides the string kind (1-60 chars) per KEYS
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
        $this->assertFileDoesNotExist($this->root . '/app/cache.sqlite', 'framework cache file unlinked');
    }

    /** The seed plus the admin/member/moderator fixtures (the FeaturesTest idiom). */
    private function seed(): void
    {
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
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
        $client = new TestClient(new App(\App\Settings::apply($this->config($extra))));
        return $as === null ? $client : $client->actingAs($as);
    }
}
