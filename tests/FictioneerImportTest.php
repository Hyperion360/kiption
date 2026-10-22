<?php // tests/FictioneerImportTest.php
namespace App\Tests;
use App\Import\FictioneerImport;
use App\Import\WxrReader;
use App\Tests\Support\Wxr;
use Kip\Database;
use PHPUnit\Framework\TestCase;

/** Drives the REAL Fictioneer orchestrator end to end: a throwaway sqlite
 *  file, Migrator + Seeder, in-process runs, a real config array with
 *  throwaway dirs. Task 7 landed the harness and the oneshot test; the
 *  rest of the era matrix arrives with Task 9. */
final class FictioneerImportTest extends TestCase
{
    private string $dir = '';
    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kiption-fximp-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0777, true);
        $this->dbPath = $this->dir . '/db.sqlite';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function db(): Database
    {
        return new Database('sqlite:' . $this->dbPath);
    }

    private function readyDb(): Database
    {
        $db = $this->db();
        (new \Kip\Migrations\Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        return $db;
    }

    private function config(): array
    {
        return [
            'db' => ['dsn' => 'sqlite:' . $this->dbPath],
            'static_cache' => ['dir' => $this->dir . '/cache', 'enabled' => false],
            'nav_file' => '',
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->dir . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->dir . '/uploads'],
            'app_dir' => dirname(__DIR__) . '/app',
            'views' => dirname(__DIR__) . '/app/views',
            'env' => 'prod',
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ];
    }

    private function write(string $xml): string
    {
        $p = $this->dir . '/export.xml';
        file_put_contents($p, $xml);
        return $p;
    }

    private function import(string $path, string $mode = 'commit', ?int $owner = null): string
    {
        return (new FictioneerImport($this->db(), new WxrReader($path), $mode, $owner, $this->config()))->run();
    }

    public function test_oneshot_story_prose_becomes_chapter_one(): void
    {
        $db = $this->readyDb();
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'one-shot', 'title' => 'One Shot',
                'content' => '<p>The whole tale.</p>',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Oneshot']]),
        ]);
        $p = $this->write($xml);
        $out = $this->import($p, 'commit');
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'one-shot')")['c']);
        $this->assertSame('1', (string) $db->one("SELECT completed FROM stories WHERE slug = 'one-shot'")['completed']);
        $this->assertStringContainsString('oneshot stories (story prose became chapter 1): 1', $out);
    }
}
