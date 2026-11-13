<?php // app/Features/Account/Tests/PennameChangeTest.php
namespace App\Features\Account\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** A member changes their own pen name from Account settings; the profile
 *  address follows and the old one keeps working as a redirect. */
final class PennameChangeTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-penname-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        $client = new TestClient(new App($extra + [
            'app_dir' => dirname(__DIR__, 4) . '/app', 'env' => 'prod', 'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-pn-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-pn-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    private function member(string $penname, string $role = 'member'): int
    {
        $slug = \App\Slug::make($penname, 'member');
        $this->db->query("INSERT INTO users (email, password_hash, penname, profile_slug, role, email_verified_at, approved_at) VALUES (?, 'x', ?, ?, ?, ?, ?)",
            [uniqid($slug . '-') . '@pn.test', $penname, $slug, $role, date('c'), date('c')]);
        return (int) $this->db->lastInsertId();
    }

    private function rename(TestClient $c, string $name): \Kip\Http\Response
    {
        return $c->postWithToken('/account/penname', ['penname' => $name]);
    }

    private function row(int $id): array
    {
        return $this->db->one('SELECT penname, profile_slug FROM users WHERE id = ?', [$id]);
    }

    public function test_owner_renames_from_owner_to_scribble(): void
    {
        $id = $this->member('Owner', 'admin');
        $r = $this->rename($this->client($id), 'Scribble');
        $this->assertSame(302, $r->status, $r->body);
        $this->assertSame(['penname' => 'Scribble', 'profile_slug' => 'scribble'], $this->row($id));
        $log = $this->db->one("SELECT detail FROM activity_log WHERE action = 'account.penname' ORDER BY id DESC");
        $this->assertStringContainsString('Owner', (string) $log['detail']);
        $this->assertStringContainsString('Scribble', (string) $log['detail']);
    }

    public function test_refuses_a_name_someone_else_has(): void
    {
        $this->member('Lois');
        $id = $this->member('Clark');
        $r = $this->rename($this->client($id), 'lois');
        $this->assertSame(422, $r->status);
        $this->assertSame('Clark', $this->row($id)['penname']);
    }

    public function test_refuses_a_word_filtered_name_without_echoing_the_word(): void
    {
        $this->db->query("INSERT OR IGNORE INTO word_filter (word) VALUES ('casino')");
        $id = $this->member('Clark');
        $r = $this->rename($this->client($id), 'casino king');
        $this->assertSame(422, $r->status);
        $this->assertSame(1, preg_match('#<p id="penname-error"[^>]*>(.*?)</p>#s', $r->body, $m), 'the error paragraph is there');
        $this->assertStringNotContainsString('casino', $m[1]);
        $this->assertStringContainsString('isn&#039;t allowed here', $m[1]);
    }

    public function test_refused_rename_marks_field_invalid_and_keeps_value_and_other_forms(): void
    {
        $this->member('Lois');
        $id = $this->member('Clark');
        $html = $this->rename($this->client($id), 'lois')->body;
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="penname-help penname-error"', $html);
        $this->assertStringContainsString('id="penname-error"', $html);
        $this->assertStringContainsString('value="lois"', $html);
        $this->assertStringContainsString('action="/account/prefs"', $html);
    }

    public function test_a_clean_settings_page_has_no_error_and_shows_the_current_name(): void
    {
        $id = $this->member('Clark');
        $html = $this->client($id)->get('/account/settings')->body;
        $this->assertStringContainsString('id="penname"', $html);
        $this->assertStringContainsString('value="Clark"', $html);
        $this->assertStringNotContainsString('aria-invalid', $html);
        $this->assertStringContainsString('aria-describedby="penname-help"', $html);
    }

    public function test_success_names_the_new_pen_name_from_the_account_not_the_address(): void
    {
        $id = $this->member('Clark');
        $c = $this->client($id);
        $r = $this->rename($c, 'Scribble');
        $this->assertSame('/account/settings?saved=penname', $r->headers['Location']);
        $this->assertStringContainsString('Your pen name is now Scribble.', $c->get('/account/settings', ['saved' => 'penname'])->body);
    }

    public function test_guest_cannot_rename(): void
    {
        $r = $this->client()->postWithToken('/account/penname', ['penname' => 'Anyone']);
        $this->assertNotSame(200, $r->status);
        $this->assertNotSame('/account/settings?saved=penname', $r->headers['Location'] ?? '');
        $this->assertNull($this->db->one("SELECT 1 AS x FROM users WHERE penname = 'Anyone'"));
    }

    public function test_recasing_your_own_name_is_allowed_and_writes_no_history(): void
    {
        $id = $this->member('clark');
        $this->assertSame(302, $this->rename($this->client($id), 'Clark')->status);
        $this->assertSame('Clark', $this->row($id)['penname']);
        $this->assertNull($this->db->one('SELECT 1 AS x FROM profile_slug_history'));
    }

    public function test_old_profile_url_redirects_after_rename(): void
    {
        $id = $this->member('Clark');
        $this->rename($this->client($id), 'Scribble');
        $r = $this->client()->get('/user/view/clark');
        $this->assertSame(301, $r->status);
        $this->assertSame('/user/view/scribble', $r->headers['Location']);
        $this->assertSame('/user/stories/scribble', $this->client()->get('/user/stories/clark')->headers['Location']);
    }

    public function test_an_unknown_slug_is_still_a_404(): void
    {
        $this->assertSame(404, $this->client()->get('/user/view/nobody-here')->status);
    }

    public function test_live_slug_beats_history(): void
    {
        $id = $this->member('Clark');
        $this->rename($this->client($id), 'Scribble');
        $this->member('Clark'); // a later member takes the slug "clark"
        $this->assertSame(200, $this->client()->get('/user/view/clark')->status);
    }

    public function test_rename_back_and_forth_succeeds(): void
    {
        $id = $this->member('Aaron');
        $c = $this->client($id);
        foreach (['Bella', 'Aaron', 'Bella'] as $name) {
            $this->assertSame(302, $this->rename($c, $name)->status, $name);
        }
        $this->assertSame(200, $this->client()->get('/user/view/bella')->status);
        $r = $this->client()->get('/user/view/aaron');
        $this->assertSame(301, $r->status);
        $this->assertSame('/user/view/bella', $r->headers['Location']);
    }

    public function test_slug_reused_by_another_member_redirects_to_latest_owner(): void
    {
        $a = $this->member('Aaron');
        $this->rename($this->client($a), 'Bella');                 // "aaron" -> history, member A
        $b = $this->member('Cleo');
        $cb = $this->client($b);
        $this->rename($cb, 'Aaron');                               // member B takes "aaron"
        $this->rename($cb, 'Dana');                                // "aaron" -> history again, now member B
        $r = $this->client()->get('/user/view/aaron');
        $this->assertSame(301, $r->status);
        $this->assertSame('/user/view/dana', $r->headers['Location']);
    }

    public function test_fourth_rename_in_the_window_is_refused_with_429_and_saves_nothing(): void
    {
        $id = $this->member('Aaron');
        $c = $this->client($id);
        foreach (['Bella', 'Cleo', 'Dana'] as $name) {
            $this->assertSame(302, $this->rename($c, $name)->status, $name);
        }
        $r = $this->rename($c, 'Elsa');
        $this->assertSame(429, $r->status);
        $this->assertStringContainsString('Too many changes in a row.', $r->body);
        $this->assertSame('Dana', $this->row($id)['penname']);
    }

    public function test_settings_basics_points_to_account_settings(): void
    {
        $admin = $this->member('Boss', 'admin');
        $html = $this->client($admin)->get('/settings')->body;
        $this->assertStringContainsString('href="/account/settings#penname"', $html);
    }

    public function test_success_adds_the_live_soon_sentence_only_when_visitors_will_lag(): void
    {
        $note = 'You see it now; visitors may see the old version for up to four hours.';
        $id = $this->member('Clark');
        $plain = $this->client($id)->get('/account/settings', ['saved' => 'penname'])->body;
        $this->assertStringNotContainsString($note, $plain);
        $proxied = $this->client($id, ['trusted_proxy' => true])->get('/account/settings', ['saved' => 'penname'])->body;
        $this->assertStringContainsString('Your pen name is now Clark. ' . $note, $proxied);
        $purged = $this->client($id, ['trusted_proxy' => true, 'cdn' => ['enabled' => true, 'zone_id' => 'z', 'api_token' => 't', 'purge_host' => 'a.example']])
            ->get('/account/settings', ['saved' => 'penname'])->body;
        $this->assertStringNotContainsString($note, $purged);
    }

    public function test_rename_gives_up_cleanly_when_every_numbered_slug_is_taken(): void
    {
        // The storm branch of UserRepository::rename(): Slug::unique exhausts
        // its numbered forms and the member sees the taken-name refusal, with
        // their own name and slug untouched.
        $id = $this->member('Aaron');
        $this->member('Zed'); // holds slug "zed"
        for ($n = 2; $n <= 51; $n++) {
            $this->db->query("INSERT INTO users (email, password_hash, penname, profile_slug, role, email_verified_at, approved_at) VALUES (?, 'x', ?, ?, 'member', ?, ?)",
                ["z$n@pn.test", "Filler $n", "zed-$n", date('c'), date('c')]);
        }
        $users = new \App\Repositories\UserRepository($this->db);
        $this->assertNull($users->rename($id, 'Zed'));
        $this->assertSame('Aaron', $this->row($id)['penname'], 'rolled back');
        $this->assertSame('aaron', $this->row($id)['profile_slug'], 'slug untouched');
    }
}
