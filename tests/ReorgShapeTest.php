<?php // tests/ReorgShapeTest.php
namespace App\Tests;

use PHPUnit\Framework\TestCase;

/** Shape invariants of the feature-folder layout. These hold TODAY (layered
 *  app, vacuously), during every intermediate commit of the reorg, and at the
 *  end; they exist to make a half-moved or duplicated tree fail loudly. */
final class ReorgShapeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    /** @return list<string> controller basenames per location */
    private function controllersIn(string $dir): array
    {
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (str_ends_with($f, 'Controller.php')) $out[] = $f;
        }
        return $out;
    }

    public function test_no_controller_exists_in_both_places(): void
    {
        $plain = array_fill_keys($this->controllersIn($this->root . '/app/src/Controllers'), true);
        foreach (glob($this->root . '/app/Features/*/*Controller.php') ?: [] as $p) {
            $base = basename($p);
            $this->assertArrayNotHasKey($base, $plain,
                "{$base} exists both plain and in a feature folder; the plain one wins and the feature copy is dead code");
        }
        $this->addToAssertionCount(1);
    }

    public function test_migration_names_are_globally_unique_across_dirs(): void
    {
        $dirs = array_merge(
            [$this->root . '/app/migrations'],
            glob($this->root . '/app/Features/*/migrations') ?: []
        );
        $seen = [];
        foreach ($dirs as $d) {
            foreach (scandir($d) ?: [] as $f) {
                if (!str_ends_with($f, '.php')) continue;
                $this->assertArrayNotHasKey($f, $seen, "{$f} appears in two migration directories");
                $seen[$f] = true;
                $this->assertSame(1, preg_match('/^\d{3}_/', $f), "{$f} lost its NNN prefix");
            }
        }
        // 24 at reorg time (2026-09-28); the floor, not a pin, so migration
        // 025 and beyond need no edit here (uniqueness above stays the guard).
        $this->assertGreaterThanOrEqual(24, $seen, 'the 24 reorg-time migration names must all still be present');
    }

    public function test_feature_folder_name_matches_controller(): void
    {
        foreach (glob($this->root . '/app/Features/*/') ?: [] as $dir) {
            $name = basename($dir);
            $this->assertFileExists($dir . $name . 'Controller.php',
                "folder app/Features/{$name} must hold {$name}Controller.php (router convention)");
        }
    }

    public function test_no_migration_files_at_wrong_depth(): void
    {
        // A 025_x.php dropped in app/Features/Auth/ (not .../Auth/migrations/)
        // is invisible to the discovery closure: kip migrate prints success
        // and the schema change never applies (adversarial review 2026-09-28).
        foreach (glob($this->root . '/app/Features/*/*.php') ?: [] as $stray) {
            $this->assertSame(0, preg_match('/^\d{3}_/', basename($stray)),
                basename($stray) . ' sits in a feature root, not its migrations/ subdirectory; move it or it will never run');
        }
        $this->addToAssertionCount(1);
    }

    public function test_reorg_end_state(): void
    {
        $this->assertDirectoryDoesNotExist($this->root . '/app/src/Controllers',
            'the layered Controllers directory must be gone (one-shot reorg)');
        foreach (scandir($this->root . '/app/views') ?: [] as $f) {
            if ($f[0] === '.') continue;
            $this->assertContains($f, ['layout.php', 'maintenance.php'],
                'app/views must hold only the kernel layout and maintenance page, found ' . $f);
        }
        $features = array_filter(glob($this->root . '/app/Features/*') ?: [], 'is_dir');
        $this->assertCount(38, $features, 'exactly 38 feature folders expected');
    }

    public function test_no_test_files_outside_the_tests_directory(): void
    {
        // Recursive: PHPUnit scans app/Features recursively, so a stray at ANY
        // depth still executes while silently violating the layout. Every
        // *Test.php must sit in a directory named exactly Tests (case-exact;
        // a lowercase variant stays green on case-insensitive dev machines)
        // and declare the path-derived namespace (adversarial review
        // 2026-09-29; supersedes the one-level glob).
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root . '/app/Features', \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($rii as $f) {
            if (!str_ends_with($f->getFilename(), 'Test.php')) continue;
            $count++;
            $this->assertSame('Tests', basename($f->getPath()),
                "{$f->getFilename()} sits in " . basename($f->getPath()) . ', not a Tests directory (case-exact)');
            $rel = substr($f->getPathname(), strlen($this->root . '/app/'));
            $expected = 'App\\' . str_replace('/', '\\', dirname($rel));
            preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($f->getPathname()), $m);
            $this->assertSame($expected, trim($m[1] ?? ''),
                "{$f->getFilename()} must declare namespace {$expected}");
        }
        $this->assertGreaterThanOrEqual(48, $count, 'the moved feature tests must all be found');
    }
}
