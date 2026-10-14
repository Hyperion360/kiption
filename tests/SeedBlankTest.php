<?php // tests/SeedBlankTest.php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class SeedBlankTest extends TestCase
{
    private string $dir;
    private string $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/seedblank-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->db = $this->dir . '/data.sqlite';
    }

    private function kip(string ...$args): string
    {
        $cmd = 'KIP_DB_DSN=' . escapeshellarg('sqlite:' . $this->db)
            . ' KIP_CACHE_DB_DSN=' . escapeshellarg('sqlite:' . $this->dir . '/cache.sqlite')
            . ' KIP_STATIC_CACHE_DIR=' . escapeshellarg($this->dir . '/cache')
            . ' KIP_NAV_FILE=' . escapeshellarg($this->dir . '/nav.json')
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/kip')
            . ' ' . implode(' ', array_map(escapeshellarg(...), $args));
        exec($cmd . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        return implode("\n", $out);
    }

    public function test_blank_gives_taxonomy_and_nav_but_no_content_or_users(): void
    {
        $this->kip('migrate');
        $out = $this->kip('db:blank');
        self::assertStringContainsString('Bootstrap data written', $out);
        $pdo = new \PDO('sqlite:' . $this->db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertSame('4', (string) $pdo->query('SELECT COUNT(*) FROM ratings')->fetchColumn());
        self::assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());
        self::assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM tag_types')->fetchColumn());
        self::assertSame('5', (string) $pdo->query('SELECT COUNT(*) FROM nav_links')->fetchColumn());
        self::assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM stories')->fetchColumn());
        self::assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_blank_is_idempotent(): void
    {
        $this->kip('migrate');
        $this->kip('db:blank');
        $this->kip('db:blank'); // must not throw on rerun
        $this->addToAssertionCount(1);
    }

    /** The launch content-policy clause ships in the blank-install seed
     *  (Seeder::bootstrap), so a fresh archive opens with its stance on the
     *  page instead of an empty room. The clause separates romance (welcome,
     *  mature included behind the gate) from gratification-focused erotica
     *  (out of scope, removed through the validation queue and reports). */
    public function test_blank_seeds_the_content_policy_page(): void
    {
        $this->kip('migrate');
        $this->kip('db:blank');
        $clause = "This archive hosts original fiction and fanfiction for a general readership.\n\n"
            . 'No works whose primary purpose is sexual gratification. Romance is welcome, '
            . 'including mature romance behind the content gate, but material written to arouse '
            . 'is out of scope for this archive and will be removed through the validation queue and reports.';
        // markdown at rest: the stored body is the plain clause, never pre-rendered HTML
        $pdo = new \PDO('sqlite:' . $this->db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $row = $pdo->query("SELECT title, body FROM pages WHERE slug = 'content-policy'")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'the blank seed carries the content-policy page');
        self::assertSame($clause, $row['body'], 'the clause verbatim, markdown at rest');
        // and it renders on the page route
        $app = new \Kip\App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->db],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => $this->dir . '/uploads'],
            'static_cache' => ['dir' => $this->dir . '/cache'],
            'nav_file' => $this->dir . '/nav.json',
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $res = (new \Kip\Testing\TestClient($app))->get('/page/view/content-policy');
        self::assertSame(200, $res->status);
        self::assertStringContainsString('Content policy', $res->body);
        self::assertStringContainsString('primary purpose is sexual gratification', $res->body);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (['data.sqlite', 'data.sqlite-wal', 'data.sqlite-shm', 'cache.sqlite', 'cache.sqlite-wal', 'cache.sqlite-shm'] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @rmdir($this->dir);
    }
}
