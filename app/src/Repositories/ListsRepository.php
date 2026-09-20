<?php // app/src/Repositories/ListsRepository.php
namespace App\Repositories;
use Kip\Database;

final class ListsRepository
{
    public function __construct(private Database $db) {}

    public function create(int $ownerId, string $title, string $summary, int $isPublic): string
    {
        $slug = \App\Slug::unique(
            fn (string $s): bool => $this->db->one('SELECT id FROM reading_lists WHERE slug = ?', [$s]) !== null,
            \App\Slug::make($title, 'list')
        );
        $this->db->query('INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, ?, ?, ?, ?)',
            [$ownerId, $title, $slug, $summary, $isPublic]);
        return $slug;
    }

    public function update(string $slug, string $title, string $summary, int $isPublic, int $ownerId): void
    {
        $row = $this->own($slug, $ownerId);
        $this->db->query("UPDATE reading_lists SET title = ?, summary = ?, is_public = ?,
                updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$title, $summary, $isPublic, $row['id']]);
    }

    public function delete(string $slug, int $ownerId): void
    {
        $row = $this->own($slug, $ownerId);
        $this->db->query('DELETE FROM reading_lists WHERE id = ?', [$row['id']]); // items ride the FK cascade
    }

    /** The edit form's row (id, slug, title, summary, is_public). Owner-ONLY:
     *  no collaborators and no admin override (the recorded YAGNI); a stranger's
     *  slug reads as 404, never 403, so the ownership state stays unobservable.
     *  Throws RuntimeException('not found'). */
    public function own(string $slug, int $ownerId): array
    {
        $row = $this->db->one('SELECT id, owner_id, slug, title, summary, is_public FROM reading_lists WHERE slug = ?', [$slug]);
        if ($row === null || (int) $row['owner_id'] !== $ownerId) throw new \RuntimeException('not found');
        return $row;
    }

    /** ONE query for every viewer: the list row plus the owner's penname and
     *  profile slug, and the items as a json blob. Visibility is gated in SQL:
     *  private lists exist only for their owner (the guest gate), and within
     *  the blob restricted works surface for members only (the restricted
     *  lesson); unvalidated and soft-deleted stories never appear for anyone.
     *  THREE binds in order of appearance: the blob's restricted gate, the
     *  slug, the is_public/owner gate. TWO of them are viewer binds and both
     *  CAST, because PDO binds int 0 as TEXT and a bare ? would compare across
     *  storage classes, failing the gates OPEN for guests (findStoryBySlug's
     *  note). @return array<string,mixed>|null */
    public function view(string $slug, int $viewerId): ?array
    {
        if (!preg_match('#^[a-z0-9-]+$#', $slug)) return null;
        $row = $this->db->one(
            "SELECT l.id, l.title, l.summary, l.slug, l.is_public, l.created_at, l.updated_at, l.owner_id,
                    u.penname AS owner_penname, u.profile_slug AS owner_slug,
                    (SELECT json_group_array(json_object('slug', t.slug, 'title', t.title, 'position', t.position, 'note', t.note))
                     FROM (SELECT s.slug, s.title, li.position, li.note
                           FROM reading_list_items li JOIN stories s ON s.id = li.story_id
                           WHERE li.list_id = l.id AND s.validated = 1 AND s.deleted_at IS NULL
                             AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)
                           ORDER BY li.position) t) AS items_json
             FROM reading_lists l JOIN users u ON u.id = l.owner_id
             WHERE l.slug = ? AND (l.is_public = 1 OR l.owner_id = CAST(? AS INTEGER))",
            [$viewerId, $slug, $viewerId]
        );
        if ($row === null) return null;
        $items = json_decode((string) $row['items_json'], true);
        unset($row['items_json']);
        $row['is_public'] = (int) $row['is_public'];
        $row['owner_id'] = (int) $row['owner_id'];
        $row['items'] = is_array($items) ? $items : []; // json_group_array yields [] for an empty list
        return $row;
    }

    /** The member index: their lists with RAW item counts as scalars, ONE
     *  query. Raw (not the guest-blob counts) because this is the owner's
     *  management surface: they see every item they added, including the
     *  ones the public blob hides. @return array<int,array<string,mixed>> */
    public function indexFor(int $ownerId): array
    {
        $rows = $this->db->all(
            "SELECT l.slug, l.title, l.summary, l.is_public, l.updated_at,
                    (SELECT COUNT(*) FROM reading_list_items li WHERE li.list_id = l.id) AS item_count
             FROM reading_lists l WHERE l.owner_id = ? ORDER BY l.updated_at DESC, l.id DESC", [$ownerId]);
        foreach ($rows as &$r) {
            $r['is_public'] = (int) $r['is_public'];
            $r['item_count'] = (int) $r['item_count'];
        }
        unset($r);
        return $rows;
    }

    /** The edit form's item rows: raw again (the owner manages what they
     *  added even after a story went unvalidated or away). Ordered by
     *  position; gaps after a removal are fine, ordering is by position. */
    public function itemsForEdit(int $listId): array
    {
        return $this->db->all(
            'SELECT li.id, li.position, li.note, s.slug, s.title FROM reading_list_items li
             JOIN stories s ON s.id = li.story_id WHERE li.list_id = ? ORDER BY li.position', [$listId]);
    }

    /** Add a story by slug to the owner's list. Honest rejects as exception
     *  messages the controller maps to 422 reasons: 'unknown story' (no such
     *  slug, or soft-deleted: gone is gone on every surface), 'unvalidated'
     *  (only validated stories list), 'duplicate' (the UNIQUE(list_id,
     *  story_id)). Restricted stories are fine: the owner curates what they
     *  can read, and the PUBLIC page's blob hides restricted works from
     *  guests. Appends after the last position. */
    public function addItem(string $listSlug, string $storySlug, string $note, int $ownerId): void
    {
        $list = $this->own($listSlug, $ownerId); // RuntimeException('not found') -> 404
        if (!preg_match('#^[a-z0-9-]+$#', $storySlug)) throw new \RuntimeException('unknown story');
        $story = $this->db->one('SELECT id, validated, deleted_at FROM stories WHERE slug = ?', [$storySlug]);
        if ($story === null || $story['deleted_at'] !== null) throw new \RuntimeException('unknown story');
        if ((int) $story['validated'] !== 1) throw new \RuntimeException('unvalidated');
        $guard = $this->db->query(
            'INSERT OR IGNORE INTO reading_list_items (list_id, story_id, position, note)
             SELECT ?, ?, COALESCE(MAX(position), 0) + 1, ? FROM reading_list_items WHERE list_id = ?',
            [$list['id'], $story['id'], $note, $list['id']]);
        if ($guard->rowCount() === 0) throw new \RuntimeException('duplicate');
    }

    /** Remove a story from the owner's list; false when the list is not the
     *  actor's or the pair is not on it (one 404 shape: a stranger's list
     *  reads as absent, same as a missing item). */
    public function removeItem(string $listSlug, string $storySlug, int $ownerId): bool
    {
        try { $list = $this->own($listSlug, $ownerId); }
        catch (\RuntimeException) { return false; }
        if (!preg_match('#^[a-z0-9-]+$#', $storySlug)) return false;
        $row = $this->db->one(
            'SELECT li.id FROM reading_list_items li JOIN stories s ON s.id = li.story_id
             WHERE li.list_id = ? AND s.slug = ?', [$list['id'], $storySlug]);
        if ($row === null) return false;
        $this->db->query('DELETE FROM reading_list_items WHERE id = ?', [$row['id']]);
        return true;
    }

    /** Swap an item with its nearest lower/higher neighbour: the series swap
     *  idiom, transactional, with the sentinel restore keyed on position AND
     *  list (the correct form: the sentinel lives on position, never on id).
     *  A boundary move (top up, bottom down) is a calm no-op, not an error. */
    public function move(string $listSlug, int $itemId, string $dir, int $ownerId): void
    {
        $list = $this->own($listSlug, $ownerId);
        $item = $this->db->one('SELECT id, position FROM reading_list_items WHERE id = ? AND list_id = ?', [$itemId, $list['id']]);
        if ($item === null) throw new \RuntimeException('not found');
        $op = $dir === 'down' ? '>' : '<';
        $ord = $dir === 'down' ? 'ASC' : 'DESC';
        $neighbour = $this->db->one(
            "SELECT id, position FROM reading_list_items WHERE list_id = ? AND position {$op} ? ORDER BY position {$ord} LIMIT 1",
            [$list['id'], $item['position']]);
        if ($neighbour === null) return;
        $this->db->begin();
        try {
            $this->db->query('UPDATE reading_list_items SET position = -1 WHERE id = ?', [$item['id']]);
            $this->db->query('UPDATE reading_list_items SET position = ? WHERE id = ?', [$item['position'], $neighbour['id']]);
            $this->db->query('UPDATE reading_list_items SET position = ? WHERE list_id = ? AND position = -1', [$neighbour['position'], $list['id']]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** Slugs of PUBLIC lists containing the story: the story-side purge
     *  rider's caller-side lookup (Cache stays DB-free, the finding-4
     *  contract). Private lists never fill the static layer (their guest
     *  render is the SQL 404), so they never need purging. @return string[] */
    public function publicListSlugsForStory(int $storyId): array
    {
        return array_column($this->db->all(
            'SELECT l.slug FROM reading_lists l JOIN reading_list_items li ON li.list_id = l.id
             WHERE li.story_id = ? AND l.is_public = 1', [$storyId]), 'slug');
    }
}
