<?php // app/Features/Categories/Tests/CategoriesTest.php
namespace App\Features\Categories\Tests;
use App\Tests\Support\TaxonomyCase;

/** The owner's category manager at /categories: a nested tree they can add to,
 *  rename, move, reorder, lock and delete without touching the database. */
final class CategoriesTest extends TaxonomyCase
{
    private function add(string $name, ?int $parent = null): int
    {
        $this->assertSame(302, $this->admin()->postWithToken('/categories/create', ['name' => $name, 'parent_id' => (string) $parent])->status);
        return (int) $this->row('SELECT id FROM categories WHERE name = ?', [$name])['id'];
    }

    private function general(): int
    {
        return (int) $this->row("SELECT id FROM categories WHERE slug = 'general'")['id'];
    }

    public function test_only_an_admin_can_see_or_change_categories(): void
    {
        $this->assertSame(302, $this->client()->get('/categories')->status, 'a guest is sent to sign in');
        $this->assertSame(403, $this->client($this->memberId)->get('/categories')->status);
        $this->assertSame(403, $this->client($this->memberId)->postWithToken('/categories/create', ['name' => 'Nope'])->status);
        $this->assertSame(200, $this->admin()->get('/categories')->status);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM categories WHERE name = 'Nope'"));
    }

    public function test_a_post_without_the_csrf_token_is_rejected(): void
    {
        $this->assertSame(403, $this->admin()->post('/categories/create', ['name' => 'Sneaky'])->status);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM categories WHERE name = 'Sneaky'"));
    }

    public function test_adding_makes_a_slug_and_nests_under_a_parent(): void
    {
        $fan = $this->add('Fan Fiction');
        $kid = $this->add('Space Operas', $fan);
        $this->assertSame('fan-fiction', $this->row('SELECT slug FROM categories WHERE id = ?', [$fan])['slug']);
        $this->assertSame($fan, (int) $this->row('SELECT parent_id FROM categories WHERE id = ?', [$kid])['parent_id']);
        $body = $this->admin()->get('/categories')->body;
        $this->assertStringContainsString('Space Operas', $body);
        // a second category with the same name still gets its own slug
        $this->add('Fan Fiction');
        $this->assertSame(2, $this->n("SELECT COUNT(*) c FROM categories WHERE name = 'Fan Fiction'"));
        $this->assertSame(2, $this->n("SELECT COUNT(DISTINCT slug) c FROM categories WHERE name = 'Fan Fiction'"));
    }

    public function test_a_blank_name_is_refused(): void
    {
        $before = $this->n('SELECT COUNT(*) c FROM categories');
        $this->assertSame(422, $this->admin()->postWithToken('/categories/create', ['name' => '   '])->status);
        $this->assertSame($before, $this->n('SELECT COUNT(*) c FROM categories'));
    }

    public function test_renaming_keeps_the_slug_and_saves_description_and_lock(): void
    {
        $id = $this->general();
        $this->assertSame(302, $this->admin()->postWithToken('/categories/update/' . $id,
            ['name' => 'Everything', 'description' => 'All sorts.', 'parent_id' => '', 'locked' => '1'])->status);
        $row = $this->row('SELECT * FROM categories WHERE id = ?', [$id]);
        $this->assertSame('Everything', $row['name']);
        $this->assertSame('general', $row['slug']);
        $this->assertSame('All sorts.', $row['description']);
        $this->assertSame(1, (int) $row['locked']);
        $this->assertStringContainsString('value="Everything"', $this->admin()->get('/categories/edit/' . $id)->body);
    }

    public function test_a_category_can_move_under_another_but_never_under_itself_or_a_descendant(): void
    {
        $a = $this->add('A');
        $b = $this->add('B', $a);
        $c = $this->add('C', $b);
        $admin = $this->admin();
        $this->assertSame(422, $admin->postWithToken('/categories/update/' . $a, ['name' => 'A', 'parent_id' => (string) $a])->status);
        $this->assertSame(422, $admin->postWithToken('/categories/update/' . $a, ['name' => 'A', 'parent_id' => (string) $c])->status);
        $this->assertNull($this->row('SELECT parent_id FROM categories WHERE id = ?', [$a])['parent_id']);
        // a legal move works, and a missing parent is refused
        $this->assertSame(302, $admin->postWithToken('/categories/update/' . $c, ['name' => 'C', 'parent_id' => (string) $a])->status);
        $this->assertSame($a, (int) $this->row('SELECT parent_id FROM categories WHERE id = ?', [$c])['parent_id']);
        $this->assertSame(422, $admin->postWithToken('/categories/update/' . $c, ['name' => 'C', 'parent_id' => '9999'])->status);
        $this->assertSame(404, $admin->postWithToken('/categories/update/9999', ['name' => 'X'])->status);
    }

    public function test_up_and_down_reorder_siblings(): void
    {
        $x = $this->add('Xray');
        $y = $this->add('Yankee');
        $order = fn (): array => array_column($this->db->all('SELECT id FROM categories WHERE parent_id IS NULL ORDER BY position, id'), 'id');
        $this->assertSame([$this->general(), $x, $y], array_map('intval', $order()));
        $this->assertSame(302, $this->admin()->postWithToken('/categories/move/' . $y . '/up')->status);
        $this->assertSame([$this->general(), $y, $x], array_map('intval', $order()));
        $this->admin()->postWithToken('/categories/move/' . $y . '/up');
        $this->assertSame([$y, $this->general(), $x], array_map('intval', $order()));
        $this->admin()->postWithToken('/categories/move/' . $y . '/up'); // already first: no change, no error
        $this->assertSame([$y, $this->general(), $x], array_map('intval', $order()));
        $this->admin()->postWithToken('/categories/move/' . $y . '/down');
        $this->assertSame([$this->general(), $y, $x], array_map('intval', $order()));
        $this->assertSame(404, $this->admin()->postWithToken('/categories/move/' . $y . '/sideways')->status);
    }

    public function test_an_unused_category_deletes_after_a_confirm_page(): void
    {
        $id = $this->add('Empty One');
        $page = $this->admin()->get('/categories/delete/' . $id);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Empty One', $page->body);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM categories WHERE id = ?', [$id]), 'viewing the confirm page deletes nothing');
        $this->assertSame(302, $this->admin()->postWithToken('/categories/delete/' . $id)->status);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM categories WHERE id = ?', [$id]));
    }

    public function test_delete_is_refused_while_stories_use_the_category_and_says_how_many(): void
    {
        $id = $this->general();
        $inUse = $this->n('SELECT COUNT(*) c FROM story_categories WHERE category_id = ?', [$id]);
        $this->assertGreaterThan(0, $inUse);
        $res = $this->admin()->postWithToken('/categories/delete/' . $id);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Move its stories', $res->body);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM categories WHERE id = ?', [$id]));
        $this->assertSame($inUse, $this->n('SELECT COUNT(*) c FROM story_categories WHERE category_id = ?', [$id]));
    }

    public function test_move_stories_then_delete_works(): void
    {
        $id = $this->general();
        $target = $this->add('Elsewhere');
        $inUse = $this->n('SELECT COUNT(*) c FROM story_categories WHERE category_id = ?', [$id]);
        $this->assertSame(302, $this->admin()->postWithToken('/categories/delete/' . $id, ['move_to' => (string) $target])->status);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM categories WHERE id = ?', [$id]));
        $this->assertSame($inUse, $this->n('SELECT COUNT(*) c FROM story_categories WHERE category_id = ?', [$target]));
        // moving into itself or a missing category is refused and nothing is lost
        $other = $this->add('Other');
        $this->assertSame(422, $this->admin()->postWithToken('/categories/delete/' . $target, ['move_to' => (string) $target])->status);
        $this->assertSame(422, $this->admin()->postWithToken('/categories/delete/' . $target, ['move_to' => '9999'])->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM categories WHERE id = ?', [$target]));
        $this->assertSame($other, (int) $this->row('SELECT id FROM categories WHERE id = ?', [$other])['id']);
    }

    public function test_delete_is_refused_for_a_category_with_subcategories(): void
    {
        $parent = $this->add('Parent');
        $this->add('Child', $parent);
        $this->assertSame(422, $this->admin()->postWithToken('/categories/delete/' . $parent)->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM categories WHERE id = ?', [$parent]));
    }

    public function test_a_write_purges_the_page_cache(): void
    {
        $file = $this->plantCachedPage();
        $this->add('Fresh');
        $this->assertFileDoesNotExist($file);
    }
}
