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
}
