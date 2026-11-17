<?php
declare(strict_types=1);

namespace Tests;
use PHPUnit\Framework\Attributes\Large;

use App\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * The word count exists twice: App\Markdown::wordCount (stored on save) and
 * public/assets/wordcount.js (shown while writing). Both read the same
 * fixture list, so the two cannot drift apart.
 */
#[Large]
final class WordCountParityTest extends TestCase
{
    public function test_php_counts_match_the_shared_fixtures(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/fixtures/wordcount.json'), true);
        $this->assertNotEmpty($cases);
        foreach ($cases as $c) {
            $this->assertSame($c['words'], Markdown::wordCount($c['md']), json_encode($c['md'], JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_js_counts_match_the_shared_fixtures(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed; run tests/js/wordcount.test.mjs where it is');
        }
        exec(escapeshellarg($node) . ' --test ' . escapeshellarg(dirname(__DIR__) . '/tests/js/wordcount.test.mjs') . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
