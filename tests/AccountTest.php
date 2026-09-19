<?php // tests/AccountTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Storage;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    private string $path = '';
    private string $uploads = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-acct-') . '.sqlite';
        $this->uploads = sys_get_temp_dir() . '/kiption-upl-' . bin2hex(random_bytes(4));
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        foreach (glob($this->uploads . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->uploads);
    }

    private function client(): TestClient
    {
        $app = new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        // CLI has no SAPI uploads: swap the mover for copy(), the seam Storage ships for this.
        $app->container->instance(Storage::class,
            new Storage($this->uploads, 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn(string $t, string $d): bool => copy($t, $d)));
        return (new TestClient($app))->actingAs(1);
    }

    private function pngFile(string $name = 'me.png'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png') . '.png';
        file_put_contents($tmp, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    public function test_account_requires_login(): void
    {
        $app = new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ]);
        $res = (new TestClient($app))->get('/account');
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location']);
    }

    public function test_account_lists_my_stories_with_status(): void
    {
        $res = $this->client()->get('/account');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Demo Author', $res->body);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('name="robots" content="noindex"', $res->body);
    }

    public function test_avatar_upload_stores_and_replaces(): void
    {
        $client = $this->client();
        $res = $client->postWithFile('/account/avatar', [], 'avatar', $this->pngFile());
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one('SELECT avatar_path FROM users WHERE id = 1');
        $this->assertMatchesRegularExpression('#^/uploads/[0-9a-f]{16}\.png$#', (string) $row['avatar_path']);
        $first = $row['avatar_path'];
        $res = $client->postWithFile('/account/avatar', [], 'avatar', $this->pngFile());
        $row = $this->db->one('SELECT avatar_path FROM users WHERE id = 1');
        $this->assertNotSame($first, $row['avatar_path']);
        $this->assertFileDoesNotExist($this->uploads . basename((string) $first));
    }

    public function test_avatar_rejects_wrong_content(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fake') . '.png';
        file_put_contents($tmp, '<?php echo "not a png";');
        $file = ['name' => 'evil.png', 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
        $res = $this->client()->postWithFile('/account/avatar', [], 'avatar', $file);
        $this->assertSame(422, $res->status);
        $this->assertNull($this->db->one('SELECT avatar_path FROM users WHERE id = 1')['avatar_path']);
    }
}
