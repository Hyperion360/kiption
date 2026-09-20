<?php // tests/ImporterTest.php
namespace App\Tests;
use App\Import\Importer;
use Kip\Database;
use PHPUnit\Framework\TestCase;

/** Drives the REAL Importer class in-process (the CLI tests shell out to
 *  bin/kip, which pcov cannot see): coverage for the orchestration plus
 *  regression pins for the QA-found resume and rating-fallback bugs. */
final class ImporterTest extends TestCase
{
    private string $dbPath = '';
    private string $cacheDir = '';
    /** @var string[] every temp root created (resume tests build two bundles) */
    private array $roots = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/tests/support/EfictionInstall.php';
        $this->dbPath = tempnam(sys_get_temp_dir(), 'kiption-impcov-db-') . '.sqlite';
        $this->cacheDir = $this->newRoot() . '/cache';
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $r) { exec('rm -rf ' . escapeshellarg($r)); }
        @unlink($this->dbPath); @unlink($this->dbPath . '-wal'); @unlink($this->dbPath . '-shm');
        @unlink(substr($this->dbPath, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    private function newRoot(): string
    {
        $r = sys_get_temp_dir() . '/kiption-impcov-' . uniqid('', true);
        mkdir($r . '/src', 0775, true);
        return $this->roots[] = $r;
    }

    /** Builds a bundle from a fresh fixture, after optionally extending its
     *  rows (the callback receives the fixture before the shims install). */
    private function bundle(?callable $extend = null): string
    {
        $root = $this->newRoot();
        $fx = new \EfictionInstall($root . '/src');
        if ($extend !== null) $extend($fx, $root);
        $fx->installShims();
        if (!defined('PHPUNIT_KIP_TEST')) define('PHPUNIT_KIP_TEST', true);
        require_once dirname(__DIR__) . '/resources/efiction-export.php';
        $token = str_repeat('a', 64);
        file_put_contents($root . '/src/export-token.php', "<?php return '" . hash('sha256', $token) . "';");
        \EfictionExporter::runner($root . '/src', 'fxs_', $token, true, true, false, true);
        foreach (glob($root . '/src/out-*/kiption-export.tar.gz') as $p) return $p;
        $this->fail('bundle not built');
    }

    private function config(): array
    {
        return [
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->dbPath],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->roots[0] . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->roots[0] . '/uploads'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'static_cache' => ['enabled' => true, 'dir' => $this->cacheDir],
        ];
    }

    private function migrate(): void
    {
        (new \Kip\Migrations\Migrator($this->db(), dirname(__DIR__) . '/app/migrations'))->migrate();
    }

    private function db(): Database
    {
        return new Database('sqlite:' . $this->dbPath);
    }

    private function import(string $bundle, string $mode, string $encoding = 'auto', bool $allowMissing = false): string
    {
        return (new Importer($this->db(), new \App\Import\BundleReader($bundle), $mode, $encoding, $allowMissing, $this->config()))->run();
    }

    public function test_unresolvable_rating_falls_back_to_unrated(): void
    {
        $this->migrate();
        $b = $this->bundle(function (\EfictionInstall $fx): void {
            $p = $fx->prefix;
            // a real rating exists, but story 9's rid resolves to nothing
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_ratings (rid, rating, ratingwarning, warningtext) VALUES (5, 'Teen', '0', '')");
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_stories (sid, title, summary, catid, classes, rid, date, updated, uid, validated, completed, wordcount, count) VALUES (9, 'Unrated Story', 'x', '0', '0', '0', '2011-01-01 00:00:00', '2012-01-01 00:00:00', 1, '1', '0', 1, 0)");
        });
        $out = $this->import($b, 'commit');
        $db = $this->db();
        $row = $db->one('SELECT r.label FROM stories s JOIN ratings r ON r.id = s.rating_id WHERE s.title = ?', ['Unrated Story']);
        $this->assertSame('Unrated', $row['label'], 'a story whose rid resolves to nothing must fall back to Unrated, not crash');
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM ratings')['c'], 'Teen plus the on-demand Unrated row');
        $this->assertStringContainsString('dropped rating fallback: Unrated: 1', $out);
        $this->assertStringContainsString('fanfiction_ratings         manifest 1, imported 2, rejected 0, skipped 0 (+1 Unrated fallback)', $out, 'verification stays honest, no MISMATCH');
    }

    public function test_resume_adds_chapter_to_previously_imported_story(): void
    {
        $this->migrate();
        $this->import($this->bundle(), 'commit');
        // second bundle from the same legacy ids plus one new chapter (file present)
        $second = $this->bundle(function (\EfictionInstall $fx, string $root): void {
            $p = $fx->prefix;
            $fx->pdo->exec("INSERT INTO {$p}fanfiction_chapters (chapid, title, inorder, storytext, validated, wordcount, sid, uid) VALUES (14, 'Late Chapter', 2, 'Added <i>later</i>.', '1', 3, 7, 1)");
            file_put_contents($root . '/src/stories/1/14.txt', 'Added <i>later</i>.');
        });
        $out = $this->import($second, 'commit');
        $db = $this->db();
        $row = $db->one("SELECT c.created_at, s.created_at s_created FROM chapters c JOIN stories s ON s.id = c.story_id WHERE c.title = 'Late Chapter'");
        $this->assertNotNull($row, 'the new chapter must import');
        $this->assertSame($row['s_created'], $row['created_at'], 'the chapter inherits the existing story dates');
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM chapters')['c'], 'no duplicates');
        $this->assertStringContainsString('already mapped', $out);
    }
}
