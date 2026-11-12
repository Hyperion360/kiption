<?php // tests/Support/TaxonomyCase.php
namespace App\Tests\Support;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Shared fixture for the taxonomy manager tests: a seeded temp database, an
 *  admin and a plain member, and a client acting as either (or as a guest). */
abstract class TaxonomyCase extends TestCase
{
    protected string $path = '';
    protected Database $db;
    protected int $adminId = 0;
    protected int $memberId = 0;
    protected int $authorId = 0;
    protected string $cacheDir = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-tax-') . '.sqlite';
        $this->cacheDir = sys_get_temp_dir() . '/kiption-tax-cache-' . uniqid('', true);
        mkdir($this->cacheDir, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $this->authorId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('taxadmin@e.test', ?, 'taxadmin', 'admin', 1, ?, ?, 'taxadmin')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->adminId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    protected function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 2) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 2) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->path . '.mail', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-tax-upl'],
            'static_cache' => ['dir' => $this->cacheDir],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    protected function admin(): TestClient
    {
        return $this->client($this->adminId);
    }

    /** Drop a stand-in cached page, so a test can tell whether a write purged it. */
    protected function plantCachedPage(): string
    {
        $file = $this->cacheDir . '/planted.html';
        file_put_contents($file, 'cached');
        return $file;
    }

    /** @return array<string,mixed>|null */
    protected function row(string $sql, array $params = []): ?array
    {
        return $this->db->one($sql, $params);
    }

    protected function n(string $sql, array $params = []): int
    {
        return (int) $this->db->one($sql, $params)['c'];
    }
}
