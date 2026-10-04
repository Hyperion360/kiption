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
