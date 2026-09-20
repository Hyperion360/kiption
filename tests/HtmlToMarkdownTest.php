<?php // tests/HtmlToMarkdownTest.php
namespace App\Tests;
use App\Import\HtmlToMarkdown;
use PHPUnit\Framework\TestCase;

final class HtmlToMarkdownTest extends TestCase
{
    public function test_prose_tag_set_maps_to_markdown(): void
    {
        $this->assertSame("Para one.\n\nPara *two* and **three** and _four_.\n\n> Quoted line\n",
            HtmlToMarkdown::convert('<p>Para one.</p><p>Para <i>two</i> and <b>three</b> and <u>four</u>.</p><blockquote>Quoted line</blockquote>'));
        $this->assertSame("Line one  \nLine two\n\n---\n",
            HtmlToMarkdown::convert('Line one<br>Line two<hr>'));
        $this->assertSame("[Words](https://example.test/a) and ![alt](https://example.test/i.png)\n",
            HtmlToMarkdown::convert('<a href="https://example.test/a">Words</a> and <img src="https://example.test/i.png" alt="alt">'));
        $this->assertSame("**Bold** inside\n", HtmlToMarkdown::convert('<strong>Bold</strong> inside'));
        $this->assertSame("Code stays\n", HtmlToMarkdown::convert('<p>Code stays</p>'));
    }

    public function test_unknown_tags_collapse_and_dangerous_bodies_vanish(): void
    {
        $this->assertSame("Kept text\n", HtmlToMarkdown::convert('<span style="x">Kept text</span>'));
        $this->assertSame("More\n", HtmlToMarkdown::convert('<script>alert(1)</script>More'), 'sibling text survives a vanished script body');
        $this->assertSame("More\n", HtmlToMarkdown::convert('<style>p{}</style>More'));
        $this->assertSame("Amp & lt decoded\n", HtmlToMarkdown::convert('<p>Amp &amp; lt decoded</p>'));
    }

    public function test_hostile_urls_dropped_and_nested_markup_flattens(): void
    {
        $this->assertSame("link\n", HtmlToMarkdown::convert('<a href="javascript:alert(1)">link</a>'));
        $this->assertSame("*a* and **xb**\n", HtmlToMarkdown::convert('<i>a</i> and <b><span>x</span>b</b>'), 'span collapses to its text inside the bold');
        $this->assertSame("Word pasta\n", HtmlToMarkdown::convert('<font face="x">Word <font>pasta</font></font>'));
    }

    public function test_word_paste_torture_collapses_cleanly(): void
    {
        $in = '<p class="MsoNormal"><span style="font-size:12.0pt">Chapter begins</span></p>'
            . '<p><b><i>emphasis</i></b></p><p>&nbsp;</p><p>End.</p>';
        $out = HtmlToMarkdown::convert($in);
        $this->assertStringContainsString('Chapter begins', $out);
        $this->assertStringContainsString('***emphasis***', $out);
        $this->assertStringNotContainsString('MsoNormal', $out);
        $this->assertStringNotContainsString('nbsp', $out);
    }
}
