<?php // app/Features/Story/Tests/LockedCategoryTest.php
namespace App\Features\Story\Tests;
use PHPUnit\Framework\Attributes\Small;
use App\Tests\Support\TaxonomyCase;

/** A locked category takes stories from admins only (the eFiction rule). */
#[Small]
final class LockedCategoryTest extends TaxonomyCase
{
    private int $locked;
    private int $general;

    protected function setUp(): void
    {
        parent::setUp();
        $this->general = (int) $this->row("SELECT id FROM categories WHERE slug = 'general'")['id'];
        $this->db->query("INSERT INTO categories (name, slug, locked) VALUES ('Staff Picks', 'staff-picks', 1)");
        $this->locked = (int) $this->db->lastInsertId();
    }

    private function fields(array $cats): array
    {
        return ['title' => 'The Rabbit Hole', 'summary' => 's', 'rating_id' => '1', 'categories' => array_map('strval', $cats)];
    }

    private function cats(string $slug): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT sc.category_id FROM story_categories sc JOIN stories s ON s.id = sc.story_id WHERE s.slug = ? ORDER BY 1', [$slug]), 'category_id'));
    }

    public function test_the_form_hides_a_locked_category_from_members_but_not_admins(): void
    {
        $this->assertStringNotContainsString('Staff Picks', $this->client($this->authorId)->get('/story/new')->body);
        $this->assertStringNotContainsString('Staff Picks', $this->client($this->authorId)->get('/story/edit/the-rabbit-hole')->body);
        $this->assertStringContainsString('Staff Picks', $this->admin()->get('/story/new')->body);
    }

    public function test_a_member_cannot_file_a_story_in_a_locked_category(): void
    {
        $res = $this->client($this->authorId)->postWithToken('/story/update/the-rabbit-hole', $this->fields([$this->general, $this->locked]));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('reserved for the site owner', $res->body);
        $this->assertNotContains($this->locked, $this->cats('the-rabbit-hole'));
        $res = $this->client($this->authorId)->postWithToken('/story/create', $this->fields([$this->locked]));
        $this->assertSame(422, $res->status);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM story_categories WHERE category_id = ?", [$this->locked]));
    }

    public function test_an_admin_can_use_a_locked_category(): void
    {
        $this->assertSame(302, $this->admin()->postWithToken('/story/update/the-rabbit-hole', $this->fields([$this->locked]))->status);
        $this->assertSame([$this->locked], $this->cats('the-rabbit-hole'));
    }

    public function test_a_story_already_in_a_locked_category_keeps_it_when_its_author_edits(): void
    {
        $this->db->query("INSERT OR IGNORE INTO story_categories (story_id, category_id) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), ?)", [$this->locked]);
        $form = $this->client($this->authorId)->get('/story/edit/the-rabbit-hole')->body;
        $this->assertStringContainsString('Staff Picks', $form, 'shown so it stays ticked');
        $res = $this->client($this->authorId)->postWithToken('/story/update/the-rabbit-hole', ['summary' => 'new'] + $this->fields([$this->general, $this->locked]));
        $this->assertSame(302, $res->status);
        $this->assertContains($this->locked, $this->cats('the-rabbit-hole'));
    }
}
