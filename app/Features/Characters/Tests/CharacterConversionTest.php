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

    private function link(int $story, int $character): void
    {
        $this->db->query('INSERT INTO story_characters (story_id, character_id) VALUES (?, ?)', [$story, $character]);
    }

    /** @return list<int> */
    private function stories(int $n): array
    {
        return array_map('intval', array_column($this->db->all('SELECT id FROM stories ORDER BY id LIMIT ' . $n), 'id'));
    }

    private function convert(): array
    {
        return (new CharacterConversion($this->db))->run($this->adminId);
    }

    private function typeId(): int
    {
        return (int) $this->row("SELECT id FROM tag_types WHERE name = 'Characters' COLLATE NOCASE")['id'];
    }

    public function test_run_creates_the_type_one_tag_per_name_and_keeps_every_story_link(): void
    {
        [$s1, $s2] = $this->stories(2);
        $a = $this->character('Lana Lang');
        $b = $this->character('lana lang');
        $c = $this->character('Clark Kent');
        $this->link($s1, $a); $this->link($s2, $b); $this->link($s1, $c); $this->link($s2, $c);
        $r = $this->convert();
        $this->assertSame('converted', $r['status']);
        $this->assertSame(['Clark Kent', 'Lana Lang'], array_column($this->db->all('SELECT name FROM tags WHERE tag_type_id = ? ORDER BY name', [$this->typeId()]), 'name'));
        $lana = (int) $this->row("SELECT id FROM tags WHERE name = 'Lana Lang'")['id'];
        $this->assertSame(2, $this->n('SELECT COUNT(*) c FROM story_tags WHERE tag_id = ?', [$lana]), 'both spellings land on the one tag');
        $clark = (int) $this->row("SELECT id FROM tags WHERE name = 'Clark Kent'")['id'];
        $this->assertSame(2, $this->n('SELECT COUNT(*) c FROM story_tags WHERE tag_id = ?', [$clark]));
        $this->assertSame(2, $r['tags']);
        $this->assertSame(4, $r['links']);
    }

    public function test_run_removes_the_old_rows_after_the_counts_match(): void
    {
        [$s1] = $this->stories(1);
        $this->link($s1, $this->character('Lana Lang'));
        $this->convert();
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM characters'));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM story_characters'));
    }

    public function test_run_reuses_an_existing_characters_type_and_tag(): void
    {
        [$s1] = $this->stories(1);
        $this->db->query("INSERT INTO tag_types (name) VALUES ('characters')");
        $type = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO tags (tag_type_id, name) VALUES (?, 'Lana Lang')", [$type]);
        $tag = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$s1, $tag]);
        $this->link($s1, $this->character('lana lang'));
        $this->convert();
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tag_types WHERE name = 'Characters' COLLATE NOCASE"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tags WHERE name = 'Lana Lang' COLLATE NOCASE"));
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM story_tags WHERE tag_id = ?', [$tag]), 'an existing link is not doubled');
    }

    public function test_run_refuses_a_name_owned_by_another_type_and_changes_nothing(): void
    {
        $type = (int) $this->row("SELECT id FROM tag_types WHERE name = 'content'")['id'];
        $this->db->query("INSERT INTO tags (tag_type_id, name) VALUES (?, 'Slow burn')", [$type]);
        $this->character('Lana Lang');
        $this->character('slow burn');
        $tags = $this->n('SELECT COUNT(*) c FROM tags');
        $types = $this->n('SELECT COUNT(*) c FROM tag_types');
        $r = $this->convert();
        $this->assertSame('refused', $r['status']);
        $this->assertSame($tags, $this->n('SELECT COUNT(*) c FROM tags'));
        $this->assertSame($types, $this->n('SELECT COUNT(*) c FROM tag_types'));
        $this->assertSame(2, $this->n('SELECT COUNT(*) c FROM characters'));
    }

    public function test_run_refuses_an_invalid_name_and_changes_nothing(): void
    {
        $this->character('Lana Lang');
        $this->character('X');
        $tags = $this->n('SELECT COUNT(*) c FROM tags');
        $this->assertSame('refused', $this->convert()['status']);
        $this->assertSame($tags, $this->n('SELECT COUNT(*) c FROM tags'));
        $this->assertSame(2, $this->n('SELECT COUNT(*) c FROM characters'));
    }

    public function test_running_twice_does_nothing_the_second_time(): void
    {
        [$s1] = $this->stories(1);
        $this->link($s1, $this->character('Lana Lang'));
        $this->convert();
        $tags = $this->n('SELECT COUNT(*) c FROM tags');
        $links = $this->n('SELECT COUNT(*) c FROM story_tags');
        $this->assertSame('nothing', $this->convert()['status']);
        $this->assertSame($tags, $this->n('SELECT COUNT(*) c FROM tags'));
        $this->assertSame($links, $this->n('SELECT COUNT(*) c FROM story_tags'));
    }

    public function test_a_failure_midway_rolls_back_every_new_tag_and_type(): void
    {
        [$s1] = $this->stories(1);
        $this->link($s1, $this->character('Lana Lang'));
        $this->db->exec("CREATE TRIGGER boom BEFORE INSERT ON story_tags BEGIN SELECT RAISE(ABORT, 'forced'); END");
        $tags = $this->n('SELECT COUNT(*) c FROM tags');
        $types = $this->n('SELECT COUNT(*) c FROM tag_types');
        try {
            $this->convert();
            $this->fail('the forced failure should surface');
        } catch (\PDOException) {
        }
        $this->assertSame($tags, $this->n('SELECT COUNT(*) c FROM tags'));
        $this->assertSame($types, $this->n('SELECT COUNT(*) c FROM tag_types'));
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM characters'));
        $this->assertSame(0, $this->db->transactionDepth());
    }

    public function test_a_count_mismatch_keeps_the_old_rows_and_rolls_back(): void
    {
        [$s1] = $this->stories(1);
        $this->link($s1, $this->character('Lana Lang'));
        $this->db->exec('CREATE TRIGGER eat AFTER INSERT ON story_tags BEGIN DELETE FROM story_tags WHERE story_id = NEW.story_id AND tag_id = NEW.tag_id; END');
        $tags = $this->n('SELECT COUNT(*) c FROM tags');
        try {
            $this->convert();
            $this->fail('a short link count must abort the conversion');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM characters'));
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM story_characters'));
        $this->assertSame($tags, $this->n('SELECT COUNT(*) c FROM tags'));
    }

    public function test_run_writes_one_activity_line_naming_the_admin(): void
    {
        $this->character('Lana Lang');
        $this->convert();
        $row = $this->row("SELECT actor_id, detail FROM activity_log WHERE action = 'characters.convert'");
        $this->assertSame($this->adminId, (int) $row['actor_id']);
        $this->assertStringContainsString('1', (string) $row['detail']);
    }

    public function test_the_admin_panel_shows_the_preview_and_the_confirm_word(): void
    {
        $general = (int) $this->row("SELECT id FROM categories WHERE slug = 'general'")['id'];
        $this->character('Alice', $general);
        $body = $this->admin()->get('/characters')->body;
        $this->assertStringContainsString('Convert to Tags', $body);
        $this->assertStringContainsString('action="/characters/convert"', $body);
        $this->assertStringContainsString('Alice', $body);
        $this->assertStringContainsString('General', $body);
    }

    public function test_no_panel_when_there_are_no_characters(): void
    {
        $this->assertStringNotContainsString('/characters/convert', $this->admin()->get('/characters')->body);
    }

    public function test_the_convert_action_needs_an_admin_and_a_token(): void
    {
        $this->character('Lana Lang');
        $this->assertContains($this->client()->postWithToken('/characters/convert', ['confirm' => 'convert'])->status, [302, 403]);
        $this->assertSame(403, $this->client($this->memberId)->postWithToken('/characters/convert', ['confirm' => 'convert'])->status);
        $this->assertSame(403, $this->admin()->post('/characters/convert', ['confirm' => 'convert'])->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM characters'));
    }

    public function test_the_wrong_confirm_word_converts_nothing(): void
    {
        $this->character('Lana Lang');
        $res = $this->admin()->postWithToken('/characters/convert', ['confirm' => 'yes']);
        $this->assertSame(422, $res->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM characters'));
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM tag_types WHERE name = 'Characters'"));
    }

    public function test_the_right_confirm_word_converts_and_purges_the_cache(): void
    {
        $this->character('Lana Lang');
        $planted = $this->plantCachedPage();
        $res = $this->admin()->postWithToken('/characters/convert', ['confirm' => 'Convert']);
        $this->assertSame(302, $res->status);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM characters'));
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tags WHERE name = 'Lana Lang'"));
        $this->assertFileDoesNotExist($planted);
    }

    public function test_a_blocked_name_is_refused_with_the_reason_on_the_page(): void
    {
        $type = (int) $this->row("SELECT id FROM tag_types WHERE name = 'content'")['id'];
        $this->db->query("INSERT INTO tags (tag_type_id, name) VALUES (?, 'Slow burn')", [$type]);
        $this->character('slow burn');
        $res = $this->admin()->postWithToken('/characters/convert', ['confirm' => 'convert']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('content', $res->body);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM characters'));
    }

    public function test_after_conversion_the_story_page_shows_the_character_as_a_tag_link(): void
    {
        $story = $this->row("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->link((int) $story['id'], $this->character('Lana Lang'));
        $this->convert();
        $tag = (int) $this->row("SELECT id FROM tags WHERE name = 'Lana Lang'")['id'];
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Lana Lang', $body);
        $this->assertStringContainsString('tag=' . $tag, $body);
        $this->assertStringContainsString('the-rabbit-hole', $this->client()->get('/search', ['tag' => (string) $tag])->body);
    }
}
