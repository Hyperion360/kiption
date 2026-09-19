<?php // tests/KudosTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class KudosTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $fanId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-kudos-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        // a second user: kudos notifications target the AUTHOR (user 1),
        // and self-notifications are suppressed, so the actor cannot be the author
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('k2@e.test', ?, 'kudosfan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->fanId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_member_kudos_once_and_notification_fires(): void
    {
        $res = $this->client($this->fanId)->postWithToken('/kudos/add/the-rabbit-hole');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM story_kudos WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole')")['c']);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE kind = 'kudos' AND user_id = 1")['c']);
        $res = $this->client($this->fanId)->postWithToken('/kudos/add/the-rabbit-hole'); // idempotent: no error, no dup
        $this->assertSame(302, $res->status);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM story_kudos WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole')")['c']);
    }

    public function test_guest_kudos_keyed_by_ip(): void
    {
        $client = $this->client();
        $res = $client->post('/kudos/add/the-rabbit-hole'); // no session, headerless: same-origin proof path
        $this->assertSame(302, $res->status, $res->body);
        $res = $client->post('/kudos/add/the-rabbit-hole');
        $this->assertSame(302, $res->status);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM story_kudos k JOIN stories s ON s.id = k.story_id WHERE s.slug = 'the-rabbit-hole' AND k.user_id IS NULL")['c']);
    }

    public function test_story_page_shows_count_and_state(): void
    {
        $this->client($this->fanId)->postWithToken('/kudos/add/the-rabbit-hole');
        $body = $this->client($this->fanId)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Kudos: 1', $body);
        $this->assertStringContainsString('You left kudos', $body);
        $guestBody = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Kudos: 1', $guestBody);
        $this->assertStringNotContainsString('You left kudos', $guestBody);
    }

    public function test_unknown_story_404s(): void
    {
        $this->assertSame(404, $this->client(1)->postWithToken('/kudos/add/nope')->status);
    }

    public function test_self_kudos_count_but_never_notifies(): void
    {
        // the author may leave kudos on their own story, but the
        // no-self-congratulation guard must keep the inbox silent
        $res = $this->client(1)->postWithToken('/kudos/add/the-rabbit-hole');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM story_kudos WHERE user_id = 1 AND story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole')")['c']);
        $this->assertSame(0, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE kind = 'kudos' AND user_id = 1")['c']);
    }

    public function test_guest_kudos_notifies_author_without_actor(): void
    {
        $res = $this->client()->post('/kudos/add/the-rabbit-hole');
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT actor_id, story_title FROM notifications WHERE kind = 'kudos' AND user_id = 1");
        $this->assertNotNull($row);
        $this->assertNull($row['actor_id']); // anonymous reader, the inbox renders "A reader"
        $this->assertSame('The Rabbit Hole', $row['story_title']);
    }
}
