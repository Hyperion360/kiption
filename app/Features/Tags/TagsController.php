<?php // app/Features/Tags/TagsController.php
namespace App\Features\Tags;
use App\{AdminScreen, Lang};
use Kip\Http\Response;
use Kip\Routing\{Auth, Get, Post};

/** The owner's tag type and tag manager (admin only; #[Auth] makes the CSRF
 *  token mandatory on every write). Tag types (genre, content, and so on) are
 *  ordered groups; tags live inside one type. Merging synonyms stays on
 *  /wrangling, which this page links to. A tag that stories use is never
 *  deleted silently: the owner must tick "remove it from those stories", and
 *  a type is only deleted once it holds no tags. Deleting a canonical tag
 *  frees the synonyms that were merged into it, because their foreign key
 *  would otherwise point at nothing. */
final class TagsController extends AdminScreen
{
    #[Auth]
    public function index(): Response|string
    {
        return $this->deny() ?? $this->list(null);
    }

    #[Auth]
    public function edittype(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $type = $this->findType($id);
        return $type === null ? new Response(\App\Lang::t('error.not_found'), 404) : $this->typeForm($type, null);
    }

    #[Auth]
    public function edit(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $tag = $this->findTag($id);
        return $tag === null ? new Response(\App\Lang::t('error.not_found'), 404) : $this->tagForm($tag, null);
    }

    #[Auth] #[Post]
    public function addtype(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $name = $this->name();
        $error = $this->typeNameError($name, 0);
        if ($error !== null) return new Response($this->list($error), 422);
        $position = (int) $this->db->one('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM tag_types')['p'];
        $this->db->query('INSERT INTO tag_types (name, position) VALUES (?, ?)', [$name, $position]);
        $this->purge();
        return Response::redirect('/tags');
    }

    #[Auth] #[Post]
    public function updatetype(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $type = $this->findType($id);
        if ($type === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $name = $this->name();
        $error = $this->typeNameError($name, (int) $type['id']);
        if ($error !== null) return new Response($this->typeForm($type, $error), 422);
        $this->db->query('UPDATE tag_types SET name = ? WHERE id = ?', [$name, (int) $type['id']]);
        $this->purge();
        return Response::redirect('/tags');
    }

    /** Swap with the type above or below; positions are renumbered 1..n first. */
    #[Auth] #[Post]
    public function movetype(string $id, string $dir): Response
    {
        if ($r = $this->deny()) return $r;
        $type = $this->findType($id);
        if ($type === null || !in_array($dir, ['up', 'down'], true)) return new Response(\App\Lang::t('error.not_found'), 404);
        $ids = array_map('intval', array_column($this->db->all('SELECT id FROM tag_types ORDER BY position, id'), 'id'));
        $this->swapPosition('tag_types', $ids, (int) $type['id'], $dir);
        $this->purge();
        return Response::redirect('/tags');
    }

    #[Auth] #[Get] #[Post]
    public function deletetype(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $type = $this->findType($id);
        if ($type === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $tags = (int) $this->db->one('SELECT COUNT(*) AS c FROM tags WHERE tag_type_id = ?', [(int) $type['id']])['c'];
        if ($this->request->method !== 'POST') return $this->confirmType($type, $tags, null);
        if ($tags > 0) return new Response($this->confirmType($type, $tags, Lang::t('tagsadmin.err_type_in_use', ['n' => $tags])), 422);
        $this->db->query('DELETE FROM tag_types WHERE id = ?', [(int) $type['id']]);
        $this->purge();
        return Response::redirect('/tags');
    }

    #[Auth] #[Post]
    public function addtag(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $type = $this->findType($this->post('tag_type_id'));
        $name = $this->name();
        if ($type === null) return new Response($this->list(Lang::t('tagsadmin.err_type')), 422);
        $error = $this->tagNameError($name, (int) $type['id'], 0);
        if ($error !== null) return new Response($this->list($error), 422);
        $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [(int) $type['id'], $name]);
        $this->purge();
        return Response::redirect('/tags');
    }

    #[Auth] #[Post]
    public function update(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $tag = $this->findTag($id);
        if ($tag === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $name = $this->name();
        $error = $this->tagNameError($name, (int) $tag['tag_type_id'], (int) $tag['id']);
        if ($error !== null) return new Response($this->tagForm($tag, $error), 422);
        $this->db->query('UPDATE tags SET name = ? WHERE id = ?', [$name, (int) $tag['id']]);
        $this->purge();
        return Response::redirect('/tags');
    }

    /** GET shows the confirm step. POST deletes an unused tag, or a used one
     *  when the owner ticked "remove it from those stories". */
    #[Auth] #[Get] #[Post]
    public function delete(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $tag = $this->findTag($id);
        if ($tag === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $stories = (int) $this->db->one('SELECT COUNT(*) AS c FROM story_tags WHERE tag_id = ?', [(int) $tag['id']])['c'];
        if ($this->request->method !== 'POST') return $this->confirmTag($tag, $stories, null);
        if ($stories > 0 && ($this->request->post['remove'] ?? '') !== '1') {
            return new Response($this->confirmTag($tag, $stories, Lang::t('tagsadmin.err_in_use', ['n' => $stories])), 422);
        }
        $this->atomically(function () use ($tag): void {
            $this->db->query('DELETE FROM story_tags WHERE tag_id = ?', [(int) $tag['id']]);
            $this->db->query('UPDATE tags SET canonical_id = NULL WHERE canonical_id = ?', [(int) $tag['id']]);
            $this->db->query('DELETE FROM tags WHERE id = ?', [(int) $tag['id']]);
        });
        $this->purge();
        return Response::redirect('/tags');
    }

    private function name(): string
    {
        return mb_substr($this->post('name'), 0, 60);
    }

    private function typeNameError(string $name, int $selfId): ?string
    {
        if ($name === '') return Lang::t('tagsadmin.err_name');
        $dupe = $this->db->one('SELECT 1 AS x FROM tag_types WHERE name = ? COLLATE NOCASE AND id != ?', [$name, $selfId]);
        return $dupe === null ? null : Lang::t('tagsadmin.err_type_dupe');
    }

    private function tagNameError(string $name, int $typeId, int $selfId): ?string
    {
        if ($name === '') return Lang::t('tagsadmin.err_name');
        $dupe = $this->db->one('SELECT 1 AS x FROM tags WHERE tag_type_id = ? AND name = ? COLLATE NOCASE AND id != ?', [$typeId, $name, $selfId]);
        return $dupe === null ? null : Lang::t('tagsadmin.err_tag_dupe');
    }

    private function findType(string $id): ?array
    {
        return ctype_digit($id) ? $this->db->one('SELECT * FROM tag_types WHERE id = ?', [(int) $id]) : null;
    }

    private function findTag(string $id): ?array
    {
        return ctype_digit($id)
            ? $this->db->one('SELECT t.*, tt.name AS type_name FROM tags t JOIN tag_types tt ON tt.id = t.tag_type_id WHERE t.id = ?', [(int) $id])
            : null;
    }

    private function list(?string $error): string
    {
        $types = $this->db->all('SELECT * FROM tag_types ORDER BY position, id');
        $tags = $this->db->all(
            'SELECT t.id, t.name, t.tag_type_id, (SELECT name FROM tags c WHERE c.id = t.canonical_id) AS canonical_name,
                    (SELECT COUNT(*) FROM story_tags st WHERE st.tag_id = t.id) AS story_count
             FROM tags t ORDER BY t.name COLLATE NOCASE, t.id');
        $byType = [];
        foreach ($tags as $t) $byType[(int) $t['tag_type_id']][] = $t;
        return $this->page('tags/index', 'tagsadmin.heading', '/tags', ['types' => $types, 'byType' => $byType, 'error' => $error]);
    }

    private function typeForm(array $type, ?string $error): string
    {
        return $this->page('tags/edittype', 'tagsadmin.edit_type_heading', '/tags/edittype/' . (int) $type['id'], ['type' => $type, 'error' => $error]);
    }

    private function tagForm(array $tag, ?string $error): string
    {
        return $this->page('tags/edit', 'tagsadmin.edit_tag_heading', '/tags/edit/' . (int) $tag['id'], ['tag' => $tag, 'error' => $error]);
    }

    private function confirmType(array $type, int $tags, ?string $error): string
    {
        return $this->page('tags/deletetype', 'tagsadmin.delete_type_heading', '/tags/deletetype/' . (int) $type['id'],
            ['type' => $type, 'tags' => $tags, 'error' => $error]);
    }

    private function confirmTag(array $tag, int $stories, ?string $error): string
    {
        return $this->page('tags/delete', 'tagsadmin.delete_tag_heading', '/tags/delete/' . (int) $tag['id'],
            ['tag' => $tag, 'stories' => $stories, 'error' => $error]);
    }
}
