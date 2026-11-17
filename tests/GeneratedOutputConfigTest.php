<?php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Large;

use PHPUnit\Framework\TestCase;
use Kip\Database;
use Kip\Migrations\Migrator;

#[Large]
final class GeneratedOutputConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kiption-generated-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/shared', 0700, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function test_release_output_paths_are_independent_of_code_and_uploads(): void
    {
        $generated = $this->root . '/generated/r-new/public';
        $uploads = $this->root . '/state/uploads';
        $cache = $this->root . '/generated/r-new/cache';
        file_put_contents($this->root . '/shared/deployment.php', '<?php return '
            . var_export(['public_dir' => $generated, 'uploads_dir' => $uploads], true) . ';');

        $code = 'define("KIPTION_SHARED_DIR", ' . var_export($this->root . '/shared', true) . ');'
            . 'putenv("KIP_STATIC_CACHE_DIR=' . $cache . '");'
            . '$c = require ' . var_export(dirname(__DIR__) . '/config.php', true) . ';'
            . 'echo json_encode([$c["app_dir"], $c["public_dir"], $c["static_cache"]["dir"], $c["uploads"]["dir"]]);';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([
            dirname(__DIR__) . '/app', $generated, $cache, $uploads,
        ], json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_cli_build_and_robots_write_only_new_generated_root(): void
    {
        $shared = $this->root . '/shared';
        $generated = $this->root . '/generated/r-new/public';
        $old = $this->root . '/generated/r-old/public';
        $cache = $this->root . '/generated/r-new/cache';
        $uploads = $this->root . '/state/uploads';
        foreach ([$generated, $old, $cache, $uploads] as $dir) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($old . '/robots.txt', 'old release');
        file_put_contents($old . '/sitemap.xml', 'old sitemap');
        file_put_contents($shared . '/deployment.php', '<?php return ' . var_export([
            'public_dir' => $generated,
            'uploads_dir' => $uploads,
            'base_url' => 'https://archive.example',
        ], true) . ';');
        $db = new Database('sqlite:' . $shared . '/data.sqlite');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        unset($db);

        $wrapper = $this->root . '/kip.php';
        file_put_contents($wrapper, '<?php define("KIPTION_SHARED_DIR", ' . var_export($shared, true) . ');'
            . 'putenv("KIP_STATIC_CACHE_DIR=' . $cache . '");'
            . 'require ' . var_export(dirname(__DIR__) . '/bin/kip', true) . ';');
        $seedRobots = hash_file('sha256', dirname(__DIR__) . '/public/robots.txt');
        foreach (['robots', 'pages:build'] as $arm) {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapper) . ' ' . $arm . ' 2>&1', $output, $status);
            self::assertSame(0, $status, implode("\n", $output));
            $output = [];
        }
        self::assertFileExists($generated . '/robots.txt');
        self::assertFileExists($generated . '/sitemap.xml');
        self::assertNotEmpty(glob($cache . '/*'));
        self::assertSame('old release', file_get_contents($old . '/robots.txt'));
        self::assertSame('old sitemap', file_get_contents($old . '/sitemap.xml'));
        self::assertSame($seedRobots, hash_file('sha256', dirname(__DIR__) . '/public/robots.txt'));
        self::assertDirectoryDoesNotExist($generated . '/uploads');

        $db = new Database('sqlite:' . $shared . '/data.sqlite');
        $db->query("UPDATE stories SET deleted_at = '2026-11-16T00:00:00Z' WHERE deleted_at IS NULL");
        $app = new \Kip\App([
            'app_dir' => dirname(__DIR__) . '/app',
            'static_cache' => ['dir' => $cache],
            'cache_db' => ['dsn' => 'sqlite:' . $shared . '/cache.sqlite'],
            'public_dir' => $generated,
            'base_url' => 'https://archive.example',
        ]);
        \App\CachePurge::reset();
        \App\CachePurge::run($app, $db);
        self::assertFileDoesNotExist($generated . '/sitemap-stories-1.xml');
        self::assertSame('old sitemap', file_get_contents($old . '/sitemap.xml'));

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapper) . ' pages:build 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertFileDoesNotExist($generated . '/sitemap-stories-1.xml');
        self::assertSame('old release', file_get_contents($old . '/robots.txt'));
    }
}
