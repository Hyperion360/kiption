<?php // tests/MetadataTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Storage;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    private string $path = '';
    private string $uploads = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-meta-') . '.sqlite';
        $this->uploads = sys_get_temp_dir() . '/kiption-meta-upl-' . bin2hex(random_bytes(4));
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
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
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-meta-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->uploads],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $app->container->instance(Storage::class,
            new Storage($this->uploads, 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn(string $t, string $d): bool => copy($t, $d)));
        return (new TestClient($app))->actingAs(1);
    }

    public function test_language_roundtrip_and_browse_filter(): void
    {
        $rating = (string) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
        $cat = (string) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id'];
        $res = $this->client()->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.',
             'rating_id' => $rating, 'categories' => [$cat], 'language' => 'pt-BR']);
        $this->assertSame(302, $res->status, $res->body);
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('pt-BR', $body);
        $res = (new TestClient(new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-meta-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->uploads],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->get('/browse', ['language' => 'pt-BR']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $res = (new TestClient(new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-meta-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->uploads],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->get('/browse', ['language' => 'fr']);
        $this->assertStringNotContainsString('The Rabbit Hole', $res->body);
    }

    public function test_cover_upload_and_display(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png') . '.png';
        file_put_contents($tmp, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $file = ['name' => 'cover.png', 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
        $res = $this->client()->postWithFile('/story/cover/the-rabbit-hole', [], 'cover', $file);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT cover_path FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->assertMatchesRegularExpression('#^/uploads/[0-9a-f]{16}\.png$#', (string) $row['cover_path']);
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('class="cover"', $body);
    }

    public function test_support_url_edit_and_display(): void
    {
        $res = $this->client()->postWithToken('/account/support', ['support_url' => 'https://ko-fi.example/demo']);
        $this->assertSame(302, $res->status, $res->body);
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Support the author', $body);
        $this->assertStringContainsString('https://ko-fi.example/demo', $body);
        $res = $this->client()->postWithToken('/account/support', ['support_url' => 'javascript:alert(1)']);
        $this->assertSame(422, $res->status); // http/https only
        $res = $this->client()->postWithToken('/account/support', ['support_url' => 'https://' . str_repeat('a', 5000)]);
        $this->assertSame(422, $res->status, 'the form maxlength=200 is client-side only; the server must cap');
        $this->assertLessThanOrEqual(200, (int) $this->db->one('SELECT LENGTH(support_url) n FROM users WHERE id = 1')['n']);
    }
}
