<?php // tests/HeadTest.php
namespace App\Tests;
use App\Seo\Head;
use PHPUnit\Framework\TestCase;

final class HeadTest extends TestCase
{
    protected function tearDown(): void
    {
        // The Features::reset() discipline: the site rating is a process-wide
        // boot seam, so no later test inherits one that a test set here.
        Head::resetSiteRating();
    }

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

    public function test_with_og_image_overrides_per_page_and_keeps_clone_purity(): void
    {
        $h = Head::make(siteName: 'S', ogImage: '/assets/site.png')->withTitle('T');
        $this->assertContains(['property' => 'og:image', 'content' => '/assets/site.png'], $h->ogTags());
        $override = $h->withOgImage('https://archive.example/uploads/avatar.png');
        $this->assertContains(['property' => 'og:image', 'content' => 'https://archive.example/uploads/avatar.png'], $override->ogTags());
        $this->assertContains(['name' => 'twitter:card', 'content' => 'summary_large_image'], $override->twitterTags());
        // the clone treatment: the original head keeps the site-level card
        $this->assertContains(['property' => 'og:image', 'content' => '/assets/site.png'], $h->ogTags());
        $this->assertSame('/assets/site.png', $h->ogImage);
    }

    /** The operator's site-wide adult label (config 'adult_rta'): a page that
     *  sets no rating of its own carries the site default. */
    public function test_site_rating_fills_pages_without_their_own(): void
    {
        Head::siteRating('RTA-5042-1996-1400-1577-RTA');
        $tags = Head::make('S')->withTitle('t')->metaTags();
        $names = array_column($tags, 'name');
        $this->assertContains('rating', $names);
        $this->assertSame('RTA-5042-1996-1400-1577-RTA', $tags[array_search('rating', $names)]['content']);
    }

    /** One rating meta maximum per page: a per-page withRating() call wins
     *  and the site default never doubles it. */
    public function test_per_page_rating_wins_over_the_site_default(): void
    {
        Head::siteRating('RTA-5042-1996-1400-1577-RTA');
        $tags = Head::make('S')->withRating('adult')->metaTags();
        $ratings = array_values(array_filter($tags, fn ($t) => $t['name'] === 'rating'));
        $this->assertCount(1, $ratings);
        $this->assertSame('adult', $ratings[0]['content']);
    }

    /** Static RTA off (the shipped default): no rating meta anywhere. */
    public function test_no_site_rating_by_default(): void
    {
        $names = array_column(Head::make('S')->metaTags(), 'name');
        $this->assertNotContains('rating', $names);
    }

    /** An empty string clears the site label (the settings board writes ''
     *  to switch it off; the boot seam must not emit an empty meta). */
    public function test_empty_site_rating_is_off(): void
    {
        Head::siteRating('RTA-5042-1996-1400-1577-RTA');
        Head::siteRating('');
        $names = array_column(Head::make('S')->metaTags(), 'name');
        $this->assertNotContains('rating', $names);
    }
}
