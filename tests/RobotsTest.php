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

    public function test_kip_robots_fallback_resolves_inside_the_app_tree(): void
    {
        // When an operator's config omits public_dir, the arm falls back to a
        // path expression; it must resolve to THIS app's public/ dir, never a
        // dir one level above the tree. The expression is resolved exactly as
        // bin/kip would (__DIR__ = bin/), so the pin catches a stray ../.
        $src = (string) file_get_contents(dirname(__DIR__) . '/bin/kip');
        $this->assertSame(1, preg_match("/\\\$file = \(\\\$config\['public_dir'\] \?\? (.+)\) \. '\/robots\.txt'/", $src, $m),
            'the robots arm carries a public_dir fallback');
        $expr = str_replace('__DIR__', var_export(dirname(__DIR__) . '/bin', true), $m[1]);
        $resolved = eval('return ' . $expr . ';');
        $this->assertSame(
            realpath(dirname(__DIR__) . '/public'),
            realpath($resolved),
            'the fallback must be the app public dir, not <repo>/../public'
        );
    }
}
