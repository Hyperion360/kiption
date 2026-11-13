<?php // app/Features/Characters/CharactersController.php
namespace App\Features\Characters;
use App\{AdminScreen, Lang, Slug};
use Kip\Http\Response;
use Kip\Routing\{Auth, Get, Post};

/** The owner's character manager (admin only; #[Auth] makes the CSRF token mandatory
 *  on every write). A character belongs to one category, or to "any category"
 *  (category_id NULL) so it can be offered whatever categories a story picks.
 *  The slug is made once, on add, and never changes on rename. A character
 *  that stories use is never deleted silently: the owner must tick "remove it
 *  from those stories". */
final class CharactersController extends AdminScreen
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
        return $row === null ? new Response(\App\Lang::t('error.not_found'), 404) : $this->editForm($row, null);
    }

    #[Auth] #[Post]
    public function create(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $name = $this->name();
        $category = $this->categoryId();
        if ($name === '') return new Response($this->list(Lang::t('characters.err_name')), 422);
        if ($category === false) return new Response($this->list(Lang::t('characters.err_category')), 422);
        $slug = Slug::unique(fn (string $s): bool => $this->db->one('SELECT 1 AS x FROM characters WHERE slug = ?', [$s]) !== null, Slug::make($name, 'character'));
        $this->db->query('INSERT INTO characters (category_id, name, slug, description) VALUES (?, ?, ?, ?)',
            [$category, $name, $slug, $this->description()]);
        $this->purge();
        return Response::redirect('/characters');
    }

    #[Auth] #[Post]
    public function update(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        if ($row === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $name = $this->name();
        $category = $this->categoryId();
        $error = $name === '' ? Lang::t('characters.err_name') : ($category === false ? Lang::t('characters.err_category') : null);
        if ($error !== null) return new Response($this->editForm($row, $error), 422);
        $this->db->query('UPDATE characters SET name = ?, category_id = ?, description = ? WHERE id = ?',
            [$name, $category, $this->description(), (int) $row['id']]);
        $this->purge();
        return Response::redirect('/characters');
    }

    #[Auth] #[Get] #[Post]
    public function delete(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        if ($row === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $stories = (int) $this->db->one('SELECT COUNT(*) AS c FROM story_characters WHERE character_id = ?', [(int) $row['id']])['c'];
        if ($this->request->method !== 'POST') return $this->confirm($row, $stories, null);
        if ($stories > 0 && ($this->request->post['remove'] ?? '') !== '1') {
            return new Response($this->confirm($row, $stories, Lang::t('characters.err_in_use', ['n' => $stories])), 422);
        }
        $this->atomically(function () use ($row): void {
            $this->db->query('DELETE FROM story_characters WHERE character_id = ?', [(int) $row['id']]);
            $this->db->query('DELETE FROM characters WHERE id = ?', [(int) $row['id']]);
        });
        $this->purge();
        return Response::redirect('/characters');
    }

    private function name(): string
    {
        return mb_substr($this->post('name'), 0, 80);
    }

    private function description(): string
    {
        return mb_substr($this->post('description'), 0, 500);
    }

    /** null = any category, false = a category id that does not exist. */
    private function categoryId(): int|false|null
    {
        $raw = $this->post('category_id');
        if ($raw === '' || $raw === '0') return null;
        if (!ctype_digit($raw)) return false;
        return $this->db->one('SELECT 1 AS x FROM categories WHERE id = ?', [(int) $raw]) === null ? false : (int) $raw;
    }

    private function find(string $id): ?array
    {
        return ctype_digit($id) ? $this->db->one('SELECT * FROM characters WHERE id = ?', [(int) $id]) : null;
    }

    private function categories(): array
    {
        return $this->db->all('SELECT id, name FROM categories ORDER BY name COLLATE NOCASE, id');
    }

    private function list(?string $error): string
    {
        $rows = $this->db->all(
            'SELECT ch.id, ch.name, ch.category_id, c.name AS category_name,
                    (SELECT COUNT(*) FROM story_characters sc WHERE sc.character_id = ch.id) AS story_count
             FROM characters ch LEFT JOIN categories c ON c.id = ch.category_id
             ORDER BY ch.name COLLATE NOCASE, ch.id');
        return $this->page('characters/index', 'characters.heading', '/characters',
            ['characters' => $rows, 'categories' => $this->categories(), 'error' => $error]);
    }

    private function editForm(array $row, ?string $error): string
    {
        return $this->page('characters/edit', 'characters.edit_heading', '/characters/edit/' . (int) $row['id'],
            ['character' => $row, 'categories' => $this->categories(), 'error' => $error]);
    }

    private function confirm(array $row, int $stories, ?string $error): string
    {
        return $this->page('characters/delete', 'characters.delete_heading', '/characters/delete/' . (int) $row['id'],
            ['character' => $row, 'stories' => $stories, 'error' => $error]);
    }
}
