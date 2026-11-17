<?php // tests/PurgeSeamsTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\CachePurge;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Every admin save that changes public pages ends in the one shared purge
 *  set, so the CDN's copy goes too, not just the local files. */
#[Medium]
final class PurgeSeamsTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-seams-');
        $this->root = sys_get_temp_dir() . '/kiption-seams-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('seams@e.test', 'x', 'seamsadmin', 'admin', 1, ?, ?, 'seamsadmin')", [date('c'), date('c')]);
        $this->adminId = (int) $this->db->lastInsertId();
        CachePurge::reset();
        CachePurge::$cdnTransport = function (): int { $this->calls++; return 200; };
    }

    protected function tearDown(): void
    {
        CachePurge::$cdnTransport = null;
        CachePurge::reset();
        \App\Features::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function admin(?array $cdn = null, array $extra = []): TestClient
    {
        return (new TestClient(new App($extra + [
            'app_dir' => $this->root . '/app', 'features_dir' => dirname(__DIR__) . '/app/Features', 'env' => 'prod', 'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'], 'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json', 'static_cache' => ['dir' => $this->root . '/static'],
            'cdn' => $cdn ?? ['enabled' => true, 'zone_id' => 'z', 'api_token' => 't', 'purge_host' => 'archive.example'],
        ])))->actingAs($this->adminId);
    }

    /** One logical save per call, the way PHP-FPM gives each request fresh state. */
    private function purgesFrom(callable $save): int
    {
        CachePurge::reset();
        $before = $this->calls;
        $r = $save();
        if ($r instanceof \Kip\Http\Response) $this->assertSame(302, $r->status, substr($r->body, 0, 300));
        return $this->calls - $before;
    }

    public function test_nav_category_tag_and_wrangling_saves_each_purge_the_edge(): void
    {
        $admin = $this->admin();
        $saves = [
            'nav create' => fn () => $admin->postWithToken('/nav/create', ['label' => 'Rules', 'url' => '/rules']),
            'category create' => fn () => $admin->postWithToken('/categories/create', ['name' => 'Smallville']),
            'tag type add' => fn () => $admin->postWithToken('/tags/addtype', ['name' => 'Relationships']),
            'wrangling unmerge' => function () use ($admin) {
                $id = (int) $this->db->one('SELECT id FROM tags ORDER BY id LIMIT 1')['id'];
                return $admin->postWithToken('/wrangling/unmerge/' . $id);
            },
        ];
        foreach ($saves as $name => $save) {
            $this->assertSame(1, $this->purgesFrom($save), $name . ' queues exactly one edge purge');
        }
    }

    public function test_nav_delete_purges_the_edge(): void
    {
        $admin = $this->admin();
        $admin->postWithToken('/nav/create', ['label' => 'Rules', 'url' => '/rules']);
        $id = (int) $this->db->one('SELECT id FROM nav_links ORDER BY id DESC LIMIT 1')['id'];
        $this->assertSame(1, $this->purgesFrom(fn () => $admin->postWithToken('/nav/delete/' . $id, ['confirmed' => '1'])));
    }

    public function test_deleting_a_member_purges_the_edge(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, email_verified_at, approved_at, profile_slug) VALUES ('gone@e.test', 'x', 'gone', 'member', ?, ?, 'gone')", [date('c'), date('c')]);
        $id = (int) $this->db->lastInsertId();
        $admin = $this->admin();
        $this->assertSame(1, $this->purgesFrom(fn () => $admin->postWithToken('/adminmembers/destroy/' . $id, ['stories' => 'remove'])));
    }

    public function test_saves_say_when_visitors_will_see_the_change(): void
    {
        $off = ['enabled' => false];
        $save = fn (TestClient $c) => $c->postWithToken('/nav/create', ['label' => 'Rules', 'url' => '/rules'])->headers['Location'];
        CachePurge::reset();
        $this->assertStringContainsString('saved=added', $save($this->admin($off)), 'no CDN in front: plain line');
        CachePurge::reset();
        $this->assertStringContainsString('saved=live_soon', $save($this->admin($off, ['trusted_proxy' => true])), 'a proxy but no purge: say so');
        CachePurge::reset();
        $this->assertStringContainsString('saved=added', $save($this->admin(null, ['trusted_proxy' => true])), 'purging is configured: plain line');
        CachePurge::reset();
        $tag = $this->admin($off, ['trusted_proxy' => true])->postWithToken('/tags/addtype', ['name' => 'Genres']);
        $this->assertStringContainsString('saved=live_soon', $tag->headers['Location']);
        $this->assertStringContainsString('type=', $tag->headers['Location']);
    }

    public function test_the_live_soon_line_renders_from_its_fixed_code(): void
    {
        CachePurge::reset();
        $admin = $this->admin(['enabled' => false], ['trusted_proxy' => true]);
        $loc = $admin->postWithToken('/nav/create', ['label' => 'Rules', 'url' => '/rules'])->headers['Location'];
        $this->assertStringContainsString('Saved. You see it now; visitors may see the old version for up to four hours.', $admin->get('/nav', ['saved' => 'live_soon'])->body);
        $this->assertStringContainsString('live_soon', $loc);
    }
}
