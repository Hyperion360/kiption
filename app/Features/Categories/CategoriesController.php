<?php // app/Features/Categories/CategoriesController.php
namespace App\Features\Categories;
use App\{AdminScreen, Lang, Slug};
use Kip\Http\Response;
use Kip\Routing\{Auth, Get, Post};

/** The owner's category manager (admin only; #[Auth] makes the CSRF token mandatory on
 *  every write). Categories nest through parent_id and sort by position. The slug is
 *  made once, on add, and never changes on rename, so links stay valid. A
 *  category that stories use, or that has subcategories or characters, is
 *  never deleted silently: the owner is told why and offered to move the
 *  stories and characters to another category first. */
final class CategoriesController extends AdminScreen
{
    #[Auth]
    public function index(): Response|string
    {
        return $this->deny() ?? $this->list(null);
    }

    #[Auth]
    public function edit(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        return $row === null ? new Response('Page not found', 404) : $this->editForm($row, null);
    }

    #[Auth] #[Post]
    public function create(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $name = $this->name();
        $parent = $this->parentId();
        if ($name === '') return new Response($this->list(Lang::t('categories.err_name')), 422);
        if ($parent !== null && $this->find((string) $parent) === null) return new Response($this->list(Lang::t('categories.err_parent')), 422);
        $slug = Slug::unique(fn (string $s): bool => $this->db->one('SELECT 1 AS x FROM categories WHERE slug = ?', [$s]) !== null, Slug::make($name, 'category'));
        $position = (int) $this->db->one('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM categories WHERE parent_id IS ?', [$parent])['p'];
        $this->db->query('INSERT INTO categories (parent_id, name, slug, description, position) VALUES (?, ?, ?, ?, ?)',
            [$parent, $name, $slug, $this->description(), $position]);
        $this->purge();
        return Response::redirect('/categories');
    }

    #[Auth] #[Post]
    public function update(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        if ($row === null) return new Response('Page not found', 404);
        $name = $this->name();
        $parent = $this->parentId();
        $error = null;
        if ($name === '') $error = Lang::t('categories.err_name');
        elseif ($parent !== null && $this->find((string) $parent) === null) $error = Lang::t('categories.err_parent');
        elseif ($parent !== null && ($parent === (int) $row['id'] || in_array($parent, $this->descendants((int) $row['id']), true))) {
            $error = Lang::t('categories.err_loop');
        }
        if ($error !== null) return new Response($this->editForm($row, $error), 422);
        // A move to a new parent goes to the end of that parent's list.
        $position = $parent === ($row['parent_id'] === null ? null : (int) $row['parent_id'])
            ? (int) $row['position']
            : (int) $this->db->one('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM categories WHERE parent_id IS ?', [$parent])['p'];
        $this->db->query('UPDATE categories SET name = ?, description = ?, parent_id = ?, locked = ?, position = ? WHERE id = ?',
            [$name, $this->description(), $parent, ($this->request->post['locked'] ?? '') === '1' ? 1 : 0, $position, (int) $row['id']]);
        $this->purge();
        return Response::redirect('/categories');
    }

    /** Swap the category with the sibling above or below it. Siblings are
     *  renumbered 1..n first, because freshly seeded rows all share position 0. */
    #[Auth] #[Post]
    public function move(string $id, string $dir): Response
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        if ($row === null || !in_array($dir, ['up', 'down'], true)) return new Response('Page not found', 404);
        $ids = array_map('intval', array_column($this->db->all(
            'SELECT id FROM categories WHERE parent_id IS ? ORDER BY position, id',
            [$row['parent_id'] === null ? null : (int) $row['parent_id']]), 'id'));
        $at = array_search((int) $row['id'], $ids, true);
        $to = $dir === 'up' ? $at - 1 : $at + 1;
        if (isset($ids[$to])) [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->db->begin();
        try {
            foreach ($ids as $i => $cid) $this->db->query('UPDATE categories SET position = ? WHERE id = ?', [$i + 1, $cid]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->purge();
        return Response::redirect('/categories');
    }

    /** GET shows the confirm step; POST deletes (or refuses with the reason). */
    #[Auth] #[Get] #[Post]
    public function delete(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        if ($row === null) return new Response('Page not found', 404);
        $use = $this->usage((int) $row['id']);
        if ($this->request->method !== 'POST') return $this->confirm($row, $use, null);

        $moveTo = $this->post('move_to');
        $target = null;
        if ($moveTo !== '') {
            $target = $this->find($moveTo);
            if ($target === null || (int) $target['id'] === (int) $row['id'] || in_array((int) $target['id'], $this->descendants((int) $row['id']), true)) {
                return new Response($this->confirm($row, $use, Lang::t('categories.err_target')), 422);
            }
        }
        if ($use['children'] > 0) return new Response($this->confirm($row, $use, Lang::t('categories.err_children', ['n' => $use['children']])), 422);
        if ($target === null && ($use['stories'] > 0 || $use['characters'] > 0)) {
            return new Response($this->confirm($row, $use, Lang::t('categories.err_in_use', ['n' => $use['stories'], 'c' => $use['characters']])), 422);
        }
        $this->db->begin();
        try {
            if ($target !== null) {
                // OR IGNORE: a story already in the target keeps one row, not two.
                $this->db->query('UPDATE OR IGNORE story_categories SET category_id = ? WHERE category_id = ?', [(int) $target['id'], (int) $row['id']]);
                $this->db->query('UPDATE characters SET category_id = ? WHERE category_id = ?', [(int) $target['id'], (int) $row['id']]);
            }
            $this->db->query('DELETE FROM categories WHERE id = ?', [(int) $row['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->purge();
        return Response::redirect('/categories');
    }

    private function name(): string
    {
        return mb_substr($this->post('name'), 0, 100);
    }

    private function description(): string
    {
        return mb_substr($this->post('description'), 0, 500);
    }

    private function parentId(): ?int
    {
        $p = $this->post('parent_id');
        return ctype_digit($p) && (int) $p > 0 ? (int) $p : null;
    }

    private function find(string $id): ?array
    {
        return ctype_digit($id) ? $this->db->one('SELECT * FROM categories WHERE id = ?', [(int) $id]) : null;
    }

    /** @return int[] every category id below $id, at any depth */
    private function descendants(int $id): array
    {
        $kids = [];
        foreach ($this->db->all('SELECT id, parent_id FROM categories WHERE parent_id IS NOT NULL') as $r) {
            $kids[(int) $r['parent_id']][] = (int) $r['id'];
        }
        $out = [];
        $stack = [$id];
        while ($stack) {
            foreach ($kids[array_pop($stack)] ?? [] as $k) { $out[] = $k; $stack[] = $k; }
        }
        return $out;
    }

    /** @return array{stories:int, children:int, characters:int} */
    private function usage(int $id): array
    {
        $r = $this->db->one(
            'SELECT (SELECT COUNT(*) FROM story_categories sc JOIN stories s ON s.id = sc.story_id AND s.deleted_at IS NULL WHERE sc.category_id = ?) AS stories,
                    (SELECT COUNT(*) FROM categories WHERE parent_id = ?) AS children,
                    (SELECT COUNT(*) FROM characters WHERE category_id = ?) AS characters',
            [$id, $id, $id]);
        return ['stories' => (int) $r['stories'], 'children' => (int) $r['children'], 'characters' => (int) $r['characters']];
    }

    /** The tree, flattened depth-first with each row's depth and story count. */
    private function list(?string $error): string
    {
        $rows = $this->db->all(
            'SELECT c.*, (SELECT COUNT(*) FROM story_categories sc JOIN stories s ON s.id = sc.story_id AND s.deleted_at IS NULL WHERE sc.category_id = c.id) AS story_count
             FROM categories c ORDER BY c.position, c.id');
        $byParent = [];
        foreach ($rows as $r) $byParent[(int) ($r['parent_id'] ?? 0)][] = $r;
        $tree = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$tree, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $r) {
                $tree[] = $r + ['depth' => $depth];
                $walk((int) $r['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $this->page('categories/index', 'categories.heading', '/categories',
            ['tree' => $tree, 'all' => $rows, 'error' => $error]);
    }

    private function editForm(array $row, ?string $error): string
    {
        $banned = array_merge([(int) $row['id']], $this->descendants((int) $row['id']));
        $options = array_values(array_filter($this->db->all('SELECT id, name FROM categories ORDER BY position, id'),
            static fn (array $c): bool => !in_array((int) $c['id'], $banned, true)));
        return $this->page('categories/edit', 'categories.edit_heading', '/categories/edit/' . (int) $row['id'],
            ['row' => $row, 'options' => $options, 'error' => $error]);
    }

    private function confirm(array $row, array $use, ?string $error): string
    {
        $others = $this->db->all('SELECT id, name FROM categories WHERE id != ? ORDER BY position, id', [(int) $row['id']]);
        $banned = $this->descendants((int) $row['id']);
        $others = array_values(array_filter($others, static fn (array $c): bool => !in_array((int) $c['id'], $banned, true)));
        return $this->page('categories/delete', 'categories.delete_heading', '/categories/delete/' . (int) $row['id'],
            ['row' => $row, 'use' => $use, 'others' => $others, 'error' => $error]);
    }
}
