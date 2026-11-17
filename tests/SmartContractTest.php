<?php // tests/SmartContractTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;

/** Slice 8: the smart punctuation rules exist once, in subset.js, and the
 *  conversions keep the word counter honest. */
#[Large]
final class SmartContractTest extends TestCase
{
    public function test_the_guard_tables_match_the_subset_module(): void
    {
        $smart = (string) file_get_contents(dirname(__DIR__, 1) . '/public/assets/smart.js');
        $this->assertStringContainsString('linkTargetAt', $smart, 'the link-target guard reads subset.js');
        $this->assertStringContainsString("u2026", $smart, 'the ellipsis');
        $this->assertStringContainsString("u2014", $smart, 'the em dash (D6)');
        // D6's rule: -- becomes an em dash; a hyphen line never converts
        $this->assertStringContainsString('scene-break guard', $smart);
        // D2's warning stays recorded at the guard site
        $this->assertStringContainsString('one-step undo', $smart);
    }

    /** Every conversion keeps Kip.wordCount identical to the pre-conversion
     *  count: a quote swap, an em dash merge, and an ellipsis merge each
     *  change punctuation, never words. Driven through Node so the same
     *  modules the browser runs are the ones under test. */
    public function test_conversions_keep_the_word_count(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed; run tests/js/smart.test.mjs where it is');
        }
        $script = <<<'JS'
const { createRequire } = await import('node:module');
const require = createRequire(process.cwd() + '/tests/js/x.mjs');
const smart = require('./public/assets/smart.js');
const wc = require('./public/assets/wordcount.js');
const mk = (value, caret) => ({ value, selectionStart: caret, selectionEnd: caret,
  setRangeText(t, a, b) { this.value = value.slice(0, a) + t + value.slice(b); this.selectionStart = this.selectionEnd = a + t.length; },
  setSelectionRange() {}, dispatchEvent() {} });
const cases = [
  ['don', "'", 3],
  ['say ', '"', 4],
  ['he said', '"', 7],
  ['wait-', '-', 5],
  ['wait..', '.', 6],
];
const out = [];
for (const [text, key, caret] of cases) {
  const box = mk(text, caret);
  const conv = smart.forKey(box, key);
  if (conv === null) { out.push([text, wc.wordCount(text), wc.wordCount(text)]); continue; }
  const after = box.value.slice(0, conv.from) + conv.text + box.value.slice(conv.to);
  out.push([text, wc.wordCount(text), wc.wordCount(after)]);
}
console.log(JSON.stringify(out));
JS;
        $prefix = (string) realpath(dirname(__DIR__, 1));
        $cmd = escapeshellarg($node) . ' --input-type=module -e ' . escapeshellarg(str_replace("'./public/assets/", "'" . $prefix . '/public/assets/', $script));
        exec($cmd . ' 2>&1', $outLines, $code);
        $this->assertSame(0, $code, implode("\n", $outLines));
        $rows = json_decode((string) $outLines[0], true);
        $this->assertIsArray($rows);
        foreach ($rows as [$text, $before, $after]) {
            $this->assertSame($before, $after, "the conversion kept the word count for {$text}");
        }
    }
}
