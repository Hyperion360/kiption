<?php // tests/MarkdownTest.php
namespace App\Tests;
use App\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    public function test_paragraphs(): void
    {
        $this->assertSame("<p>One.</p>\n<p>Two.</p>\n", Markdown::render("One.\n\nTwo."));
    }

    public function test_emphasis_and_strong(): void
    {
        $this->assertSame("<p>Falling <em>down</em> the <strong>hole</strong>.</p>\n", Markdown::render('Falling *down* the **hole**.'));
        $this->assertSame("<p>A <em>quiet</em> word.</p>\n", Markdown::render('A _quiet_ word.'));
    }

    public function test_scene_break(): void
    {
        $this->assertSame("<p>Before</p>\n<hr>\n<p>After</p>\n", Markdown::render("Before\n\n---\n\nAfter"));
    }

    public function test_blockquote(): void
    {
        $this->assertSame("<blockquote><p>Quoted.</p></blockquote>\n", Markdown::render('> Quoted.'));
    }

    public function test_links_https_only(): void
    {
        $this->assertSame(
            '<p><a href="https://example.test/a?b=c" rel="noopener">text</a></p>' . "\n",
            Markdown::render('[text](https://example.test/a?b=c)'));
    }

    public function test_dangerous_link_schemes_render_as_text(): void
    {
        $this->assertSame('<p>[x](javascript:alert(1))</p>' . "\n", Markdown::render('[x](javascript:alert(1))'));
        $this->assertSame('<p>[x](data:text/html,hi)</p>' . "\n", Markdown::render('[x](data:text/html,hi)'));
        $this->assertSame('<p>[x](http://insecure.test/)</p>' . "\n", Markdown::render('[x](http://insecure.test/)'));
    }

    public function test_images_allow_http_and_https(): void
    {
        $this->assertSame(
            '<p><img src="https://example.test/i.png" alt="alt text"></p>' . "\n",
            Markdown::render('![alt text](https://example.test/i.png)'));
        $this->assertSame(
            '<p><img src="http://x/y.png" alt="a"></p>' . "\n",
            Markdown::render('![a](http://x/y.png)'));
    }

    public function test_raw_html_is_escaped(): void
    {
        $this->assertSame("<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>\n", Markdown::render('<script>alert(1)</script>'));
        $this->assertSame(
            "<p>&lt;img src=x onerror=alert(1)&gt;</p>\n",
            Markdown::render('<img src=x onerror=alert(1)>'));
    }

    public function test_link_text_is_escaped(): void
    {
        $this->assertSame(
            '<p><a href="https://e.test/" rel="noopener">&lt;b&gt;bold&lt;/b&gt;</a></p>' . "\n",
            Markdown::render('[<b>bold</b>](https://e.test/)'));
    }

    public function test_inline_markup_inside_paragraphs(): void
    {
        $this->assertSame(
            "<p>A <em>b</em> and <strong>c</strong> and <a href=\"https://e.test/\" rel=\"noopener\">d</a>.</p>\n",
            Markdown::render('A *b* and **c** and [d](https://e.test/).'));
    }

    public function test_unclosed_markers_stay_literal(): void
    {
        $this->assertSame("<p>A * b</p>\n", Markdown::render('A * b'));
        $this->assertSame("<p>**bold</p>\n", Markdown::render('**bold'));
    }

    public function test_empty_input(): void
    {
        $this->assertSame('', Markdown::render(''));
        $this->assertSame('', Markdown::render("   \n  \n"));
    }

    public function test_crlf_normalized(): void
    {
        $this->assertSame("<p>A</p>\n<p>B</p>\n", Markdown::render("A\r\n\r\nB"));
    }

    public function test_word_count_strips_markup(): void
    {
        $this->assertSame(8, Markdown::wordCount('Falling *down* the **hole**, [past](https://e.test/) shelves of nothing.'));
        $this->assertSame(0, Markdown::wordCount(''));
        $this->assertSame(0, Markdown::wordCount('---'));
        $this->assertSame(3, Markdown::wordCount('Four *short* words.'));
    }
}
