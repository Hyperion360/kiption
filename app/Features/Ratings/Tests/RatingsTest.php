<?php // app/Features/Ratings/Tests/RatingsTest.php
namespace App\Features\Ratings\Tests;
use App\Tests\Support\TaxonomyCase;

/** The owner's rating manager at /ratings: add, edit, order and delete the
 *  content ratings, including which ones are adult and what warning they show. */
final class RatingsTest extends TaxonomyCase
{
    private function add(string $label, bool $adult = false, string $warning = ''): int
    {
        $post = ['label' => $label, 'warning_text' => $warning] + ($adult ? ['is_adult' => '1'] : []);
        $this->assertSame(302, $this->admin()->postWithToken('/ratings/create', $post)->status);
        return (int) $this->row('SELECT id FROM ratings WHERE label = ?', [$label])['id'];
    }

    private function ratingId(string $label): int
    {
        return (int) $this->row('SELECT id FROM ratings WHERE label = ?', [$label])['id'];
    }

    public function test_only_an_admin_can_see_or_change_ratings(): void
    {
        $this->assertSame(302, $this->client()->get('/ratings')->status);
        $this->assertSame(403, $this->client($this->memberId)->get('/ratings')->status);
        $this->assertSame(403, $this->client($this->memberId)->postWithToken('/ratings/create', ['label' => 'Nope'])->status);
        $this->assertSame(200, $this->admin()->get('/ratings')->status);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM ratings WHERE label = 'Nope'"));
    }

    public function test_a_post_without_the_csrf_token_is_rejected(): void
    {
        $this->assertSame(403, $this->admin()->post('/ratings/create', ['label' => 'Sneaky'])->status);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM ratings WHERE label = 'Sneaky'"));
    }

    public function test_add_saves_label_adult_flag_and_warning_and_goes_last(): void
    {
        $id = $this->add('Graphic', true, 'Contains graphic violence.');
        $row = $this->row('SELECT * FROM ratings WHERE id = ?', [$id]);
        $this->assertSame(1, (int) $row['is_adult']);
        $this->assertSame('Contains graphic violence.', $row['warning_text']);
        $this->assertSame($id, (int) $this->row('SELECT id FROM ratings ORDER BY position DESC, id DESC LIMIT 1')['id']);
        $this->assertStringContainsString('Graphic', $this->admin()->get('/ratings')->body);
        $this->assertSame(422, $this->admin()->postWithToken('/ratings/create', ['label' => ' '])->status);
    }

    public function test_edit_changes_label_adult_flag_and_warning(): void
    {
        $id = $this->ratingId('Teen');
        $this->assertSame(200, $this->admin()->get('/ratings/edit/' . $id)->status);
        $this->assertSame(302, $this->admin()->postWithToken('/ratings/update/' . $id,
            ['label' => 'Teen and up', 'is_adult' => '1', 'warning_text' => 'Some mature themes.'])->status);
        $row = $this->row('SELECT * FROM ratings WHERE id = ?', [$id]);
        $this->assertSame(['Teen and up', 1, 'Some mature themes.'], [$row['label'], (int) $row['is_adult'], $row['warning_text']]);
        // unticking the box clears the flag
        $this->admin()->postWithToken('/ratings/update/' . $id, ['label' => 'Teen and up']);
        $this->assertSame(0, (int) $this->row('SELECT is_adult FROM ratings WHERE id = ?', [$id])['is_adult']);
        $this->assertSame(404, $this->admin()->postWithToken('/ratings/update/9999', ['label' => 'X'])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/ratings/update/' . $id, ['label' => ''])->status);
    }

    public function test_up_and_down_reorder(): void
    {
        $labels = fn (): array => array_column($this->db->all('SELECT label FROM ratings ORDER BY position, id'), 'label');
        $this->assertSame(['General', 'Teen', 'Mature', 'Explicit'], $labels());
        $this->admin()->postWithToken('/ratings/move/' . $this->ratingId('Mature') . '/up');
        $this->assertSame(['General', 'Mature', 'Teen', 'Explicit'], $labels());
        $this->admin()->postWithToken('/ratings/move/' . $this->ratingId('General') . '/up'); // first: stays
        $this->assertSame(['General', 'Mature', 'Teen', 'Explicit'], $labels());
        $this->admin()->postWithToken('/ratings/move/' . $this->ratingId('General') . '/down');
        $this->assertSame(['Mature', 'General', 'Teen', 'Explicit'], $labels());
        $this->assertSame(404, $this->admin()->postWithToken('/ratings/move/' . $this->ratingId('Teen') . '/left')->status);
    }

    public function test_an_unused_rating_deletes_after_a_confirm_page(): void
    {
        $id = $this->add('Spare');
        $this->assertStringContainsString('Spare', $this->admin()->get('/ratings/delete/' . $id)->body);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM ratings WHERE id = ?', [$id]));
        $this->assertSame(302, $this->admin()->postWithToken('/ratings/delete/' . $id)->status);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM ratings WHERE id = ?', [$id]));
    }

    public function test_delete_is_refused_while_stories_use_it_and_offers_reassign_then_delete(): void
    {
        $id = (int) $this->row('SELECT rating_id FROM stories LIMIT 1')['rating_id'];
        $inUse = $this->n('SELECT COUNT(*) c FROM stories WHERE rating_id = ?', [$id]);
        $res = $this->admin()->postWithToken('/ratings/delete/' . $id);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Move its stories', $res->body);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM ratings WHERE id = ?', [$id]));
        // reassign to a missing or the same rating is refused
        $this->assertSame(422, $this->admin()->postWithToken('/ratings/delete/' . $id, ['move_to' => (string) $id])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/ratings/delete/' . $id, ['move_to' => '9999'])->status);
        $target = $this->add('Target');
        $this->assertSame(302, $this->admin()->postWithToken('/ratings/delete/' . $id, ['move_to' => (string) $target])->status);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM ratings WHERE id = ?', [$id]));
        $this->assertSame($inUse, $this->n('SELECT COUNT(*) c FROM stories WHERE rating_id = ?', [$target]));
    }

    public function test_an_adult_rating_shows_its_warning_on_the_story_page_and_gates_the_reader(): void
    {
        $id = $this->add('Restricted', true, 'Not for younger readers.');
        $this->db->query("UPDATE stories SET rating_id = ? WHERE slug = 'the-rabbit-hole'", [$id]);
        $this->assertStringContainsString('Not for younger readers.', $this->client()->get('/story/view/the-rabbit-hole')->body);
        $read = $this->client()->get('/story/read/the-rabbit-hole/1')->body;
        $this->assertStringContainsString('/warning/accept', $read, 'the age warning stands in front of the prose');
        // a non-adult rating opens the reader straight away
        $this->db->query("UPDATE stories SET rating_id = ? WHERE slug = 'the-rabbit-hole'", [$this->ratingId('General')]);
        $this->assertStringNotContainsString('/warning/accept', $this->client()->get('/story/read/the-rabbit-hole/1')->body);
    }

    public function test_a_write_purges_the_page_cache(): void
    {
        $file = $this->plantCachedPage();
        $this->add('Fresh');
        $this->assertFileDoesNotExist($file);
    }
}
