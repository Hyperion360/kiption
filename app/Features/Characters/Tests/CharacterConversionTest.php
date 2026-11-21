<?php // app/Features/Characters/Tests/CharacterConversionTest.php
namespace App\Features\Characters\Tests;
use App\Features\Characters\CharacterConversion;
use App\Tests\Support\TaxonomyCase;
use PHPUnit\Framework\Attributes\Small;

/** The read-only preview of folding characters into a tag type. */
#[Small]
final class CharacterConversionTest extends TaxonomyCase
{
    private function character(string $name, ?int $category = null): int
    {
        $this->db->query('INSERT INTO characters (category_id, name, slug) VALUES (?, ?, ?)', [$category, $name, 'c-' . md5($name . microtime())]);
        return (int) $this->db->lastInsertId();
    }

    private function plan(): array
    {
        return (new CharacterConversion($this->db))->plan();
    }

    public function test_an_empty_list_has_nothing_to_convert(): void
    {
        $p = $this->plan();
        $this->assertSame(0, $p['characters']);
        $this->assertSame([], $p['blocked']);
    }

    public function test_it_counts_characters_and_linked_stories(): void
    {
        $a = $this->character('Lana Lang');
        $this->character('Clark Kent');
        $story = (int) $this->row('SELECT id FROM stories LIMIT 1')['id'];
        $this->db->query('INSERT INTO story_characters (story_id, character_id) VALUES (?, ?)', [$story, $a]);
        $p = $this->plan();
        $this->assertSame(2, $p['characters']);
        $this->assertSame(1, $p['stories']);
    }

    public function test_case_variants_merge_into_the_first_spelling(): void
    {
        $this->character('Lana Lang');
        $this->character('lana lang');
        $p = $this->plan();
        $this->assertSame(['Lana Lang' => ['lana lang']], $p['merges']);
        $this->assertSame([], $p['blocked']);
    }

    public function test_a_name_owned_by_another_tag_type_is_blocked(): void
    {
        $type = (int) $this->row("SELECT id FROM tag_types WHERE name = 'content'")['id'];
        $this->db->query("INSERT INTO tags (tag_type_id, name) VALUES (?, 'Slow burn')", [$type]);
        $this->character('slow BURN');
        $this->assertSame(['slow BURN' => 'content'], $this->plan()['blocked']);
    }

    public function test_a_name_already_in_the_characters_type_is_reused_not_blocked(): void
    {
        $this->db->query("INSERT INTO tag_types (name) VALUES ('characters')");
        $type = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO tags (tag_type_id, name) VALUES (?, 'Lana Lang')", [$type]);
        $this->character('lana lang');
        $p = $this->plan();
        $this->assertSame(['lana lang'], $p['reused']);
        $this->assertSame([], $p['blocked']);
    }

    public function test_bad_names_and_category_bound_characters_are_listed(): void
    {
        $general = (int) $this->row("SELECT id FROM categories WHERE slug = 'general'")['id'];
        $this->character('X');           // too short
        $this->character('<b>Bold</b>'); // markup
        $this->character('Alice', $general);
        $p = $this->plan();
        $this->assertSame(['X', '<b>Bold</b>'], $p['invalid']);
        $this->assertSame(['Alice' => 'General'], $p['categoryBound']);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $this->character('Lana Lang');
        $before = $this->n('SELECT COUNT(*) c FROM tags');
        $this->plan();
        $this->assertSame($before, $this->n('SELECT COUNT(*) c FROM tags'));
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM characters'));
    }
}
