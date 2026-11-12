<?php // app/Features/Profilefields/Tests/ProfilefieldsTest.php
namespace App\Features\Profilefields\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Owner-defined author profile fields: the admin screen, the member form, the profile page. */
final class ProfilefieldsTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-pf-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->adminId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        \App\Adminness::setRole($this->db, $this->adminId, 'admin');
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null): TestClient
    {
        $c = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-pf-mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-pf-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        return $as === null ? $c : $c->actingAs($as);
    }

    private function admin(): TestClient { return $this->client($this->adminId); }
    private function member(): TestClient { return $this->client($this->memberId); }

    private function addField(string $label, string $type): int
    {
        $this->assertSame(302, $this->admin()->postWithToken('/profilefields/add', ['label' => $label, 'type' => $type])->status);
        return (int) $this->db->one('SELECT id FROM profile_fields WHERE label = ?', [$label])['id'];
    }

    private function labels(): array
    {
        return array_column($this->db->all('SELECT label FROM profile_fields ORDER BY position, id'), 'label');
    }

    private function profile(): string
    {
        return $this->client()->get('/user/view/demo-author')->body;
    }

    public function test_admin_adds_renames_and_lists_fields(): void
    {
        $id = $this->addField('Website', 'url');
        $this->assertStringContainsString('Website', $this->admin()->get('/profilefields')->body);
        $this->assertSame(302, $this->admin()->postWithToken('/profilefields/rename/' . $id, ['label' => 'My site'])->status);
        $this->assertSame(['My site'], $this->labels());
    }

    public function test_the_screen_is_admin_only_and_writes_need_a_token(): void
    {
        $this->assertSame(403, $this->client()->get('/profilefields')->status, 'guest');
        $this->assertSame(403, $this->member()->get('/profilefields')->status, 'member');
        $this->assertSame(403, $this->member()->postWithToken('/profilefields/add', ['label' => 'X', 'type' => 'text'])->status);
        $this->assertSame([], $this->labels());
        $res = $this->admin()->post('/profilefields/add', ['label' => 'X', 'type' => 'text'], ['sec-fetch-site' => 'cross-site']);
        $this->assertSame(403, $res->status);
        $this->assertSame([], $this->labels());
    }

    public function test_bad_labels_and_types_are_refused(): void
    {
        $this->assertSame(422, $this->admin()->postWithToken('/profilefields/add', ['label' => '  ', 'type' => 'text'])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/profilefields/add', ['label' => 'Ok', 'type' => 'html'])->status);
        $this->assertSame([], $this->labels());
    }

    public function test_reordering_moves_a_field_and_the_ends_stay_put(): void
    {
        $a = $this->addField('A', 'text');
        $b = $this->addField('B', 'text');
        $c = $this->addField('C', 'text');
        $this->admin()->postWithToken('/profilefields/move/' . $c, ['dir' => 'up']);
        $this->assertSame(['A', 'C', 'B'], $this->labels());
        $this->admin()->postWithToken('/profilefields/move/' . $a, ['dir' => 'up']);
        $this->assertSame(['A', 'C', 'B'], $this->labels(), 'first stays first');
        $this->admin()->postWithToken('/profilefields/move/' . $a, ['dir' => 'down']);
        $this->assertSame(['C', 'A', 'B'], $this->labels());
        $this->assertSame(422, $this->admin()->postWithToken('/profilefields/move/' . $b, ['dir' => 'sideways'])->status);
    }

    public function test_delete_asks_first_then_removes_the_field_and_its_answers(): void
    {
        $id = $this->addField('Pronouns', 'text');
        $this->member()->postWithToken('/account/fields', ['field' => [$id => 'she/her']]);
        $this->assertStringContainsString('she/her', $this->profile());
        $confirm = $this->admin()->get('/profilefields/delete/' . $id);
        $this->assertSame(200, $confirm->status);
        $this->assertStringContainsString('action="/profilefields/destroy/' . $id . '"', $confirm->body);
        $this->assertNotNull($this->db->one('SELECT 1 AS x FROM profile_fields WHERE id = ?', [$id]), 'opening the page deletes nothing');
        $this->assertSame(403, $this->member()->postWithToken('/profilefields/destroy/' . $id)->status);
        $this->assertSame(302, $this->admin()->postWithToken('/profilefields/destroy/' . $id)->status);
        $this->assertNull($this->db->one('SELECT 1 AS x FROM profile_fields WHERE id = ?', [$id]));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM profile_field_values')['c']);
        $this->assertStringNotContainsString('she/her', $this->profile());
        $this->assertSame(404, $this->admin()->get('/profilefields/delete/9999')->status);
    }

    public function test_a_member_fills_fields_and_they_show_on_the_profile(): void
    {
        $t = $this->addField('Pronouns', 'text');
        $u = $this->addField('Website', 'url');
        $y = $this->addField('Open to requests', 'yesno');
        $page = $this->member()->get('/account/settings')->body;
        foreach (['Pronouns', 'Website', 'Open to requests'] as $label) $this->assertStringContainsString($label, $page);
        $res = $this->member()->postWithToken('/account/fields', ['field' => [$t => 'she/her', $u => 'https://example.test/me', $y => '1']]);
        $this->assertSame(302, $res->status, $res->body);
        $profile = $this->profile();
        $this->assertStringContainsString('she/her', $profile);
        $this->assertStringContainsString('<a href="https://example.test/me" rel="noopener nofollow">', $profile);
        $this->assertStringContainsString('Open to requests', $profile);
    }

    public function test_javascript_and_other_unsafe_links_are_rejected(): void
    {
        $u = $this->addField('Website', 'url');
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<b>x</b>', 'ftp://example.test/x', '//example.test', 'http://', 'https://exa mple.test', "https://example.test/\"onmouseover=\"x"] as $bad) {
            $res = $this->member()->postWithToken('/account/fields', ['field' => [$u => $bad]]);
            $this->assertSame(422, $res->status, $bad);
            $this->assertStringContainsString('must start with http', $res->body);
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM profile_field_values')['c']);
    }

    public function test_an_unsafe_link_that_got_in_another_way_is_not_rendered_as_a_link(): void
    {
        $u = $this->addField('Website', 'url');
        $this->db->query('INSERT INTO profile_field_values (user_id, field_id, value) VALUES (?, ?, ?)', [$this->memberId, $u, 'javascript:alert(1)']);
        $profile = $this->profile();
        $this->assertStringNotContainsString('javascript:', $profile);
    }

    public function test_text_values_are_escaped_and_one_bad_value_saves_nothing(): void
    {
        $t = $this->addField('Pronouns', 'text');
        $u = $this->addField('Website', 'url');
        $this->member()->postWithToken('/account/fields', ['field' => [$t => '<script>alert(1)</script>']]);
        $profile = $this->profile();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $profile);
        $this->assertStringContainsString('&lt;script&gt;', $profile);
        $res = $this->member()->postWithToken('/account/fields', ['field' => [$t => 'changed', $u => 'javascript:x']]);
        $this->assertSame(422, $res->status);
        $this->assertStringNotContainsString('changed</', $this->profile(), 'a refused form saved none of it');
    }

    public function test_clearing_a_value_removes_it_and_an_unchecked_box_turns_it_off(): void
    {
        $t = $this->addField('Pronouns', 'text');
        $y = $this->addField('Open to requests', 'yesno');
        $this->member()->postWithToken('/account/fields', ['field' => [$t => 'she/her', $y => '1']]);
        $this->member()->postWithToken('/account/fields', ['field' => [$t => '']]);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM profile_field_values')['c']);
        $this->assertStringNotContainsString('Open to requests', $this->profile());
    }

    public function test_account_fields_needs_login_and_a_token(): void
    {
        $t = $this->addField('Pronouns', 'text');
        $this->assertSame(302, $this->client()->postWithToken('/account/fields', ['field' => [$t => 'x']])->status);
        $this->assertSame(403, $this->member()->post('/account/fields', ['field' => [$t => 'x']])->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM profile_field_values')['c']);
    }

    public function test_an_unknown_field_id_in_the_form_is_ignored(): void
    {
        $t = $this->addField('Pronouns', 'text');
        $this->member()->postWithToken('/account/fields', ['field' => [$t => 'ok', 9999 => 'ghost']]);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM profile_field_values')['c']);
    }
}
