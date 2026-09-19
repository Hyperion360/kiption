<?php // tests/HeadTest.php
namespace App\Tests;
use App\Seo\Head;
use PHPUnit\Framework\TestCase;

final class HeadTest extends TestCase
{
    public function test_canonical_is_absolute_via_base_url(): void
    {
        $h = Head::make(siteName: 'S', baseUrl: 'https://archive.example')->withCanonical('/story/view/x');
        $this->assertSame('https://archive.example/story/view/x', $h->canonical());
        $this->assertSame('https://archive.example/story/view/x', $h->url('/story/view/x'));
    }

    public function test_title_appends_site_suffix(): void
    {
        $h = Head::make(siteName: 'Kiption')->withTitle('The Rabbit Hole by Demo Author');
        $this->assertSame('The Rabbit Hole by Demo Author - Kiption', $h->title());
        $this->assertSame('Kiption', Head::make(siteName: 'Kiption')->withTitle(null)->title());
    }

    public function test_description_trimmed_at_word_boundary(): void
    {
        $long = str_repeat('word ', 40);
        $h = Head::make(siteName: 'S')->withDescription($long);
        $this->assertLessThanOrEqual(160, strlen($h->description()));
        $this->assertStringEndsNotWith(' ', $h->description());
    }

    public function test_description_cut_never_splits_a_utf8_character(): void
    {
        $desc = Head::make(siteName: 'S')->withDescription(str_repeat('中', 200))->description();
        $this->assertSame(160, mb_strlen($desc, 'UTF-8')); // the budget is 160 characters, no spaces to trim at
        // A byte cut ships invalid UTF-8 and the layout escaper (htmlspecialchars,
        // UTF-8) then blanks the whole content attribute: description goes empty.
        $this->assertTrue(mb_check_encoding($desc, 'UTF-8'));
    }

    public function test_short_multibyte_descriptions_are_not_byte_truncated(): void
    {
        // 100 characters of 2-byte text: inside the 160 character budget even
        // though it is 200 bytes. Byte maths cut this to 160 bytes.
        $this->assertSame(str_repeat('é', 100), Head::make(siteName: 'S')->withDescription(str_repeat('é', 100))->description());
    }

    public function test_og_article_type_carries_times(): void
    {
        $h = Head::make(siteName: 'S')->withArticle(published: '2026-08-01T09:00:00Z', modified: '2026-09-10T09:00:00Z');
        $tags = $h->ogTags();
        $this->assertContains(['property' => 'og:type', 'content' => 'article'], $tags);
        $this->assertContains(['property' => 'article:published_time', 'content' => '2026-08-01T09:00:00Z'], $tags);
        $this->assertContains(['property' => 'article:modified_time', 'content' => '2026-09-10T09:00:00Z'], $tags);
    }

    public function test_og_image_and_twitter_card_pair(): void
    {
        $with = Head::make(siteName: 'S', ogImage: '/assets/card.png')->withTitle('T');
        $this->assertContains(['property' => 'og:image', 'content' => '/assets/card.png'], $with->ogTags());
        $this->assertContains(['name' => 'twitter:card', 'content' => 'summary_large_image'], $with->twitterTags());
        $without = Head::make(siteName: 'S')->withTitle('T');
        $this->assertContains(['name' => 'twitter:card', 'content' => 'summary'], $without->twitterTags());
    }

    public function test_jsonld_encodes_and_escapes(): void
    {
        $h = Head::make(siteName: 'S')->withJsonLd(['@type' => 'Book', 'name' => 'A "Quoted" <Story>']);
        $this->assertSame('{"@type":"Book","name":"A \"Quoted\" \u003CStory\u003E"}', $h->jsonLd());
    }

    public function test_noindex_flag(): void
    {
        $this->assertFalse(Head::make(siteName: 'S')->noindex);
        $this->assertTrue(Head::make(siteName: 'S')->withNoindex()->noindex);
    }
}
