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

    /** The category natural key is its slug (the tags idiom): a re-run of the
     *  same fandom term adopts the existing row instead of re-inserting under
     *  a suffixed slug, and the database-fed catMap lets a NEW story in a
     *  later run link that same adopted category. */
    public function test_taxonomy_is_idempotent_on_resume(): void
    {
        $db = $this->readyDb();
        $term = ['domain' => 'fcn_fandom', 'nicename' => 'fantasy', 'name' => 'Fantasy'];
        $story = fn(int $id, string $slug): string => Wxr::item(['id' => $id, 'type' => 'fcn_story',
            'slug' => $slug, 'title' => 'Story ' . $id, 'content' => '',
            'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                       'fictioneer_story_chapters' => serialize([])],
            'terms' => [$term]]);
        $a = $this->dir . '/a.xml';
        $b = $this->dir . '/b.xml';
        file_put_contents($a, Wxr::channel([$story(10, 'from-a')]));
        file_put_contents($b, Wxr::channel([$story(20, 'from-b')]));
        $cats = fn(): string => (string) $db->one('SELECT COUNT(*) c FROM categories')['c'];
        $junctions = fn(): string => (string) $db->one("SELECT COUNT(*) c FROM story_categories sc
            JOIN stories s ON s.id = sc.story_id WHERE s.slug IN ('from-a', 'from-b')")['c'];
        $baseline = $cats(); // the seeder's starter category

        $this->import($a, 'commit');
        $afterFirst = $cats();
        $this->assertSame((string) ($baseline + 1), $afterFirst, 'exactly one category for the fandom term');
        $this->assertSame('fantasy', (string) $db->one("SELECT slug FROM categories WHERE name = 'Fantasy'")['slug']);
        $this->assertSame('1', $junctions());

        // the same WXR again: the mapped story skips its junctions, the term
        // adopts the existing category, nothing re-inserts
        $this->import($a, 'commit');
        $this->assertSame($afterFirst, $cats(), 'the fandom term must not re-insert');
        $this->assertSame('fantasy', (string) $db->one("SELECT slug FROM categories WHERE name = 'Fantasy'")['slug'], 'no suffixed -2 slug');
        $this->assertSame('1', $junctions());

        // a NEW story in a later run with the same term links the adopted row
        $this->import($b, 'commit');
        $this->assertSame($afterFirst, $cats());
        $rows = $db->all("SELECT c.slug FROM story_categories sc JOIN categories c ON c.id = sc.category_id
                          JOIN stories s ON s.id = sc.story_id WHERE s.slug IN ('from-a', 'from-b')");
        $this->assertSame([['slug' => 'fantasy'], ['slug' => 'fantasy']], $rows, 'both stories point at the one adopted category');
    }
}
