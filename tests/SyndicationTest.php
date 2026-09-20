<?php // tests/SyndicationTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class SyndicationTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-syn-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-syn-test.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-syn-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides);
    }

    private function client(?int $userId = null): TestClient
    {
        $client = new TestClient(new App($this->config()));
        return $userId === null ? $client : $client->actingAs($userId);
    }

    /** The seeded Demo Author, owner of the-rabbit-hole. */
    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    public function test_head_canonical_modes(): void
    {
        $h = \App\Seo\Head::make(siteName: 'Kiption', baseUrl: 'https://archive.example')->withCanonical('/story/view/x');
        $this->assertSame('https://archive.example/story/view/x', $h->canonical());
        // absolute external: used verbatim, never baseUrl-prefixed
        $this->assertSame('https://rr.example/works/1', $h->withCanonicalUrl('https://rr.example/works/1')->canonical());
        // suppressed: the LINK disappears but canonical() (og:url's source) is UNCHANGED (finding 5)
        $this->assertFalse($h->withCanonicalSuppressed()->rendersCanonicalLink());
        $this->assertSame('https://archive.example/story/view/x', $h->withCanonicalSuppressed()->canonical(), 'og:url keeps the self URL');
    }
}
