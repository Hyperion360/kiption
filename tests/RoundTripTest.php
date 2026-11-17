<?php // tests/RoundTripTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/** Shared fixtures pin the PHP renderer's subset and exact JavaScript source
 *  contracts on every checkout. Browser parsing and form transport can normalize
 *  newlines; the source model preserves the string it receives. Rich-surface and
 *  real-device release evidence is verified separately. */
#[Medium]
final class RoundTripTest extends TestCase
{
    /** The HTML subset the editor's renderer contract claims. Anything else
     *  in a fixture's render is a drift the rich view cannot round trip. */
    private const ALLOWED = '/<(?!\/?(?:p|em|strong|a|blockquote|hr)\b)/';

    /** @return list<string> */
    private function fixtures(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/fixtures/roundtrip/*.md') ?: [] as $f) {
            $out[] = basename($f);
        }
        sort($out);
        return $out;
    }

    public function test_the_fixture_set_exists_and_is_not_empty(): void
    {
        $this->assertNotSame([], $this->fixtures(), 'the round-trip fixtures are missing');
        $this->assertGreaterThanOrEqual(7, count($this->fixtures()), 'the plan named at least seven shapes');
    }

    public function test_every_fixture_renders_inside_the_editor_subset(): void
    {
        foreach ($this->fixtures() as $f) {
            $md = (string) file_get_contents(__DIR__ . '/fixtures/roundtrip/' . $f);
            $html = \App\Markdown::render($md);
            $this->assertDoesNotMatchRegularExpression(self::ALLOWED, $html, "{$f} rendered outside the editor subset: {$html}");
            $this->assertStringNotContainsString('<script', $html, $f);
        }
    }

    /** The exact shapes the rich view must preserve, pinned per fixture so a
     *  regression names the file that drifted. */
    public function test_the_named_shapes_hold(): void
    {
        $render = fn (string $f): string => \App\Markdown::render((string) file_get_contents(__DIR__ . '/fixtures/roundtrip/' . $f));
        $this->assertStringNotContainsString('<em>', $render('underscores.md'), 'underscores stay written');
        $this->assertMatchesRegularExpression('/href="https:\/\/example\.org\/a_b_c\/d_e"/', $render('link-underscores.md'), 'link addresses keep their underscores');
        $this->assertSame(2, substr_count($render('nested-marks.md'), '<strong>'), 'bold survives beside bold-italic');
        $this->assertStringContainsString('<blockquote>', $render('quotes.md'), 'a quote is one block');
        $this->assertStringContainsString('<hr>', $render('scene-breaks.md'), 'the scene break renders');
        $this->assertSame(2, substr_count($render('blank-lines.md'), '<p>'), 'one blank line separates two paragraphs');
        $this->assertStringContainsString('Don’t', $render('curly.md'), 'curly punctuation survives rendering');
    }

    /** Automated source tests are required independently of device acceptance. */
    #[Group('process')]
    public function test_the_js_round_trip_contract(): void
    {
        exec('node --test ' . escapeshellarg(dirname(__DIR__) . '/tests/js/roundtrip.test.mjs') . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
