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

    public function test_lists_map_to_dash_items(): void
    {
        $this->assertSame("- one\n- two\n", HtmlToMarkdown::convert('<ul><li>one</li><li>two</li></ul>'));
        $this->assertSame("- a\n- b\n", HtmlToMarkdown::convert('<ol><li>a</li><li>b</li></ol>'), 'ordered lists lose their numbers (the subset has no ordered form)');
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

    public function test_heading_becomes_a_bold_paragraph(): void
    {
        $md = HtmlToMarkdown::convert('<h2>The Map</h2><p>Body text.</p>');
        $this->assertSame("**The Map**\n\nBody text.\n", $md);
    }

    public function test_table_collapses_to_cell_lines(): void
    {
        $md = HtmlToMarkdown::convert('<table><tr><th>Name</th><th>Age</th></tr><tr><td>Ada</td><td>36</td></tr></table>');
        $this->assertStringContainsString('Name | Age', $md);
        $this->assertStringContainsString('Ada | 36', $md);
    }

    public function test_block_comments_vanish(): void
    {
        $md = HtmlToMarkdown::convert("<!-- wp:paragraph -->\n<p>Hello.</p>\n<!-- /wp:paragraph -->");
        $this->assertSame("Hello.\n", $md);
    }

    public function test_wordpress_caption_keeps_image_and_text(): void
    {
        $md = HtmlToMarkdown::wordpress('[caption width="300"]<img src="https://x/y.jpg" alt="river"> The river[/caption]');
        $this->assertStringContainsString('![river](https://x/y.jpg)', $md);
        $this->assertStringContainsString('The river', $md);
        $this->assertStringNotContainsString('[caption', $md);
    }

    public function test_wordpress_fcnt_keeps_the_note_inline(): void
    {
        $md = HtmlToMarkdown::wordpress('<p>Word[fcnt]an old word[/fcnt] ends.</p>');
        $this->assertStringContainsString('Word (an old word) ends.', $md);
    }

    public function test_wordpress_gallery_vanishes(): void
    {
        $md = HtmlToMarkdown::wordpress('<p>A</p>[gallery ids="1,2,3"]<p>B</p>');
        $this->assertSame("A\n\nB\n", $md);
    }

    public function test_wordpress_fictioneer_shortcodes_vanish(): void
    {
        $md = HtmlToMarkdown::wordpress('<p>A</p>[fictioneer_chapter_list]<p>B</p>');
        $this->assertSame("A\n\nB\n", $md);
    }

    public function test_unknown_shortcode_stays_literal(): void
    {
        $md = HtmlToMarkdown::wordpress('<p>He said [sic].</p>');
        $this->assertStringContainsString('[sic]', $md);
    }

    public function test_del_and_pre_collapse_to_text(): void
    {
        $md = HtmlToMarkdown::convert('<p><del>gone</del></p><pre>kept  as-is</pre>');
        $this->assertStringContainsString('gone', $md);
        $this->assertStringContainsString('kept  as-is', $md);
    }
}
