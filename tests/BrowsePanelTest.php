<?php // tests/BrowsePanelTest.php
namespace App\Tests;
use App\BrowsePanel;
use Kip\Database;
use PHPUnit\Framework\TestCase;

// The header's Browse panel lists categories with counts and popular tags. It
// reads a JSON artifact (the nav links pattern), so no page pays a content
// query for it; the file is rebuilt on writes, never during a render.
final class BrowsePanelTest extends TestCase
{
    private Database $db;
    private string $path = '';
    private string $dir = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-panel-') . '.sqlite';
        $this->dir = sys_get_temp_dir() . '/kiption-panel-' . uniqid();
        mkdir($this->dir);
        $this->db = new Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        array_map('unlink', glob($this->dir . '/*') ?: []); @rmdir($this->dir);
    }

    public function test_the_artifact_sits_beside_the_nav_file(): void
    {
        $this->assertSame('/d/browse-panel.json', BrowsePanel::fileFor('/d/nav.json'));
        $this->assertSame('', BrowsePanel::fileFor(''), 'no nav file, no artifact');
    }

    public function test_missing_empty_or_corrupt_files_read_as_empty(): void
    {
        $empty = ['categories' => [], 'tags' => []];
        $this->assertSame($empty, BrowsePanel::read(''));
        $this->assertSame($empty, BrowsePanel::read($this->dir . '/nope.json'));
        file_put_contents($this->dir . '/bad.json', '{nope');
        $this->assertSame($empty, BrowsePanel::read($this->dir . '/bad.json'));
    }

    public function test_rebuild_writes_categories_with_counts_and_popular_tags(): void
    {
        $file = $this->dir . '/browse-panel.json';
        BrowsePanel::rebuild($this->db, $file);
        $panel = BrowsePanel::read($file);
        $this->assertNotSame([], $panel['categories']);
        $first = $panel['categories'][0];
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('slug', $first);
        $this->assertIsInt($first['count']);
        $this->assertLessThanOrEqual(BrowsePanel::TAGS, count($panel['tags']));
    }

    public function test_nav_links_rebuild_refreshes_the_panel_too(): void
    {
        $nav = $this->dir . '/nav.json';
        \App\NavLinks::rebuild($this->db, $nav);
        $this->assertFileExists($this->dir . '/browse-panel.json');
    }
}
