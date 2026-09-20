<?php // tests/RobotsTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

final class RobotsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kiption-robots-' . uniqid('', true);
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_robots_regeneration_honors_the_ai_toggle(): void
    {
        $file = $this->root . '/robots.txt';
        \App\Seo\Sitemap::writeRobots($file, permissive: true);
        $this->assertSame(
            (string) file_get_contents(dirname(__DIR__) . '/public/robots.txt'),
            (string) file_get_contents($file),
            'finding 12: the permissive output is byte-identical to the committed robots.txt'
        );
        \App\Seo\Sitemap::writeRobots($file, permissive: false);
        $txt = (string) file_get_contents($file);
        $this->assertStringContainsString('User-agent: GPTBot', $txt);
        $this->assertStringContainsString('Disallow: /', $txt);
    }

    public function test_restrictive_output_prepends_one_rfc9309_group(): void
    {
        $file = $this->root . '/robots.txt';
        \App\Seo\Sitemap::writeRobots($file, permissive: false);
        // consecutive User-agent lines form ONE group sharing the Disallow;
        // the committed permissive bytes ride verbatim after the blank separator
        $this->assertSame(
            "User-agent: GPTBot\nUser-agent: CCBot\nUser-agent: ClaudeBot\nUser-agent: anthropic-ai\nUser-agent: Google-Extended\nDisallow: /\n\n"
                . (string) file_get_contents(dirname(__DIR__) . '/public/robots.txt'),
            (string) file_get_contents($file)
        );
    }

    public function test_kip_usage_lists_the_robots_arm(): void
    {
        // bare invocation prints usage only: no config side effects on the repo
        exec(sprintf('%s %s 2>&1',
            escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__) . '/bin/kip')), $out, $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('robots', implode("\n", $out));
    }
}
