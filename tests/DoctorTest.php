<?php // tests/DoctorTest.php
declare(strict_types=1);

namespace App\Tests;

use App\Doctor;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    private array $env;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/doctor-' . bin2hex(random_bytes(4));
        mkdir($dir . '/app', 0777, true);
        mkdir($dir . '/public/cache', 0777, true);
        mkdir($dir . '/public/uploads', 0777, true);
        $this->env = [
            'app_dir' => $dir . '/app',
            'public_dir' => $dir . '/public',
            'config' => [
                'base_url' => 'https://archive.example.test',
                'mail' => ['transport' => 'log', 'log_path' => $dir . '/app/mail.log'],
                'backups' => ['dir' => $dir . '/app/backups'],
                'static_cache' => ['enabled' => true, 'dir' => $dir . '/public/cache'],
                'uploads' => ['dir' => $dir . '/public/uploads'],
            ],
            'expected_migrations' => 1,
        ];
    }

    public function test_passes_on_a_healthy_environment(): void
    {
        $this->env['expected_migrations'] = 0; // no database yet: nothing applied, nothing expected
        $report = Doctor::run($this->env);
        self::assertTrue($report['ok']);
        self::assertContains($report['search_mode'], ['fts5', 'like']); // machine-dependent: assert the report, not the build
        self::assertSame(0, $report['failures']);
    }

    public function test_reports_unwritable_paths(): void
    {
        $this->env['expected_migrations'] = 0;
        // A path under a regular file cannot be created by anyone, so the
        // check's auto-create cannot heal it (a merely-missing dir would be
        // mkdir'ed and pass).
        $blocker = $this->env['app_dir'] . '/blocker';
        file_put_contents($blocker, 'x');
        $this->env['config']['static_cache']['dir'] = $blocker . '/cache';
        $report = Doctor::run($this->env);
        self::assertFalse($report['ok']);
        self::assertTrue($this->failed($report, 'writable static cache'));
        foreach ($report['checks'] as $c) {
            if (!$c['ok']) self::assertNotSame('', $c['fix']);
        }
    }

    public function test_flags_pending_migrations(): void
    {
        $this->env['expected_migrations'] = 99;
        $report = Doctor::run($this->env);
        self::assertFalse($report['ok']);
        self::assertTrue($this->failed($report, 'migrations'));
    }

    public function test_json_mode_is_machine_readable(): void
    {
        $json = Doctor::runJson($this->env);
        self::assertSame(json_decode($json, true)['ok'], Doctor::run($this->env)['ok']);
    }

    private function failed(array $report, string $name): bool
    {
        foreach ($report['checks'] as $c) {
            if ($c['name'] === $name) return !$c['ok'];
        }
        self::fail("no check named {$name}");
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->env['app_dir']);
        $ri = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $f) { $f->isDir() ? @rmdir((string) $f) : @unlink((string) $f); }
        @rmdir($dir);
    }
}
