<?php // app/Features/Tags/Tests/TagsTest.php
namespace App\Features\Tags\Tests;
use PHPUnit\Framework\Attributes\Small;
use App\Tests\Support\TaxonomyCase;

/** The owner's tag type and tag manager at /tags. */
#[Small]
final class TagsTest extends TaxonomyCase
{
    private function typeId(string $name): int
    {
        return (int) $this->row('SELECT id FROM tag_types WHERE name = ?', [$name])['id'];
    }

    private function tagId(string $name): int
    {
        return (int) $this->row('SELECT id FROM tags WHERE name = ?', [$name])['id'];
    }

    private function addType(string $name): int
    {
        $this->assertSame(302, $this->admin()->postWithToken('/tags/addtype', ['name' => $name])->status);
        return $this->typeId($name);
    }

    private function addTag(int $type, string $name): int
    {
        $this->assertSame(302, $this->admin()->postWithToken('/tags/addtag', ['tag_type_id' => (string) $type, 'name' => $name])->status);
        return $this->tagId($name);
    }

    private function typeOrder(): array
    {
        return array_column($this->db->all('SELECT name FROM tag_types ORDER BY position, id'), 'name');
    }

    public function test_only_an_admin_can_see_or_change_tags(): void
    {
        $this->assertSame(302, $this->client()->get('/tags')->status);
        $this->assertSame(403, $this->client($this->memberId)->get('/tags')->status);
        $this->assertSame(403, $this->client($this->memberId)->postWithToken('/tags/addtype', ['name' => 'Nope'])->status);
        $this->assertSame(403, $this->client($this->memberId)->postWithToken('/tags/addtag', ['tag_type_id' => '1', 'name' => 'Nope'])->status);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM tag_types WHERE name = 'Nope'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM tags WHERE name = 'Nope'"));
    }

    public function test_a_post_without_the_csrf_token_is_rejected(): void
    {
        $this->assertSame(403, $this->admin()->post('/tags/addtype', ['name' => 'Sneaky'])->status);
        $this->assertSame(403, $this->admin()->post('/tags/addtag', ['tag_type_id' => (string) $this->typeId('genre'), 'name' => 'Sneaky'])->status);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM tag_types WHERE name = 'Sneaky'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM tags WHERE name = 'Sneaky'"));
    }

    public function test_the_page_lists_types_and_tags_and_links_to_wrangling(): void
    {
        $body = $this->admin()->get('/tags')->body;
        $this->assertStringContainsString('Fantasy', $body);
        $this->assertStringContainsString('href="/wrangling"', $body);
    }

    public function test_add_and_rename_a_tag_type(): void
    {
        $id = $this->addType('theme');
        $this->assertSame(302, $this->admin()->postWithToken('/tags/updatetype/' . $id, ['name' => 'themes'])->status);
        $this->assertSame('themes', $this->row('SELECT name FROM tag_types WHERE id = ?', [$id])['name']);
        $this->assertSame(200, $this->admin()->get('/tags/edittype/' . $id)->status);
        // blank and duplicate names are refused
        $this->assertSame(422, $this->admin()->postWithToken('/tags/addtype', ['name' => ' '])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/tags/addtype', ['name' => 'GENRE'])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/tags/updatetype/' . $id, ['name' => 'genre'])->status);
        $this->assertSame(404, $this->admin()->postWithToken('/tags/updatetype/9999', ['name' => 'x'])->status);
    }

    public function test_tag_types_reorder_and_the_story_form_follows(): void
    {
        $this->assertSame(['genre', 'content'], $this->typeOrder());
        $this->assertSame(302, $this->admin()->postWithToken('/tags/movetype/' . $this->typeId('content') . '/up')->status);
        $this->assertSame(['content', 'genre'], $this->typeOrder());
        $form = $this->client($this->authorId)->get('/story/new')->body;
        $this->assertLessThan(strpos($form, 'Fantasy'), strpos($form, 'Coming of age'), 'the content tags now come first on the story form');
        $this->admin()->postWithToken('/tags/movetype/' . $this->typeId('content') . '/up'); // already first
        $this->assertSame(['content', 'genre'], $this->typeOrder());
        $this->assertSame(404, $this->admin()->postWithToken('/tags/movetype/' . $this->typeId('genre') . '/sideways')->status);
    }

    public function test_a_type_deletes_only_when_empty(): void
    {
        $empty = $this->addType('empty-type');
        $this->assertStringContainsString('empty-type', $this->admin()->get('/tags/deletetype/' . $empty)->body);
        $this->assertSame(302, $this->admin()->postWithToken('/tags/deletetype/' . $empty)->status);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM tag_types WHERE id = ?', [$empty]));
        $genre = $this->typeId('genre');
        $res = $this->admin()->postWithToken('/tags/deletetype/' . $genre);
        $this->assertSame(422, $res->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM tag_types WHERE id = ?', [$genre]));
    }

    public function test_add_and_rename_a_tag(): void
    {
        $genre = $this->typeId('genre');
        $id = $this->addTag($genre, 'Mystery');
        $this->assertSame($genre, (int) $this->row('SELECT tag_type_id FROM tags WHERE id = ?', [$id])['tag_type_id']);
        $this->assertSame(200, $this->admin()->get('/tags/edit/' . $id)->status);
        $this->assertSame(302, $this->admin()->postWithToken('/tags/update/' . $id, ['name' => 'Whodunit'])->status);
        $this->assertSame('Whodunit', $this->row('SELECT name FROM tags WHERE id = ?', [$id])['name']);
        $this->assertSame(422, $this->admin()->postWithToken('/tags/update/' . $id, ['name' => 'fantasy'])->status, 'same type, same name');
        $this->assertSame(422, $this->admin()->postWithToken('/tags/addtag', ['tag_type_id' => (string) $genre, 'name' => 'Fantasy'])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/tags/addtag', ['tag_type_id' => '9999', 'name' => 'Lost'])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/tags/addtag', ['tag_type_id' => (string) $genre, 'name' => ''])->status);
        $this->assertSame(404, $this->admin()->postWithToken('/tags/update/9999', ['name' => 'x'])->status);
        // the same name is fine under a different type
        $this->addTag($this->typeId('content'), 'Fantasy');
    }

    public function test_a_merged_tag_shows_its_canonical(): void
    {
        $genre = $this->typeId('genre');
        $syn = $this->addTag($genre, 'Fantasyish');
        $this->db->query('UPDATE tags SET canonical_id = ? WHERE id = ?', [$this->tagId('Fantasy'), $syn]);
        $this->assertMatchesRegularExpression('/Fantasyish.*merged into.*Fantasy/s', $this->admin()->get('/tags')->body);
    }

    public function test_delete_is_refused_while_stories_use_the_tag_and_can_remove_then_delete(): void
    {
        $id = $this->addTag($this->typeId('genre'), 'Cozy');
        $this->db->query("INSERT INTO story_tags (story_id, tag_id) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), ?)", [$id]);
        $this->assertStringContainsString('Cozy', $this->admin()->get('/tags/delete/' . $id)->body);
        $res = $this->admin()->postWithToken('/tags/delete/' . $id);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Remove it from', $res->body);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM tags WHERE id = ?', [$id]));
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM story_tags WHERE tag_id = ?', [$id]));
        $this->assertSame(302, $this->admin()->postWithToken('/tags/delete/' . $id, ['remove' => '1'])->status);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM tags WHERE id = ?', [$id]));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM story_tags WHERE tag_id = ?', [$id]));
    }

    public function test_deleting_a_canonical_tag_frees_its_synonyms(): void
    {
        $canon = $this->addTag($this->typeId('genre'), 'Canon');
        $syn = $this->addTag($this->typeId('genre'), 'Fantasyish');
        $this->db->query('UPDATE tags SET canonical_id = ? WHERE id = ?', [$canon, $syn]);
        $this->assertSame(302, $this->admin()->postWithToken('/tags/delete/' . $canon)->status);
        $this->assertNull($this->row('SELECT canonical_id FROM tags WHERE id = ?', [$syn])['canonical_id']);
    }

    public function test_a_write_purges_the_page_cache(): void
    {
        $file = $this->plantCachedPage();
        $this->addType('fresh');
        $this->assertFileDoesNotExist($file);
        $file = $this->plantCachedPage();
        $this->addTag($this->typeId('genre'), 'Fresh tag');
        $this->assertFileDoesNotExist($file);
    }

    public function test_characters_is_offered_as_a_common_type_until_it_exists(): void
    {
        $this->assertStringContainsString('Add Characters', $this->admin()->get('/tags')->body);
        $this->addType('characters'); // any case counts as present
        $this->assertStringNotContainsString('Add Characters', $this->admin()->get('/tags')->body);
    }

    public function test_a_member_can_suggest_a_character_through_the_characters_type(): void
    {
        $type = $this->addType('Characters');
        $this->db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated) VALUES ('Char Story', 'char-story', 1, 1, 1)");
        $story = (int) $this->db->lastInsertId();
        $out = (new \App\Repositories\TagSuggestionRepository($this->db))->submit($story, 1, [$type => 'Lana Lang'], 'suggest');
        $this->assertSame(['Lana Lang'], $out['pending']);
        $this->assertStringContainsString('Lana Lang', $this->admin()->get('/tagqueue')->body);
    }
}
