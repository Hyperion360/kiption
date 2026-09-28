<?php // tests/SitemapTest.php
namespace App\Tests;
use App\StaticCache\Builder;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class SitemapTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-sitemap-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->root = sys_get_temp_dir() . '/kiption-sitemap-' . uniqid('', true);
        mkdir($this->root . '/public', 0775, true);
    }

    protected function tearDown(): void
    {
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function publicDir(): string
    {
        return $this->root . '/public';
    }

    public function test_sitemap_index_and_segments(): void
    {
        $dir = $this->publicDir();
        $n = \App\Seo\Sitemap::writeAll($this->db(), $dir, 'https://archive.example', []);
        $this->assertSame(7, $n, 'stories-1 + authors + categories + series + pages + news + the index');
        $this->assertFileExists($dir . '/sitemap.xml');
        $idx = (string) file_get_contents($dir . '/sitemap.xml');
        $this->assertStringContainsString('<sitemapindex', $idx);
        $this->assertStringContainsString('sitemap-stories-1.xml', $idx);
        $this->assertStringContainsString('sitemap-categories.xml', $idx);
        foreach (['sitemap-stories-1.xml', 'sitemap-authors.xml', 'sitemap-categories.xml',
                  'sitemap-series.xml', 'sitemap-pages.xml', 'sitemap-news.xml'] as $f) {
            $this->assertFileExists($dir . '/' . $f, $f);
        }
        $this->assertNotFalse(simplexml_load_string($idx), 'the index is well-formed XML');
        $stories = (string) file_get_contents($dir . '/sitemap-stories-1.xml');
        $this->assertNotFalse(simplexml_load_string($stories), 'the segment is well-formed XML');
        $this->assertStringContainsString('https://archive.example/story/view/the-rabbit-hole', $stories);
        $this->assertStringContainsString('https://archive.example/story/read/the-rabbit-hole/2', $stories, 'each validated read URL rides its story segment');
        $this->assertStringContainsString('<lastmod>', $stories);
        // the directory gate: profile pages of approvable members only
        $authors = (string) file_get_contents($dir . '/sitemap-authors.xml');
        $this->assertStringContainsString('/user/view/demo-author', $authors);
        $this->assertStringContainsString('/user/view/betafriend', $authors);
        // external-canonical stories deindex locally and leave the sitemap
        $this->db()->query("UPDATE stories SET canonical_url = 'https://rr.example/works/9' WHERE slug = 'after-hours'");
        \App\Seo\Sitemap::writeAll($this->db(), $dir, 'https://archive.example', []);
        $stories = (string) file_get_contents($dir . '/sitemap-stories-1.xml');
        $this->assertStringNotContainsString('/story/view/after-hours', $stories);
        $this->assertStringNotContainsString('/story/read/after-hours/1', $stories);
        // gates: restricted/unvalidated/deleted excluded; the Builder's WHERE verbatim
        $this->db()->query("UPDATE stories SET canonical_url = NULL, is_restricted = 1 WHERE slug = 'after-hours'");
        \App\Seo\Sitemap::writeAll($this->db(), $dir, 'https://archive.example', []);
        $this->assertStringNotContainsString('/story/view/after-hours', (string) file_get_contents($dir . '/sitemap-stories-1.xml'));
        // restore after-hours so each gate stays observable one at a time
        $this->db()->query("UPDATE stories SET is_restricted = 0 WHERE slug = 'after-hours'");
        $this->db()->query("UPDATE stories SET validated = 0 WHERE slug = 'the-rabbit-hole'");
        \App\Seo\Sitemap::writeAll($this->db(), $dir, 'https://archive.example', []);
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', (string) file_get_contents($dir . '/sitemap-stories-1.xml'), 'unvalidated excluded');
        $this->assertStringContainsString('/story/view/after-hours', (string) file_get_contents($dir . '/sitemap-stories-1.xml'));
        // with the last gated story deleted the segment is empty: skipped and unlisted
        $this->db()->query("UPDATE stories SET deleted_at = '2026-09-18T00:00:00Z' WHERE slug = 'after-hours'");
        \App\Seo\Sitemap::writeAll($this->db(), $dir, 'https://archive.example', []);
        $this->assertFileDoesNotExist($dir . '/sitemap-stories-1.xml', 'empty segments are skipped');
        $this->assertStringNotContainsString('sitemap-stories-1.xml', (string) file_get_contents($dir . '/sitemap.xml'));
        $this->assertStringContainsString('sitemap-authors.xml', (string) file_get_contents($dir . '/sitemap.xml'), 'the index lists what was written');
    }

    public function test_stories_split_at_a_lowered_segment_seam(): void
    {
        $dir = $this->publicDir();
        // 6 story URLs on the seed (rabbit view + 3 reads, after-hours view + 1
        // read); the default 45,000 seam is a constant, so the split math is
        // asserted at a lowered value through the parameter (finding 13).
        $n = \App\Seo\Sitemap::writeAll($this->db(), $dir, 'https://archive.example', [], 2);
        $this->assertSame(9, $n, '3 story segments + authors + categories + series + pages + news + the index');
        foreach (['sitemap-stories-1.xml', 'sitemap-stories-2.xml', 'sitemap-stories-3.xml'] as $f) {
            $this->assertFileExists($dir . '/' . $f, $f);
        }
        $this->assertFileDoesNotExist($dir . '/sitemap-stories-4.xml');
        $idx = (string) file_get_contents($dir . '/sitemap.xml');
        $this->assertSame(3, substr_count($idx, 'sitemap-stories-'), 'every segment is listed in the index');
        // a smaller rebuild leaves no stale segment behind
        \App\Seo\Sitemap::writeAll($this->db(), $dir, 'https://archive.example', []);
        $this->assertFileDoesNotExist($dir . '/sitemap-stories-2.xml');
        $this->assertFileExists($dir . '/sitemap-stories-1.xml');
    }

    public function test_builder_build_writes_sitemap_and_robots_into_the_public_dir(): void
    {
        $cacheDir = $this->root . '/cache';
        $count = Builder::build([
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'app_dir' => dirname(__DIR__) . '/app',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/uploads'],
            'base_url' => 'https://archive.example',
            'public_dir' => $this->publicDir(),
            'ai_crawlers' => false,
        ], $cacheDir);
        // the BuilderTest count pin applies here too (Task 3: the challenges
        // index joins the enumeration; sitemaps stay files, not cache pages)
        $this->assertSame(22, $count);
        $this->assertFileExists($this->publicDir() . '/sitemap.xml');
        $this->assertStringContainsString(
            'https://archive.example/story/view/the-rabbit-hole',
            (string) file_get_contents($this->publicDir() . '/sitemap-stories-1.xml'));
        $this->assertStringContainsString('User-agent: GPTBot',
            (string) file_get_contents($this->publicDir() . '/robots.txt'),
            'the build honors the ai_crawlers toggle');
        $this->assertFileDoesNotExist($cacheDir . '/sitemap.xml',
            'finding 11: sitemaps land in public_dir, never the cache dir');
    }
}
