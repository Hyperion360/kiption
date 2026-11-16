<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The paste converter is a pure JavaScript module. This runs its Node suite
 * from PHPUnit (skipped where node is missing) and proves the markdown each
 * golden sample converts to renders through App\Markdown as safe, plain
 * story markup.
 */
final class PasteConverterTest extends TestCase
{
    public function test_node_converter_suite_passes(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed; run tests/js/paste.test.mjs where it is');
        }
        exec(escapeshellarg($node) . ' --test ' . escapeshellarg(dirname(__DIR__) . '/tests/js/paste.test.mjs') . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_every_golden_markdown_renders_to_safe_html(): void
    {
        $files = glob(__DIR__ . '/fixtures/paste/*.md') ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            $html = \App\Markdown::render((string) file_get_contents($f));
            $this->assertNotSame('', $html, $f);
            $this->assertStringNotContainsString('<script', $html, $f);
            $this->assertDoesNotMatchRegularExpression('/<(?!\/?(?:p|em|strong|a|hr|blockquote)\b)/', $html, $f);
            $this->assertStringNotContainsString('javascript:', $html, $f);
        }
    }

    public function test_the_docs_sample_round_trips_to_the_expected_markup(): void
    {
        $html = \App\Markdown::render((string) file_get_contents(__DIR__ . '/fixtures/paste/docs-basic.md'));
        $this->assertSame('<p><em>slanted</em> and <strong>loud</strong> <a href="https://example.org" rel="noopener">link</a></p>', trim($html));
    }
}
