<?php
declare(strict_types=1);

namespace App\Tests;

use App\Setup\MigrationLock;
use PHPUnit\Framework\TestCase;

final class MigrationLockTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/kip-migration-lock-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') @unlink($this->root . '/' . $entry);
        }
        @rmdir($this->root);
    }

    private function lockFile(): string
    {
        $file = $this->root . '/migration.lock';
        file_put_contents($file, 'v1');
        chmod($file, 0600);
        return $file;
    }

    public function test_unconfigured_install_runs_without_a_lock(): void
    {
        self::assertSame('done', MigrationLock::run(null, static fn (): string => 'done'));
    }

    public function test_configured_lock_is_held_during_work_and_released_afterward(): void
    {
        $file = $this->lockFile();
        self::assertSame('done', MigrationLock::run($file, static function () use ($file): string {
            $other = fopen($file, 'r+');
            try {
                self::assertFalse(flock($other, LOCK_EX | LOCK_NB));
            } finally {
                fclose($other);
            }
            return 'done';
        }));
        $later = fopen($file, 'r+');
        try {
            self::assertTrue(flock($later, LOCK_EX | LOCK_NB));
        } finally {
            fclose($later);
        }
    }

    public function test_held_lock_refuses_migration(): void
    {
        $file = $this->lockFile();
        $holder = fopen($file, 'r+');
        self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
        try {
            $ran = false;
            try {
                MigrationLock::run($file, static function () use (&$ran): void { $ran = true; });
                self::fail('migration should refuse held lock');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Migration lock', $e->getMessage());
            }
            self::assertFalse($ran);
        } finally {
            fclose($holder);
        }
    }

    public function test_missing_linked_or_bad_protocol_lock_refuses_without_running(): void
    {
        $file = $this->root . '/migration.lock';
        foreach (['missing', 'linked', 'bad-version', 'broad-mode'] as $case) {
            @unlink($file);
            if ($case === 'linked') {
                file_put_contents($this->root . '/target', 'v1');
                symlink($this->root . '/target', $file);
            } elseif ($case !== 'missing') {
                file_put_contents($file, $case === 'bad-version' ? 'v2' : 'v1');
                chmod($file, $case === 'broad-mode' ? 0644 : 0600);
            }
            $ran = false;
            try {
                MigrationLock::run($file, static function () use (&$ran): void { $ran = true; });
                self::fail("{$case} lock should be refused");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('migration lock', $e->getMessage());
            }
            self::assertFalse($ran);
        }
    }

    public function test_relative_and_parent_traversal_paths_are_refused(): void
    {
        foreach (['migration.lock', $this->root . '/../migration.lock'] as $path) {
            $ran = false;
            try {
                MigrationLock::run($path, static function () use (&$ran): void { $ran = true; });
                self::fail('invalid path should be refused');
            } catch (\RuntimeException $e) {
                self::assertSame('Invalid migration lock path', $e->getMessage());
            }
            self::assertFalse($ran);
        }
    }

    public function test_callback_failure_releases_the_lock(): void
    {
        $file = $this->lockFile();
        try {
            MigrationLock::run($file, static function (): void { throw new \LogicException('stop'); });
            self::fail('expected callback failure');
        } catch (\LogicException $e) {
            self::assertSame('stop', $e->getMessage());
        }
        self::assertSame('next', MigrationLock::run($file, static fn (): string => 'next'));
    }
}
