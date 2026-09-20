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
}
