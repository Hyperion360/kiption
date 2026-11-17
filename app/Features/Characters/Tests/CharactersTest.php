<?php // app/Features/Characters/Tests/CharactersTest.php
namespace App\Features\Characters\Tests;
use PHPUnit\Framework\Attributes\Small;
use App\Tests\Support\TaxonomyCase;

/** The owner's character manager at /characters. */
#[Small]
final class CharactersTest extends TaxonomyCase
{
    private function general(): int
    {
        return (int) $this->row("SELECT id FROM categories WHERE slug = 'general'")['id'];
    }

    private function add(string $name, ?int $category): int
    {
        $res = $this->admin()->postWithToken('/characters/create', ['name' => $name, 'category_id' => (string) ($category ?? 0), 'description' => 'Bio']);
        $this->assertSame(302, $res->status);
        return (int) $this->row('SELECT id FROM characters WHERE name = ? ORDER BY id DESC', [$name])['id'];
    }

    public function test_only_an_admin_can_see_or_change_characters(): void
    {
        $this->assertSame(302, $this->client()->get('/characters')->status);
        $this->assertSame(403, $this->client($this->memberId)->get('/characters')->status);
        $this->assertSame(403, $this->client($this->memberId)->postWithToken('/characters/create', ['name' => 'Nope'])->status);
        $this->assertSame(403, $this->admin()->post('/characters/create', ['name' => 'Nope'])->status, 'no CSRF token');
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM characters WHERE name = 'Nope'"));
    }

    public function test_add_a_character_in_a_category_or_any_category(): void
    {
        $a = $this->add('Alice', $this->general());
        $b = $this->add('Narrator', null);
        $this->assertSame($this->general(), (int) $this->row('SELECT category_id FROM characters WHERE id = ?', [$a])['category_id']);
        $this->assertNull($this->row('SELECT category_id FROM characters WHERE id = ?', [$b])['category_id']);
        $body = $this->admin()->get('/characters')->body;
        $this->assertStringContainsString('Alice', $body);
        $this->assertStringContainsString('Any category', $body);
    }

    public function test_the_slug_is_unique_and_stable_across_a_rename(): void
    {
        $a = $this->add('Alice', null);
        $b = $this->add('Alice', null);
        $slugA = $this->row('SELECT slug FROM characters WHERE id = ?', [$a])['slug'];
        $this->assertNotSame($slugA, $this->row('SELECT slug FROM characters WHERE id = ?', [$b])['slug']);
        $this->assertSame(302, $this->admin()->postWithToken('/characters/update/' . $a, ['name' => 'Alicia', 'category_id' => '0'])->status);
        $this->assertSame($slugA, $this->row('SELECT slug FROM characters WHERE id = ?', [$a])['slug']);
    }

    public function test_rename_and_change_category(): void
    {
        $id = $this->add('Bob', null);
        $this->assertSame(200, $this->admin()->get('/characters/edit/' . $id)->status);
        $this->assertSame(302, $this->admin()->postWithToken('/characters/update/' . $id, ['name' => 'Robert', 'category_id' => (string) $this->general(), 'description' => 'New'])->status);
        $row = $this->row('SELECT * FROM characters WHERE id = ?', [$id]);
        $this->assertSame('Robert', $row['name']);
        $this->assertSame($this->general(), (int) $row['category_id']);
        $this->assertSame(302, $this->admin()->postWithToken('/characters/update/' . $id, ['name' => 'Robert', 'category_id' => '0'])->status);
        $this->assertNull($this->row('SELECT category_id FROM characters WHERE id = ?', [$id])['category_id']);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->assertSame(422, $this->admin()->postWithToken('/characters/create', ['name' => ' ', 'category_id' => '0'])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/characters/create', ['name' => 'X', 'category_id' => '9999'])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/characters/create', ['name' => 'X', 'category_id' => 'abc'])->status);
        $id = $this->add('Eve', null);
        $this->assertSame(422, $this->admin()->postWithToken('/characters/update/' . $id, ['name' => '', 'category_id' => '0'])->status);
        $this->assertSame(404, $this->admin()->postWithToken('/characters/update/9999', ['name' => 'x', 'category_id' => '0'])->status);
        $this->assertSame(404, $this->admin()->get('/characters/edit/9999')->status);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM characters WHERE name = 'X'"));
    }

    public function test_an_any_category_character_survives_the_migration_shape(): void
    {
        $info = array_column($this->db->all('PRAGMA table_info(characters)'), null, 'name');
        $this->assertSame(0, (int) $info['category_id']['notnull']);
    }

    public function test_delete_unused_character(): void
    {
        $id = $this->add('Temp', null);
        $this->assertStringContainsString('Temp', $this->admin()->get('/characters/delete/' . $id)->body);
        $this->assertSame(302, $this->admin()->postWithToken('/characters/delete/' . $id)->status);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM characters WHERE id = ?', [$id]));
    }

    public function test_delete_is_refused_while_stories_use_it_and_can_remove_then_delete(): void
    {
        $id = $this->add('Used', null);
        $this->db->query("INSERT INTO story_characters (story_id, character_id) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), ?)", [$id]);
        $res = $this->admin()->postWithToken('/characters/delete/' . $id);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Remove it from', $res->body);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM characters WHERE id = ?', [$id]));
        $this->assertSame(302, $this->admin()->postWithToken('/characters/delete/' . $id, ['remove' => '1'])->status);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM characters WHERE id = ?', [$id]));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM story_characters WHERE character_id = ?', [$id]));
    }

    public function test_rebuilding_the_table_keeps_characters_and_story_links(): void
    {
        $id = $this->add('Linked', $this->general());
        $this->db->query("INSERT INTO story_characters (story_id, character_id) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), ?)", [$id]);
        // Run the migration body again over populated tables: the cascade on DROP TABLE must not eat the links.
        (require dirname(__DIR__) . '/migrations/050_characters_any_category.php')->up($this->db);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM story_characters WHERE character_id = ?', [$id]));
        $this->assertSame('Linked', $this->row('SELECT name FROM characters WHERE id = ?', [$id])['name']);
    }

    public function test_a_write_purges_the_page_cache(): void
    {
        $file = $this->plantCachedPage();
        $this->add('Fresh', null);
        $this->assertFileDoesNotExist($file);
    }
}
