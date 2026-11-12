<?php // app/Features/Activity/Tests/ActivityTest.php
namespace App\Features\Activity\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The owner's activity log: admins see it, members do not, and admin actions write to it. */
final class ActivityTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-act-') . '.sqlite';
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
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-act-mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-act-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'items_per_page' => 2,
        ]));
        return $as === null ? $c : $c->actingAs($as);
    }

    public function test_only_admins_can_read_the_log(): void
    {
        $this->assertSame(200, $this->client($this->adminId)->get('/activity')->status);
        $this->assertSame(403, $this->client($this->memberId)->get('/activity')->status);
        $this->assertSame(403, $this->client()->get('/activity')->status);
    }

    public function test_admin_actions_are_recorded_and_shown_newest_first(): void
    {
        $admin = $this->client($this->adminId);
        $admin->postWithToken('/invites/create');
        $admin->postWithToken('/profilefields/add', ['label' => 'Pronouns', 'type' => 'text']);
        $rows = $this->db->all('SELECT actor_id, actor_name, action, detail FROM activity_log ORDER BY id');
        $this->assertSame(['invites.create', 'profilefields.add'], array_column($rows, 'action'));
        $this->assertSame($this->adminId, (int) $rows[0]['actor_id']);
        $this->assertSame('betafriend', $rows[0]['actor_name']);
        $body = $admin->get('/activity')->body;
        $this->assertLessThan(strpos($body, 'Made an invite code'), strpos($body, 'Added a profile field'));
        $this->assertStringContainsString('Pronouns', $body);
    }

    public function test_a_refused_action_leaves_no_line(): void
    {
        $this->client($this->memberId)->postWithToken('/invites/create');
        $this->client($this->adminId)->postWithToken('/profilefields/add', ['label' => '', 'type' => 'text']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM activity_log')['c']);
    }

    public function test_the_log_escapes_what_was_typed_and_pages(): void
    {
        \App\ActivityLog::record($this->db, $this->adminId, 'profilefields.add', '<script>alert(1)</script>');
        for ($i = 0; $i < 3; $i++) \App\ActivityLog::record($this->db, $this->adminId, 'invites.create');
        $admin = $this->client($this->adminId);
        $first = $admin->get('/activity')->body;
        $this->assertStringContainsString('rel="next"', $first);
        $this->assertStringNotContainsString('<script>alert(1)', $first);
        $second = $admin->get('/activity', ['page' => '2'])->body;
        $this->assertStringContainsString('&lt;script&gt;', $second);
        $this->assertStringContainsString('rel="prev"', $second);
    }

    public function test_the_name_survives_the_account_going_away(): void
    {
        \App\ActivityLog::record($this->db, $this->memberId, 'invites.create');
        $this->db->query('DELETE FROM users WHERE id = ?', [$this->memberId]);
        $row = $this->db->one('SELECT actor_id, actor_name FROM activity_log');
        $this->assertNull($row['actor_id']);
        $this->assertSame('Demo Author', $row['actor_name']);
        $this->assertStringContainsString('Demo Author', $this->client($this->adminId)->get('/activity')->body);
    }

    public function test_record_never_throws(): void
    {
        $this->db->query('DROP TABLE activity_log');
        \App\ActivityLog::record($this->db, $this->adminId, 'invites.create');
        $this->assertSame(302, $this->client($this->adminId)->postWithToken('/invites/create')->status);
    }
}
