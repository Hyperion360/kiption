<?php // app/Features/Story/Tests/GiftMetadataTest.php
namespace App\Features\Story\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class GiftMetadataTest extends TestCase
{
    private string $path = '';
    private string $cacheDir = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-gift-') . '.sqlite';
        $this->cacheDir = sys_get_temp_dir() . '/kiption-gift-cfg-' . uniqid('', true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
    }

    private function newApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-gift-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-gift-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            // Config-injected static cache dir: the update POST's purge writes
            // land here, never in the repo's public/cache.
            'static_cache' => ['dir' => $this->cacheDir],
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    public function test_gift_and_round_robin_fields_round_trip(): void
    {
        $me = $this->client($this->authorId());
        $res = $me->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'rating_id' => 2, 'categories' => [1],
             'round_robin' => '1', 'gift_to' => 'A friend who fell first']);
        $this->assertSame(302, $res->status, $res->body);
        $db = $this->db();
        $row = $db->one("SELECT round_robin, gift_to FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(1, (int) $row['round_robin']);
        $this->assertSame('A friend who fell first', $row['gift_to']);
        // clamped; junk coerces
        $me->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'rating_id' => 2, 'categories' => [1],
             'gift_to' => str_repeat('x', 200)]); // round_robin key OMITTED - the real unchecked-checkbox shape (finding 8)
        $row2 = $db->one("SELECT round_robin, gift_to FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(0, (int) $row2['round_robin']);
        $this->assertSame(120, strlen((string) $row2['gift_to']));
        // display: the gift line renders when set (a story.gift_line lang key - finding 18, the scan rejects hardcoded text nodes)
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('A gift for', $body);
        $this->assertStringContainsString('xx', $body);
        $this->assertStringNotContainsString('A gift for', $this->client()->get('/story/view/after-hours')->body);
    }

    public function test_three_new_flags_register_everywhere(): void
    {
        $this->assertContains('challenges', array_keys(\App\Features::INVENTORY));
        $this->assertContains('releases', array_keys(\App\Features::INVENTORY));
        $this->assertContains('roundrobin', array_keys(\App\Features::INVENTORY));
        $this->assertStringContainsString('features.challenges.desc', (string) file_get_contents(dirname(__DIR__, 4) . '/app/lang/en.php'));
    }
}
