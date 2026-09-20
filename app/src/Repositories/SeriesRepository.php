<?php // app/src/Repositories/SeriesRepository.php
namespace App\Repositories;
use Kip\Database;

final class SeriesRepository
{
    public function __construct(private Database $db) {}

    public function create(int $ownerId, string $title, string $summary, string $membership): string
    {
        $slug = \App\Slug::unique(
            fn (string $s): bool => $this->db->one('SELECT id FROM series WHERE slug = ?', [$s]) !== null,
            \App\Slug::make($title, 'series')
        );
        $this->db->query('INSERT INTO series (title, slug, summary, owner_id, membership) VALUES (?, ?, ?, ?, ?)',
            [$title, $slug, $summary, $ownerId, $membership]);
        return $slug;
    }

    /** Owner or admin; throws RuntimeException('not found') like ownStory. */
    public function update(string $slug, string $title, string $summary, string $membership, int $actorId, bool $isAdmin): void
    {
        $row = $this->own($slug, $actorId, $isAdmin);
        $this->db->query('UPDATE series SET title = ?, summary = ?, membership = ? WHERE id = ?', [$title, $summary, $membership, $row['id']]);
    }

    /** The edit form's row (slug, title, summary, membership) in ONE query with
     *  the owner/admin gate folded in as a may_edit scalar (the formData idiom:
     *  /series/edit is a budget-pinned page, so no separate role query; the
     *  Task 4 two-step own()+select and the controller's viewerIsAdmin call
     *  would cost 3 queries against the pinned 1). */
    public function forEdit(string $slug, int $actorId): array
    {
        $row = $this->db->one(
            "SELECT slug, title, summary, membership,
                    (owner_id = CAST(? AS INTEGER)
                     OR EXISTS (SELECT 1 FROM users v WHERE v.id = CAST(? AS INTEGER) AND v.role = 'admin')) may_edit
             FROM series WHERE slug = ?",
            [$actorId, $actorId, $slug]
        );
        if ($row === null || (int) $row['may_edit'] !== 1) throw new \RuntimeException('not found');
        unset($row['may_edit']);
        return $row;
    }

    /** One query for EVERY viewer (guest, member, owner, admin): the series row
     *  (k='0') plus its item rows (k='1'), visibility-gated in SQL: confirmed
     *  AND (validated OR viewer is the story's author, the series owner, or
     *  admin); owner, story author, and admin additionally see pending items.
     *  Soft-deleted stories never appear (everyone), and restricted member
     *  titles only for logged-in viewers, the findStoryBySlug gate pattern
     *  (coordinator ruling, follow-up to Task 3); item_count (column h)
     *  carries the same gates so the badge never outruns the list.
     *  The series branch carries owner_id and a viewer-admin scalar so the
     *  controller computes isOwner/isAdmin without extra queries. Returns
     *  ['series' => ..., 'items' => ...] or null when the slug is unknown.
     *  ORDER BY note (plan review finding 1): compound SELECTs take bare output
     *  aliases only, so position is emitted UNCAST (raw integer) and ordered as
     *  `ORDER BY k, c`; a CAST in ORDER BY fails to prepare and a CAST-in-select
     *  sorts lexicographically (1,10,11,2). */
    public function seriesPage(string $slug, int $me): ?array
    {
        if (!preg_match('#^[a-z0-9-]+$#', $slug)) return null;
        $rows = $this->db->all(
            "SELECT '0' AS k, ser.title a, ser.summary b, ser.membership c, ser.slug d,
                    u.penname e, u.profile_slug f, ser.created_at g,
                    CAST((SELECT COUNT(*) FROM series_items x JOIN stories xs ON xs.id = x.story_id
                          WHERE x.series_id = ser.id AND x.confirmed = 1 AND xs.deleted_at IS NULL
                            AND (xs.is_restricted = 0 OR CAST(? AS INTEGER) != 0)) AS TEXT) h,
                    CAST((SELECT COUNT(*) FROM users v WHERE v.id = ? AND v.role = 'admin') AS TEXT) i,
                    CAST(ser.owner_id AS TEXT) j
             FROM series ser JOIN users u ON u.id = ser.owner_id WHERE ser.slug = ?
             UNION ALL
             SELECT '1' AS k, s.slug a, s.title b, si.position c, CAST(si.id AS TEXT) d,
                    CAST(si.confirmed AS TEXT) e, CAST((SELECT COUNT(*) FROM chapters ch WHERE ch.story_id = s.id AND ch.validated = 1) AS TEXT) f,
                    s.updated_at g, CAST(s.word_count AS TEXT) h, NULL i, NULL j
             FROM series_items si
             JOIN series ser ON ser.id = si.series_id
             JOIN stories s ON s.id = si.story_id
             WHERE ser.slug = ?
               AND s.deleted_at IS NULL
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)
               AND ((si.confirmed = 1 AND (s.validated = 1 OR s.author_id = CAST(? AS INTEGER) OR ser.owner_id = CAST(? AS INTEGER)
                    OR EXISTS (SELECT 1 FROM users v2 WHERE v2.id = CAST(? AS INTEGER) AND v2.role = 'admin')))
                    OR ser.owner_id = CAST(? AS INTEGER) OR s.author_id = CAST(? AS INTEGER)
                    OR EXISTS (SELECT 1 FROM users v3 WHERE v3.id = CAST(? AS INTEGER) AND v3.role = 'admin'))
             ORDER BY k, c",
            // ELEVEN binds, in order of appearance (SELECT-list first, left to
            // right): the count's restricted gate, the is_admin scalar, the
            // series slug (twice), the list's restricted gate, then the six
            // viewer-gate binds. The Task 2 draft shipped eight of nine and PDO
            // silently left the ninth unbound; count the question marks.
            [$me, $me, $slug, $slug, $me, $me, $me, $me, $me, $me, $me]
        );
        if ($rows === []) return null;
        $series = [
            'title' => $rows[0]['a'], 'summary' => $rows[0]['b'], 'membership' => $rows[0]['c'],
            'slug' => $rows[0]['d'], 'owner_penname' => $rows[0]['e'], 'owner_slug' => $rows[0]['f'],
            'created_at' => $rows[0]['g'], 'item_count' => (int) $rows[0]['h'],
            'is_admin' => (int) $rows[0]['i'], 'owner_id' => (int) $rows[0]['j'],
        ];
        $items = [];
        foreach (array_slice($rows, 1) as $r) {
            $items[] = ['slug' => $r['a'], 'title' => $r['b'], 'position' => (int) $r['c'],
                'item_id' => (int) $r['d'], 'confirmed' => (int) $r['e'],
                'chapter_count' => (int) $r['f'], 'updated_at' => $r['g'], 'word_count' => (int) $r['h']];
        }
        return ['series' => $series, 'items' => $items];
    }

    /** 'added' | 'pending' | 'confirmed' | 'duplicate'; RuntimeException for
     *  closed series, foreign stories, unknown slugs. Actor must be the story's
     *  author or coauthor; the series owner or admin may add any story they
     *  author. COORDINATOR RULING (Task 2 execution, 2026-09-20): when the row
     *  already exists (partial unique hit) and the actor is owner/admin, a
     *  PENDING row is upgraded to confirmed and the method returns 'confirmed'
     *  (a distinct result so the controller fires the same series_confirm
     *  notification as the explicit confirm action); an already-confirmed row
     *  (or a non-owner re-submission) is 'duplicate'. */
    public function addItem(string $seriesSlug, string $storySlug, int $actorId, bool $isAdmin): string
    {
        $this->db->begin();
        try {
            $ser = $this->db->one('SELECT id, owner_id, membership FROM series WHERE slug = ?', [$seriesSlug]);
            $sto = $this->db->one('SELECT id, author_id FROM stories WHERE slug = ? AND deleted_at IS NULL', [$storySlug]);
            if ($ser === null || $sto === null) throw new \RuntimeException('not found');
            $isOwner = (int) $ser['owner_id'] === $actorId;
            $isStorySide = (int) $sto['author_id'] === $actorId
                || $this->db->one('SELECT 1 AS x FROM coauthors WHERE story_id = ? AND user_id = ?', [$sto['id'], $actorId]) !== null;
            if (!$isOwner && !$isAdmin && $ser['membership'] === 'closed') throw new \RuntimeException('series is closed');
            if (!$isStorySide && !$isOwner && !$isAdmin) throw new \RuntimeException('not your story');
            $confirmed = ($isOwner || $isAdmin || $ser['membership'] === 'open') ? 1 : 0;
            $guard = $this->db->query(
                'INSERT OR IGNORE INTO series_items (series_id, story_id, position, confirmed)
                 SELECT ?, ?, COALESCE(MAX(position), 0) + 1, ? FROM series_items WHERE series_id = ?',
                [$ser['id'], $sto['id'], $confirmed, $ser['id']]
            );
            if ($guard->rowCount() === 0) {
                if ($isOwner || $isAdmin) {
                    $up = $this->db->query(
                        'UPDATE series_items SET confirmed = 1 WHERE series_id = ? AND story_id = ? AND confirmed = 0',
                        [$ser['id'], $sto['id']]
                    );
                    if ($up->rowCount() > 0) { $this->db->commit(); return 'confirmed'; }
                }
                $this->db->rollBack();
                return 'duplicate';
            }
            $this->db->commit();
            return $confirmed === 1 ? 'added' : 'pending';
        } catch (\Throwable $e) {
            $this->db->rollBack(); throw $e;
        }
    }

    /** Owner, admin, or the story's own author may remove. */
    public function removeItem(string $seriesSlug, string $storySlug, int $actorId, bool $isAdmin): bool
    {
        $row = $this->db->one(
            'SELECT si.id, ser.owner_id, s.author_id FROM series_items si
             JOIN series ser ON ser.id = si.series_id JOIN stories s ON s.id = si.story_id
             WHERE ser.slug = ? AND s.slug = ?', [$seriesSlug, $storySlug]);
        if ($row === null) return false;
        if ((int) $row['owner_id'] !== $actorId && (int) $row['author_id'] !== $actorId && !$isAdmin) return false;
        $this->db->query('DELETE FROM series_items WHERE id = ?', [$row['id']]);
        return true;
    }

    /** Swap an item with its nearest lower/higher neighbour. Owner or admin.
     *  The sentinel restore is keyed on position AND series (the list-swap
     *  form: the sentinel lives on position, never on id). */
    public function move(string $seriesSlug, int $itemId, string $dir, int $actorId, bool $isAdmin): void
    {
        $ser = $this->own($seriesSlug, $actorId, $isAdmin);
        $item = $this->db->one('SELECT id, position FROM series_items WHERE id = ? AND series_id = ?', [$itemId, $ser['id']]);
        if ($item === null) throw new \RuntimeException('not found');
        $op = $dir === 'down' ? '>' : '<';
        $ord = $dir === 'down' ? 'ASC' : 'DESC';
        $neighbour = $this->db->one(
            "SELECT id, position FROM series_items WHERE series_id = ? AND position {$op} ? ORDER BY position {$ord} LIMIT 1",
            [$ser['id'], $item['position']]
        );
        if ($neighbour === null) return; // already at the end; not an error
        $this->db->begin();
        try {
            $this->db->query('UPDATE series_items SET position = -1 WHERE id = ?', [$item['id']]);
            $this->db->query('UPDATE series_items SET position = ? WHERE id = ?', [$item['position'], $neighbour['id']]);
            $this->db->query('UPDATE series_items SET position = ? WHERE series_id = ? AND position = -1', [$neighbour['position'], $ser['id']]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** Owner or admin confirms a moderated submission. Returns [storyAuthor, storyTitle, storySlug] for the notification. */
    public function confirm(string $seriesSlug, int $itemId, int $actorId, bool $isAdmin): ?array
    {
        $ser = $this->own($seriesSlug, $actorId, $isAdmin);
        $row = $this->db->one(
            'SELECT si.id, s.author_id, s.title, s.slug FROM series_items si
             JOIN stories s ON s.id = si.story_id WHERE si.id = ? AND si.series_id = ? AND si.confirmed = 0',
            [$itemId, $ser['id']]
        );
        if ($row === null) return null;
        $this->db->query('UPDATE series_items SET confirmed = 1 WHERE id = ?', [$row['id']]);
        return [(int) $row['author_id'], (string) $row['title'], (string) $row['slug']];
    }

    /** Slugs of confirmed series containing a story (cache purge + display). */
    public function seriesSlugsForStory(int $storyId): array
    {
        return array_map(
            fn (array $r): string => (string) $r['slug'],
            $this->db->all('SELECT ser.slug FROM series_items si JOIN series ser ON ser.id = si.series_id WHERE si.story_id = ? AND si.confirmed = 1', [$storyId])
        );
    }

    /** One-line role check for the controller's write guards (writes are not
     *  budget-pinned, plan review finding 12: no Adminness::isAdmin exists). */
    public function viewerIsAdmin(int $userId): bool
    {
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$userId])['c'] === 1;
    }

    private function own(string $slug, int $actorId, bool $isAdmin): array
    {
        $row = $this->db->one('SELECT id, owner_id FROM series WHERE slug = ?', [$slug]);
        if ($row === null || ((int) $row['owner_id'] !== $actorId && !$isAdmin)) throw new \RuntimeException('not found');
        return $row;
    }
}
