<?php // app/Features/Account/Tests/ProfileSaveThrottleTest.php
namespace App\Features\Account\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\CachePurge;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** A member's own profile saves purge the whole public layer (edge included),
 *  so a burst of them is refused instead of hammering the purge quota. */
#[Medium]
final class ProfileSaveThrottleTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;
    private int $purges = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-throttle-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->db->query("INSERT INTO users (email, password_hash, penname, profile_slug, role, email_verified_at, approved_at) VALUES ('thr@e.test', 'x', 'Thrower', 'thrower', 'member', ?, ?)", [date('c'), date('c')]);
        $this->memberId = (int) $this->db->lastInsertId();
        CachePurge::reset();
        CachePurge::$cdnTransport = function (): int { $this->purges++; return 200; };
    }

    protected function tearDown(): void
    {
        CachePurge::$cdnTransport = null;
        CachePurge::reset();
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function client(): TestClient
    {
        return (new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app', 'env' => 'prod', 'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-thr-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-thr-upl'], 'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'static_cache' => ['dir' => sys_get_temp_dir() . '/kiption-thr-static-' . getmypid()],
            'cdn' => ['enabled' => true, 'zone_id' => 'z', 'api_token' => 't', 'purge_host' => 'archive.example'],
        ])))->actingAs($this->memberId);
    }

    public function test_profile_save_burst_is_refused_429_without_purge(): void
    {
        $c = $this->client();
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(302, $c->postWithToken('/account/prefs', ['bio' => "bio $i", 'default_sort' => 'recent'])->status);
        }
        $this->assertGreaterThan(0, $this->purges, 'a saved profile purges the edge');
        CachePurge::reset();
        $before = $this->purges;
        $r = $c->postWithToken('/account/prefs', ['bio' => 'one too many', 'default_sort' => 'recent']);
        $this->assertSame(429, $r->status);
        $this->assertStringContainsString('Too many changes in a row.', $r->body);
        $this->assertSame($before, $this->purges, 'saved nothing, purged nothing');
        $this->assertNotSame('one too many', (string) $this->db->one('SELECT bio FROM users WHERE id = ?', [$this->memberId])['bio']);
    }

    public function test_every_profile_save_shares_the_cap(): void
    {
        $c = $this->client();
        for ($i = 0; $i < 10; $i++) $c->postWithToken('/account/support', ['support_url' => 'https://ko-fi.com/x' . $i]);
        $this->assertSame(429, $c->postWithToken('/account/prefs', ['bio' => 'x', 'default_sort' => 'recent'])->status);
        $this->assertSame(429, $c->postWithToken('/account/fields', [])->status);
        // The avatar upload rides the same cap: with the cap spent it answers
        // 429 (uploads are on by default here) before any file is examined.
        $this->assertSame(429, $c->postWithToken('/account/avatar', [])->status);
    }
}
