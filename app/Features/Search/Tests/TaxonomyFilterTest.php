<?php // app/Features/Search/Tests/TaxonomyFilterTest.php
namespace App\Features\Search\Tests;
use App\Tests\Support\TaxonomyCase;

/** /search?tag=ID and /search?character=ID, and the story page links that feed them. */
final class TaxonomyFilterTest extends TaxonomyCase
{
    private function storyId(string $slug): int
    {
        return (int) $this->row('SELECT id FROM stories WHERE slug = ?', [$slug])['id'];
    }

    private function tagId(string $name): int
    {
        return (int) $this->row('SELECT id FROM tags WHERE name = ?', [$name])['id'];
    }

    private function slugs(string $query): array
    {
        parse_str($query, $params);
        $body = $this->client()->get('/search', $params)->body;
        preg_match_all('#/story/view/([a-z0-9-]+)#', $body, $m);
        return array_values(array_unique($m[1]));
    }

    private function twoStories(): array
    {
        $stories = array_column($this->db->all('SELECT slug FROM stories WHERE validated = 1 ORDER BY id'), 'slug');
        $this->assertGreaterThanOrEqual(2, count($stories));
        $this->db->query('DELETE FROM story_tags');
        return [$stories[0], $stories[1]];
    }

    public function test_search_by_tag(): void
    {
        [$a, $b] = $this->twoStories();
        $fantasy = $this->tagId('Fantasy');
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$this->storyId($a), $fantasy]);
        $this->assertSame([$a], $this->slugs('tag=' . $fantasy));
        $this->assertNotContains($b, $this->slugs('tag=' . $fantasy));
    }

    public function test_a_synonym_id_finds_stories_of_its_canonical_tag(): void
    {
        [$a] = $this->twoStories();
        $fantasy = $this->tagId('Fantasy');
        $this->db->query("INSERT INTO tags (tag_type_id, name, canonical_id) VALUES ((SELECT tag_type_id FROM tags WHERE id = ?), 'Fantasyish', ?)", [$fantasy, $fantasy]);
        $syn = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$this->storyId($a), $fantasy]);
        $this->assertSame([$a], $this->slugs('tag=' . $syn));
    }

    public function test_search_by_character_and_combined_with_category(): void
    {
        [$a, $b] = $this->twoStories();
        $general = (int) $this->row("SELECT id FROM categories WHERE slug = 'general'")['id'];
        $this->db->query("INSERT INTO categories (name, slug) VALUES ('Sci-fi', 'sci-fi')");
        $scifi = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO characters (category_id, name, slug) VALUES (NULL, 'Alice', 'alice')");
        $alice = (int) $this->db->lastInsertId();
        foreach ([$a, $b] as $slug) {
            $this->db->query('INSERT INTO story_characters (story_id, character_id) VALUES (?, ?)', [$this->storyId($slug), $alice]);
        }
        $this->db->query('DELETE FROM story_categories');
        $this->db->query('INSERT INTO story_categories (story_id, category_id) VALUES (?, ?)', [$this->storyId($a), $general]);
        $this->db->query('INSERT INTO story_categories (story_id, category_id) VALUES (?, ?)', [$this->storyId($b), $scifi]);
        $both = $this->slugs('character=' . $alice);
        sort($both);
        $expected = [$a, $b];
        sort($expected);
        $this->assertSame($expected, $both);
        $this->assertSame([$b], $this->slugs('character=' . $alice . '&category=sci-fi'));
    }

    public function test_tag_and_character_filters_combine(): void
    {
        [$a, $b] = $this->twoStories();
        $fantasy = $this->tagId('Fantasy');
        $this->db->query("INSERT INTO characters (category_id, name, slug) VALUES (NULL, 'Alice', 'alice')");
        $alice = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$this->storyId($a), $fantasy]);
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$this->storyId($b), $fantasy]);
        $this->db->query('INSERT INTO story_characters (story_id, character_id) VALUES (?, ?)', [$this->storyId($b), $alice]);
        $this->assertSame([$b], $this->slugs("tag=$fantasy&character=$alice"));
    }

    public function test_unknown_or_junk_ids(): void
    {
        $this->twoStories();
        $res = $this->client()->get('/search', ['tag' => '99999']);
        $this->assertSame(200, $res->status);
        $this->assertSame([], $this->slugs('tag=99999'));
        $this->assertSame([], $this->slugs('character=99999'));
        $this->assertSame(200, $this->client()->get('/search', ['tag' => 'abc', 'character' => '-1'])->status);
        $this->assertSame(200, $this->client()->get('/search', ['tag' => '99999999999999999999999'])->status);
    }

    public function test_the_pager_and_form_carry_the_filters(): void
    {
        $this->twoStories();
        $body = $this->client()->get('/search', ['tag' => '7', 'character' => '9'])->body;
        $this->assertStringContainsString('name="tag" value="7"', $body);
        $this->assertStringContainsString('name="character" value="9"', $body);
    }

    public function test_story_page_names_link_to_the_filtered_search(): void
    {
        [$a] = $this->twoStories();
        $fantasy = $this->tagId('Fantasy');
        $this->db->query("INSERT INTO characters (category_id, name, slug) VALUES (NULL, 'Alice', 'alice')");
        $alice = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$this->storyId($a), $fantasy]);
        $this->db->query('INSERT INTO story_characters (story_id, character_id) VALUES (?, ?)', [$this->storyId($a), $alice]);
        $body = $this->client()->get('/story/view/' . $a)->body;
        $this->assertStringContainsString('href="/search?tag=' . $fantasy . '"', $body);
        $this->assertStringContainsString('href="/search?character=' . $alice . '"', $body);
    }

    public function test_a_synonym_chip_links_to_its_canonical_tag(): void
    {
        [$a] = $this->twoStories();
        $fantasy = $this->tagId('Fantasy');
        $this->db->query("INSERT INTO tags (tag_type_id, name, canonical_id) VALUES ((SELECT tag_type_id FROM tags WHERE id = ?), 'Fantasyish', ?)", [$fantasy, $fantasy]);
        $syn = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$this->storyId($a), $syn]);
        $this->assertStringContainsString('href="/search?tag=' . $fantasy . '"', $this->client()->get('/story/view/' . $a)->body);
    }
}
