<?php // tests/FictioneerCliTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

final class FictioneerCliTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kiption-fxcli-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function runKip(string $args, string $dsn, string $owner = ''): array
    {
        exec(sprintf('KIP_DB_DSN=%s KIP_STATIC_CACHE_DIR=%s KIP_NAV_FILE=%s PATH="/opt/homebrew/bin:$PATH" php %s %s 2>&1',
            escapeshellarg($dsn), escapeshellarg($this->dir . '/cache'), escapeshellarg($this->dir . '/nav.json'),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'), $args), $out, $code);
        return [$out, $code];
    }

    public function test_usage_and_bad_path(): void
    {
        [$out, $code] = $this->runKip('import:fictioneer', 'sqlite:' . $this->dir . '/x.sqlite');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage: kip import:fictioneer', implode("\n", $out));
        [$out, $code] = $this->runKip('import:fictioneer /no/such/file.xml --dry-run', 'sqlite:' . $this->dir . '/x.sqlite');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No such file: /no/such/file.xml', implode("\n", $out));
    }

    public function test_mutually_exclusive_flags(): void
    {
        touch($this->dir . '/e.xml');
        [$out, $code] = $this->runKip("import:fictioneer {$this->dir}/e.xml --dry-run --commit", 'sqlite:' . $this->dir . '/x.sqlite');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('mutually exclusive', implode("\n", $out));
    }

    public function test_owner_must_resolve(): void
    {
        // the penname scan reads users, so the throwaway DB needs its schema
        // first (the CliTest importSetup idiom); without it the scan dies on
        // the missing table instead of printing the refusal under test
        $dsn = 'sqlite:' . $this->dir . '/x.sqlite';
        $this->runKip('migrate', $dsn);
        touch($this->dir . '/e.xml');
        // --dry-run clears the mode gate without risking writes; the refusal
        // must fire before any import work either way
        [$out, $code] = $this->runKip("import:fictioneer {$this->dir}/e.xml --dry-run --owner=nobody", $dsn);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no member carries', implode("\n", $out));
    }
}
