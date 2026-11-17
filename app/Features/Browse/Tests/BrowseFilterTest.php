<?php // app/Features/Browse/Tests/BrowseFilterTest.php
namespace App\Features\Browse\Tests;
use PHPUnit\Framework\Attributes\Small;
use App\Tests\Support\TaxonomyCase;

/** GET /browse/filter: tags to include and leave out, status, language, words, category, sort. */
#[Small]
final class BrowseFilterTest extends TaxonomyCase
{
    private function tagId(string $name): int
    {
        return (int) $this->row('SELECT id FROM tags WHERE name = ?', [$name])['id'];
    }

    private function storyId(string $slug): int
    {
        return (int) $this->row('SELECT id FROM stories WHERE slug = ?', [$slug])['id'];
    }

    /** @return list<string> */
    private function slugs(string $body): array
    {
        preg_match_all('#/story/view/([a-z0-9-]+)#', $body, $m);
        return array_values(array_unique($m[1]));
    }

    public function test_the_page_loads_for_a_guest_and_a_member(): void
    {
        $this->assertSame(200, $this->client()->get('/browse/filter')->status);
        $this->assertSame(200, $this->client($this->memberId)->get('/browse/filter')->status);
    }

    public function test_an_included_tag_lists_only_matching_stories(): void
    {
        $slugs = array_column($this->db->all('SELECT slug FROM stories WHERE validated = 1 ORDER BY id'), 'slug');
        $this->assertGreaterThanOrEqual(2, count($slugs));
        $this->db->query('DELETE FROM story_tags');
        $tag = $this->tagId('Fantasy');
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$this->storyId($slugs[0]), $tag]);
        $res = $this->client()->get('/browse/filter', ['tag' => [(string) $tag]]);
        $this->assertSame(200, $res->status);
        $this->assertSame([$slugs[0]], $this->slugs($res->body));
        $left = $this->client()->get('/browse/filter', ['xtag' => [(string) $tag]]);
        $this->assertNotContains($slugs[0], $this->slugs($left->body));
        $this->assertContains($slugs[1], $this->slugs($left->body));
    }

    public function test_the_form_is_a_plain_get_form_that_works_without_scripting(): void
    {
        $body = $this->client()->get('/browse/filter')->body;
        $this->assertStringContainsString('<form method="get" action="/browse/filter"', $body);
        $this->assertStringContainsString('name="tag[]"', $body);
        $this->assertStringContainsString('name="xtag[]"', $body);
        $this->assertStringContainsString('<details', $body);
        $this->assertStringContainsString('data-js-module="listfilter"', $body);
        $this->assertStringContainsString('type="submit"', $body);
        preg_match('#<form method="get" action="/browse/filter".*?</form>#s', $body, $form);
        $this->assertNotEmpty($form);
        $this->assertStringNotContainsString('<script', $form[0], 'nothing scripted inside the form');
        $this->assertDoesNotMatchRegularExpression('#\son(click|change|submit|input)=#i', $body, 'no inline handlers');
        preg_match_all('#<script\b([^>]*)>#i', $body, $tags);
        foreach ($tags[1] as $attrs) {
            $this->assertMatchesRegularExpression('#src="|type="application/ld\+json"#', $attrs, 'every script is external or JSON-LD, never inline code');
        }
    }

    public function test_the_page_is_noindex_in_the_header_and_the_markup(): void
    {
        $res = $this->client()->get('/browse/filter');
        $this->assertSame('noindex', $res->headers['X-Robots-Tag'] ?? null);
        $this->assertMatchesRegularExpression('#<meta name="robots" content="[^"]*noindex#', $res->body);
    }

    public function test_junk_parameters_are_coerced_not_an_error(): void
    {
        $res = $this->client()->get('/browse/filter', ['tag' => 'x', 'xtag' => [[1]], 'wmin' => 'abc', 'sort' => 'hax', 'status' => ['a'], 'page' => '-9']);
        $this->assertSame(200, $res->status);
    }

    public function test_the_page_never_enters_the_static_cache(): void
    {
        $cache = new \App\StaticCache\Cache($this->cacheDir);
        $this->assertNull($cache->fileFor('/browse/filter'), 'not on the whitelist');
        $this->client()->get('/browse/filter');
        $this->client()->get('/browse/filter', ['tag' => ['1']]);
        $this->assertFileDoesNotExist($this->cacheDir . '/browse/filter/index.html');
    }

    public function test_the_clear_link_carries_no_query_string(): void
    {
        $body = $this->client()->get('/browse/filter', ['status' => 'complete'])->body;
        $this->assertMatchesRegularExpression('#<a [^>]*href="/browse/filter"[^>]*>Clear Filters</a>#', $body);
    }

    public function test_pager_links_carry_the_filter(): void
    {
        $this->db->query('DELETE FROM story_tags');
        $author = $this->authorId;
        $rating = (int) $this->row('SELECT id FROM ratings LIMIT 1')['id'];
        for ($i = 0; $i < 22; $i++) {
            $this->db->query('INSERT INTO stories (title, slug, author_id, rating_id, validated, completed) VALUES (?, ?, ?, ?, 1, 1)', ['Extra ' . $i, 'extra-' . $i, $author, $rating]);
        }
        $body = $this->client()->get('/browse/filter', ['status' => 'complete'])->body;
        $this->assertMatchesRegularExpression('#rel="next"#', $body);
        $this->assertMatchesRegularExpression('#href="/browse/filter\?[^"]*status=complete[^"]*page=2#', $body);
    }

    public function test_a_selected_tag_past_the_cap_still_shows_as_checked(): void
    {
        $typeId = (int) $this->row('SELECT id FROM tag_types ORDER BY id LIMIT 1')['id'];
        for ($i = 0; $i < 110; $i++) {
            $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$typeId, sprintf('zzz%03d', $i)]);
        }
        $id = $this->tagId('zzz109');
        $body = $this->client()->get('/browse/filter', ['tag' => [(string) $id]])->body;
        $this->assertStringContainsString('Also Selected', $body);
        $this->assertMatchesRegularExpression('#name="tag\[\]" value="' . $id . '" checked#', $body);
    }

    public function test_a_muted_authors_stories_hide_from_the_muting_member_and_return_when_the_flag_is_off(): void
    {
        $slug = (string) $this->row('SELECT slug FROM stories WHERE validated = 1 ORDER BY id LIMIT 1')['slug'];
        $authorId = (int) $this->row('SELECT author_id FROM stories WHERE slug = ?', [$slug])['author_id'];
        $this->db->query('INSERT INTO muted (user_id, author_id) VALUES (?, ?)', [$this->memberId, $authorId]);
        \App\Features::init($this->db, []);
        $me = $this->client($this->memberId);
        $this->assertNotContains($slug, $this->slugs($me->get('/browse/filter')->body), 'muted for the muting member');
        $this->assertContains($slug, $this->slugs($this->client()->get('/browse/filter')->body), 'a guest still sees it');
        \App\Features::toggle('mute', false);
        $this->assertContains($slug, $this->slugs($me->get('/browse/filter')->body), 'flag off stops the filtering');
        \App\Features::toggle('mute', true);
    }
}
