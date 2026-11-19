<?php // tests/MarkdownTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\Markdown;
use PHPUnit\Framework\TestCase;

#[Medium]
final class MarkdownTest extends TestCase
{
    public function test_inline_editor_parity_fixtures(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/fixtures/inline-editor-parity.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            $this->assertSame($row['readerHtml'], Markdown::render($row['source'], false), $row['source']);
        }
    }

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

    public function test_snake_case_words_do_not_italicize(): void
    {
        $this->assertSame("<p>use snake_case_word here</p>\n", Markdown::render('use snake_case_word here'));
        $this->assertSame("<p>my_var and other_var</p>\n", Markdown::render('my_var and other_var'));
        $this->assertSame("<p>a <em>real</em> one and snake_case_word</p>\n", Markdown::render('a _real_ one and snake_case_word'));
        $this->assertSame("<p><em>Привет мир</em> и по_русски_так</p>\n", Markdown::render('_Привет мир_ и по_русски_так'));
    }

    public function test_underscores_in_link_addresses_stay_in_the_address(): void
    {
        $this->assertSame(
            '<p><a href="https://e.test/a_b_c" rel="noopener">x</a> and <a href="https://e.test/_x_" rel="noopener">y</a></p>' . "\n",
            Markdown::render('[x](https://e.test/a_b_c) and [y](https://e.test/_x_)'));
        $this->assertSame(
            '<p><a href="https://e.test/a*b*c" rel="noopener">z</a></p>' . "\n",
            Markdown::render('[z](https://e.test/a*b*c)'));
        $this->assertSame(
            '<p><img src="https://e.test/a_b_c.png" alt="p"></p>' . "\n",
            Markdown::render('![p](https://e.test/a_b_c.png)'));
    }

    public function test_backslash_escapes_make_literal_markers(): void
    {
        $this->assertSame("<p>5 * 3 and 7 * 2</p>\n", Markdown::render('5 \\* 3 and 7 \\* 2'));
        $this->assertSame("<p>snake_case_word</p>\n", Markdown::render('snake\\_case\\_word'));
        $this->assertSame("<p>*not italic*</p>\n", Markdown::render('\\*not italic\\*'));
        $this->assertSame("<p>_not italic_</p>\n", Markdown::render('\\_not italic\\_'));
        $this->assertSame("<p>a\\b</p>\n", Markdown::render('a\\\\b'));
        $this->assertSame("<p>keep \\n as typed</p>\n", Markdown::render('keep \\n as typed'));
        $this->assertSame("<p><em>a*b</em></p>\n", Markdown::render('*a\\*b*'));
    }

    public function test_escaped_markers_in_link_text_and_list_items(): void
    {
        $this->assertSame('<p><a href="https://e.test/" rel="noopener">a*b</a></p>' . "\n", Markdown::render('[a\\*b](https://e.test/)'));
        $this->assertSame("<ul>\n<li>a * b</li></ul>\n", Markdown::render('- a \\* b', true));
    }

    public function test_word_count_is_unicode_aware(): void
    {
        $this->assertSame(2, Markdown::wordCount('don’t stop'));
        $this->assertSame(2, Markdown::wordCount("don't stop"));
        $this->assertSame(2, Markdown::wordCount('Привет мир'));
        $this->assertSame(2, Markdown::wordCount('café naïve'));
    }

    public function test_word_count_strips_markup(): void
    {
        $this->assertSame(8, Markdown::wordCount('Falling *down* the **hole**, [past](https://e.test/) shelves of nothing.'));
        $this->assertSame(0, Markdown::wordCount(''));
        $this->assertSame(0, Markdown::wordCount('---'));
        $this->assertSame(3, Markdown::wordCount('Four *short* words.'));
    }
    public function test_library_images_follow_the_chapter_policy(): void
    {
        $md = "Before\n\n![A *map*](/uploads/0123456789abcdef.png)\n\n---\n\nAfter";
        $this->assertStringContainsString('<img src="/uploads/0123456789abcdef.png" alt="A map">', Markdown::render($md, false, true));
        $off = Markdown::render($md, false, false);
        $this->assertStringNotContainsString('<img', $off);
        $this->assertStringContainsString('<p>A map</p>', $off);
        $this->assertStringContainsString('<hr>', $off);
        // every other caller is unchanged: the path stays literal text
        $this->assertSame(Markdown::render($md), Markdown::render($md, false, null));
        $this->assertStringNotContainsString('<img', Markdown::render($md));
        // only the library shape qualifies, never an arbitrary local path
        $this->assertStringNotContainsString('<img', Markdown::render('![x](/etc/passwd)', false, true));
        $this->assertStringNotContainsString('<img', Markdown::render('![x](/uploads/../x.png)', false, true));
    }

    public function test_a_link_address_with_balanced_parentheses_stays_one_link(): void
    {
        $this->assertSame(
            '<p><a href="https://x.com/a_(b)" rel="noopener">wiki</a> then more.</p>' . "\n",
            Markdown::render('[wiki](https://x.com/a_(b)) then more.'));
        $this->assertSame(
            '<p>(see <a href="https://x.com/a_(b)" rel="noopener">wiki</a>)</p>' . "\n",
            Markdown::render('(see [wiki](https://x.com/a_(b)))'));
        $this->assertSame(
            '<p><a href="https://x.com/plain" rel="noopener">p</a> (aside)</p>' . "\n",
            Markdown::render('[p](https://x.com/plain) (aside)'));
        $this->assertSame(
            '<p>[bad](javascript:alert(1))</p>' . "\n",
            Markdown::render('[bad](javascript:alert(1))'));
    }
    public function test_scene_breaks_are_normalised_to_the_editor_spelling(): void
    {
        $this->assertSame("One\n\n---\n\nTwo", Markdown::normalizeSceneBreaks("One\n\n* * *\n\nTwo"));
        $this->assertSame("One\r\n\r\n---\r\n\r\nTwo", Markdown::normalizeSceneBreaks("One\r\n\r\n***\r\n\r\nTwo"));
        $this->assertSame("---\n\nTwo", Markdown::normalizeSceneBreaks("_ _ _\n\nTwo"));
        $this->assertSame("One\n\n---\n\nTwo", Markdown::normalizeSceneBreaks("One\n\n- - -\n\nTwo"));
        // Untouched: already canonical, bold text, text directly above (a setext heading risk), fenced and quoted breaks.
        foreach (["One\n\n---\n\nTwo", "***bold***", "One\n* * *\nTwo", "```\n\n***\n```", "> * * *", "    * * *", "plain"] as $keep) {
            $this->assertSame($keep, Markdown::normalizeSceneBreaks($keep));
        }
        $this->assertSame("<p>One</p>\n<hr>\n<p>Two</p>\n", Markdown::render(Markdown::normalizeSceneBreaks("One\n\n***\n\nTwo")));
    }
}
