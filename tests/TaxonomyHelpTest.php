<?php // tests/TaxonomyHelpTest.php
namespace App\Tests;
use App\Lang;
use App\Tests\Support\TaxonomyCase;

/** Owners are told what Categories, Characters and Tags are for, and can add
 *  the common tag types (Relationships first) in one click. */
final class TaxonomyHelpTest extends TaxonomyCase
{
    protected function tearDown(): void
    {
        Lang::reset();
        parent::tearDown();
    }

    private function relationships(): ?array
    {
        return $this->db->one("SELECT id, name FROM tag_types WHERE name = 'Relationships' COLLATE NOCASE");
    }

    public function test_tags_screen_offers_relationships_when_missing(): void
    {
        $this->db->query("DELETE FROM tag_types WHERE name = 'Relationships' COLLATE NOCASE");
        $html = $this->admin()->get('/tags')->body;
        $this->assertStringContainsString('Add Relationships', $html);
        $this->assertSame(302, $this->admin()->postWithToken('/tags/addtype', ['name' => 'Relationships'])->status);
        $this->assertNotNull($this->relationships());
        $this->assertStringNotContainsString('Add Relationships', $this->admin()->get('/tags')->body);
    }

    public function test_the_first_type_is_offered_as_a_plain_button(): void
    {
        $this->db->query('DELETE FROM story_tags');
        $this->db->query('DELETE FROM tags');
        $this->db->query('DELETE FROM tag_types');
        $html = $this->admin()->get('/tags')->body;
        $this->assertMatchesRegularExpression('#<button type="submit" class="btn">Add Relationships</button>#', $html);
        $this->assertStringContainsString('Add Warnings', $html);
        $this->assertStringContainsString('Add Genres', $html);
    }

    public function test_addtype_lands_on_new_type_with_focus(): void
    {
        $this->db->query("DELETE FROM tag_types WHERE name = 'Relationships' COLLATE NOCASE");
        $admin = $this->admin();
        $r = $admin->postWithToken('/tags/addtype', ['name' => 'Relationships']);
        $typeId = (int) $this->relationships()['id'];
        $this->assertSame(302, $r->status);
        $this->assertStringContainsString('saved=added', $r->headers['Location']);
        $this->assertStringContainsString('type=' . $typeId, $r->headers['Location']);
        $html = $admin->get('/tags', ['type' => (string) $typeId])->body;
        $this->assertStringContainsString('autofocus', $html);              // the add-tag name input
        $this->assertStringContainsString('placeholder="Clark/Lois"', $html);
        $this->assertStringContainsString('<option value="' . $typeId . '" selected>', $html);
        $this->assertStringContainsString("Relationship tags are free text. They aren't linked to Characters.", html_entity_decode($html, ENT_QUOTES));
    }

    public function test_other_types_get_no_placeholder_and_no_autofocus_without_a_type(): void
    {
        $html = $this->admin()->get('/tags')->body;
        $this->assertStringNotContainsString('autofocus', $html);
        $this->assertStringNotContainsString('placeholder="Clark/Lois"', $html);
        $genre = (int) $this->db->one("SELECT id FROM tag_types WHERE name <> 'Relationships' COLLATE NOCASE ORDER BY id LIMIT 1")['id'];
        $html = $this->admin()->get('/tags', ['type' => (string) $genre])->body;
        $this->assertStringContainsString('autofocus', $html);
        $this->assertStringNotContainsString('placeholder="Clark/Lois"', $html);
    }

    public function test_duplicate_type_name_is_refused_case_insensitively(): void
    {
        $this->db->query("DELETE FROM tag_types WHERE name = 'Relationships' COLLATE NOCASE");
        $admin = $this->admin();
        $admin->postWithToken('/tags/addtype', ['name' => 'Relationships']);
        $r = $admin->postWithToken('/tags/addtype', ['name' => 'relationships']); // a double click or a different case
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('relationships already exists.', $r->body);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) AS n FROM tag_types WHERE name = 'Relationships' COLLATE NOCASE")['n']);
    }

    public function test_renamed_label_does_not_reoffer_an_existing_type(): void
    {
        $this->db->query("DELETE FROM tag_types WHERE name = 'Relationships' COLLATE NOCASE");
        $this->admin()->postWithToken('/tags/addtype', ['name' => 'Relationships']);
        // Rename the label through Site text, as an owner would.
        \App\Features::init($this->db, []);
        Lang::useSiteText($this->cacheDir . '/site-text');
        $res = $this->admin()->postWithToken('/text/save', ['lang' => 'en', 'key' => 'tagsadmin.suggest_relationships', 'value' => 'Pairings']);
        $this->assertSame(302, $res->status, $res->body);
        $html = $this->admin()->get('/tags')->body;
        $this->assertStringNotContainsString('Add Pairings', $html);
        $this->assertStringNotContainsString('Add Relationships', $html);
    }

    public function test_categories_screen_says_categories_are_fandoms(): void
    {
        $html = $this->admin()->get('/categories')->body;
        $this->assertStringContainsString('class="admin-help"', $html);
        $this->assertStringContainsString('Categories are your fandoms or sections', $html);
    }

    public function test_characters_screen_links_to_tags_and_needs_a_category_first(): void
    {
        $this->db->query('DELETE FROM story_characters');
        $this->db->query('DELETE FROM characters');
        $this->db->query('DELETE FROM story_categories');
        $this->db->query('DELETE FROM categories');
        $html = $this->admin()->get('/characters')->body;
        $this->assertStringContainsString('<a href="/tags">Tags screen</a>', $html);
        $this->assertStringContainsString('Add a category (fandom) first.', $html);
        $this->assertStringContainsString('href="/categories"', $html);
    }

    public function test_characters_screen_does_not_nag_once_a_category_exists(): void
    {
        $this->assertGreaterThan(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM categories')['n']);
        $this->assertStringNotContainsString('Add a category (fandom) first.', $this->admin()->get('/characters')->body);
    }

    public function test_tags_screen_help_names_the_common_types(): void
    {
        $this->assertStringContainsString('Common types are Genres, Warnings and Relationships.', html_entity_decode($this->admin()->get('/tags')->body));
    }
}
