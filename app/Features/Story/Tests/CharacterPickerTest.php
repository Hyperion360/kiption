<?php // app/Features/Story/Tests/CharacterPickerTest.php
namespace App\Features\Story\Tests;
use PHPUnit\Framework\Attributes\Small;
use App\Tests\Support\TaxonomyCase;

/** The character picker on the story form, and characters on the story page. */
#[Small]
final class CharacterPickerTest extends TaxonomyCase
{
    private int $general;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->general = (int) $this->row("SELECT id FROM categories WHERE slug = 'general'")['id'];
        $this->db->query("INSERT INTO categories (name, slug) VALUES ('Sci-fi', 'sci-fi')");
        $this->other = (int) $this->db->lastInsertId();
    }

    private function character(string $name, ?int $category): int
    {
        $this->db->query('INSERT INTO characters (category_id, name, slug) VALUES (?, ?, ?)', [$category, $name, strtolower($name)]);
        return (int) $this->db->lastInsertId();
    }

    private function ratingId(): int
    {
        return (int) $this->row("SELECT id FROM ratings WHERE label = 'General'")['id'];
    }

    private function linked(string $slug): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT sc.character_id FROM story_characters sc JOIN stories s ON s.id = sc.story_id WHERE s.slug = ? ORDER BY 1', [$slug]), 'character_id'));
    }

    private function save(string $slug, array $fields): int
    {
        return $this->client($this->authorId)->postWithToken('/story/update/' . $slug, $fields + [
            'title' => 'The Rabbit Hole', 'summary' => 's', 'rating_id' => (string) $this->ratingId(),
        ])->status;
    }

    public function test_the_form_offers_characters_grouped_by_category(): void
    {
        $this->character('Alice', $this->general);
        $this->character('Narrator', null);
        $this->character('Zed', $this->other);
        $body = $this->client($this->authorId)->get('/story/new')->body;
        foreach (['Alice', 'Narrator', 'Zed', 'name="characters[]"', 'name="characters_shown"', 'Any Category'] as $needle) {
            $this->assertStringContainsString($needle, $body);
        }
    }

    public function test_the_form_has_no_character_section_when_there_are_none(): void
    {
        $this->assertStringNotContainsString('characters_shown', $this->client($this->authorId)->get('/story/new')->body);
    }

    public function test_create_saves_characters(): void
    {
        $alice = $this->character('Alice', $this->general);
        $nar = $this->character('Narrator', null);
        $res = $this->client($this->authorId)->postWithToken('/story/create', [
            'title' => 'Tea Party', 'summary' => 's', 'rating_id' => (string) $this->ratingId(),
            'categories' => [(string) $this->general], 'characters_shown' => '1', 'characters' => [(string) $alice, (string) $nar],
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame([$alice, $nar], $this->linked('tea-party'));
    }

    public function test_edit_changes_the_characters_and_shows_them_checked(): void
    {
        $alice = $this->character('Alice', $this->general);
        $bob = $this->character('Bob', $this->general);
        $slug = 'the-rabbit-hole';
        $this->assertSame(302, $this->save($slug, ['categories' => [(string) $this->general], 'characters_shown' => '1', 'characters' => [(string) $alice]]));
        $this->assertSame([$alice], $this->linked($slug));
        $form = $this->client($this->authorId)->get('/story/edit/' . $slug)->body;
        $this->assertMatchesRegularExpression('/value="' . $alice . '" checked/', $form);
        $this->assertDoesNotMatchRegularExpression('/value="' . $bob . '" checked/', $form);
        $this->assertSame(302, $this->save($slug, ['categories' => [(string) $this->general], 'characters_shown' => '1', 'characters' => [(string) $bob]]));
        $this->assertSame([$bob], $this->linked($slug));
        $this->assertSame(302, $this->save($slug, ['categories' => [(string) $this->general], 'characters_shown' => '1']));
        $this->assertSame([], $this->linked($slug), 'all unticked clears them');
    }

    public function test_a_character_of_an_unchosen_category_is_rejected(): void
    {
        $alice = $this->character('Alice', $this->general);
        $zed = $this->character('Zed', $this->other);
        $fake = 99999;
        $this->save('the-rabbit-hole', ['categories' => [(string) $this->general], 'characters_shown' => '1',
            'characters' => [(string) $alice, (string) $zed, (string) $fake, 'abc']]);
        $this->assertSame([$alice], $this->linked('the-rabbit-hole'));
    }

    public function test_a_save_without_the_picker_leaves_characters_alone(): void
    {
        $alice = $this->character('Alice', $this->general);
        $this->save('the-rabbit-hole', ['categories' => [(string) $this->general], 'characters_shown' => '1', 'characters' => [(string) $alice]]);
        $this->save('the-rabbit-hole', ['categories' => [(string) $this->general]]);
        $this->assertSame([$alice], $this->linked('the-rabbit-hole'));
    }

    public function test_the_story_page_lists_its_characters(): void
    {
        $alice = $this->character('Alice', $this->general);
        $this->character('Bob', $this->general);
        $this->save('the-rabbit-hole', ['categories' => [(string) $this->general], 'characters_shown' => '1', 'characters' => [(string) $alice]]);
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Alice', $body);
        $this->assertStringNotContainsString('Bob', $body);
    }
}
