<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Readable English must come from the language pack so owners can edit it.
 * Controllers may not hand a literal sentence to a Response or an error slot.
 */
final class NoHardcodedTextTest extends TestCase
{
    /** @return list<string> */
    private function controllers(): array
    {
        $root = dirname(__DIR__) . '/app';
        $files = glob($root . '/Features/*/*Controller.php') ?: [];
        return $files;
    }

    public function test_controllers_have_no_literal_response_text(): void
    {
        $bad = [];
        foreach ($this->controllers() as $file) {
            foreach (file($file) as $n => $line) {
                if (preg_match("/new Response\\(\\s*'([^']*[A-Za-z]{3}[^']*)'/", $line, $m)
                    && !str_starts_with(ltrim($line), '//')) {
                    $bad[] = basename($file) . ':' . ($n + 1) . ' ' . $m[1];
                }
                if (preg_match("/'error'\\s*=>\\s*'[A-Z][^']*'/", $line)) {
                    $bad[] = basename($file) . ':' . ($n + 1) . ' error slot';
                }
            }
        }
        $this->assertSame([], $bad, "Move this text into app/lang/en.php:\n" . implode("\n", $bad));
    }

    public function test_the_scan_catches_a_literal(): void
    {
        $line = "return new Response('Something broke', 500);";
        $this->assertSame(1, preg_match("/new Response\\(\\s*'([^']*[A-Za-z]{3}[^']*)'/", $line));
        $ok = "return new Response(\\App\\Lang::t('x.y'), 500);";
        $this->assertSame(0, preg_match("/new Response\\(\\s*'([^']*[A-Za-z]{3}[^']*)'/", $ok));
    }
}
