<?php // tests/PagesNavTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class PagesNavTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-nav-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-nav-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    private function db(): Database
    {
        return $this->db;
    }

    public function test_nav_artifact_rebuilds_and_reads(): void
    {
        $file = $this->root . '/nav.json';
        $db = $this->db();
        $this->assertSame([], \App\NavLinks::all($file), 'missing file reads empty');
        $this->assertSame([], \App\NavLinks::all(''), 'empty path reads empty (review blocker 1: file_get_contents would throw)');
        $db->query('INSERT INTO nav_links (label, url, position, is_hidden) VALUES (?,?,?,?)', ['About', '/page/about', 1, 0]);
        $db->query('INSERT INTO nav_links (label, url, position, is_hidden) VALUES (?,?,?,?)', ['Hidden', '/page/x', 2, 1]);
        \App\NavLinks::rebuild($db, $file);
        $this->assertSame([['label' => 'About', 'url' => '/page/about']], \App\NavLinks::all($file), 'hidden links skipped, ordered');
        // corrupt file reads empty, never errors
        file_put_contents($file, '{not json');
        $this->assertSame([], \App\NavLinks::all($file));
    }
}
