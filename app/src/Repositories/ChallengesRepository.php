<?php // app/src/Repositories/ChallengesRepository.php
namespace App\Repositories;
use Kip\Database;

/** The challenges module's storage, the ListsRepository mirror (the freshest
 *  CRUD precedent): owner-gated through own() everywhere, one 404 shape for
 *  strangers and unknown slugs alike. The public page fold and item
 *  membership carry the seriesPage visibility discipline. */
final class ChallengesRepository
{
    public function __construct(private Database $db) {}

    public function create(int $ownerId, string $title, string $summary, string $membership): string
    {
        $slug = \App\Slug::unique(
            fn (string $s): bool => $this->db->one('SELECT id FROM challenges WHERE slug = ?', [$s]) !== null,
            \App\Slug::make($title, 'challenge')
        );
        $this->db->query('INSERT INTO challenges (owner_id, title, slug, summary, membership) VALUES (?, ?, ?, ?, ?)',
            [$ownerId, $title, $slug, $summary, $membership]);
        return $slug;
    }

    public function update(string $slug, string $title, string $summary, string $membership, int $ownerId): void
    {
        $row = $this->own($slug, $ownerId);
        $this->db->query("UPDATE challenges SET title = ?, summary = ?, membership = ?,
                updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$title, $summary, $membership, $row['id']]);
    }

    public function delete(string $slug, int $ownerId): void
    {
        $row = $this->own($slug, $ownerId);
        $this->db->query('DELETE FROM challenges WHERE id = ?', [$row['id']]); // prompts and items ride the FK cascade
    }

    /** The edit form's row (id, owner_id, slug, title, summary, membership).
     *  Owner-ONLY, the Lists idiom: no admin override; a stranger's slug
     *  reads as 404, never 403, so the ownership state stays unobservable.
     *  Throws RuntimeException('not found'). */
    public function own(string $slug, int $ownerId): array
    {
        $row = $this->db->one('SELECT id, owner_id, slug, title, summary, membership FROM challenges WHERE slug = ?', [$slug]);
        if ($row === null || (int) $row['owner_id'] !== $ownerId) throw new \RuntimeException('not found');
        return $row;
    }

    /** The edit form's prompt rows, ordered by position; gaps after a removal
     *  are fine, ordering is by position. */
    public function promptsForEdit(int $challengeId): array
    {
        return $this->db->all(
            'SELECT id, position, prompt_text FROM challenge_prompts WHERE challenge_id = ? ORDER BY position',
            [$challengeId]
        );
    }

    /** Append a prompt after the last position. Owner-gated via own() (a
     *  stranger's challenge is the same 404 as an unknown slug). Text arrives
     *  pre-clamped and pre-validated by the controller (1-500, the listInput
     *  clamp idiom: controllers validate, repositories trust). */
    public function addPrompt(string $challengeSlug, string $text, int $ownerId): void
    {
        $challenge = $this->own($challengeSlug, $ownerId);
        $this->db->query(
            'INSERT INTO challenge_prompts (challenge_id, position, prompt_text)
             SELECT ?, COALESCE(MAX(position), 0) + 1, ? FROM challenge_prompts WHERE challenge_id = ?',
            [$challenge['id'], $text, $challenge['id']]
        );
    }

    /** Remove a prompt; false when the challenge is not the actor's or the
     *  prompt is not on it (one 404 shape, the removeItem idiom: a stranger's
     *  challenge reads as absent, same as a missing prompt). */
    public function removePrompt(string $challengeSlug, int $promptId, int $ownerId): bool
    {
        try { $challenge = $this->own($challengeSlug, $ownerId); }
        catch (\RuntimeException) { return false; }
        $row = $this->db->one('SELECT id FROM challenge_prompts WHERE id = ? AND challenge_id = ?', [$promptId, $challenge['id']]);
        if ($row === null) return false;
        $this->db->query('DELETE FROM challenge_prompts WHERE id = ?', [$row['id']]);
        return true;
    }

    /** Swap a prompt with its nearest lower/higher neighbour: the corrected
     *  sentinel form, transactional, the restore keyed on position AND
     *  challenge (the sentinel lives on position, never on id). A boundary
     *  move (top up, bottom down) is a calm no-op, not an error. */
    public function reorderPrompts(string $challengeSlug, int $promptId, string $dir, int $ownerId): void
    {
        $challenge = $this->own($challengeSlug, $ownerId);
        $prompt = $this->db->one('SELECT id, position FROM challenge_prompts WHERE id = ? AND challenge_id = ?', [$promptId, $challenge['id']]);
        if ($prompt === null) throw new \RuntimeException('not found');
        $op = $dir === 'down' ? '>' : '<';
        $ord = $dir === 'down' ? 'ASC' : 'DESC';
        $neighbour = $this->db->one(
            "SELECT id, position FROM challenge_prompts WHERE challenge_id = ? AND position {$op} ? ORDER BY position {$ord} LIMIT 1",
            [$challenge['id'], $prompt['position']]
        );
        if ($neighbour === null) return;
        $this->db->begin();
        try {
            $this->db->query('UPDATE challenge_prompts SET position = -1 WHERE id = ?', [$prompt['id']]);
            $this->db->query('UPDATE challenge_prompts SET position = ? WHERE id = ?', [$prompt['position'], $neighbour['id']]);
            $this->db->query('UPDATE challenge_prompts SET position = ? WHERE challenge_id = ? AND position = -1', [$neighbour['position'], $challenge['id']]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** ONE query for every viewer (guest, member, owner, admin), the seriesPage
     *  arrangement: the challenge row (k='0') plus its item rows (k='1'),
     *  visibility-gated in SQL. Guests see confirmed, validated, unrestricted
     *  stories only; the story's author, the challenge owner, and admins
     *  additionally see unvalidated members' rows and their own pending items.
     *  Soft-deleted stories never appear for anyone. The prompts ride as a
     *  param-free json blob in the '0' branch (the reading-list blob idiom);
     *  item position is emitted UNCAST and ordered as a bare alias (compound
     *  SELECTs reject CAST in ORDER BY, and a CAST-in-select sorts 10 before
     *  4, finding 9). The Task 2 mute clause rides the item branch only, as
     *  the TWELFTH bind (anonymous keeps the exact eleven). Returns
     *  ['challenge' => ..., 'items' => ...] or null for an unknown slug.
     *  TWELVE binds when a viewer flows in, counted: the count's restricted
     *  gate, the admin scalar, the slug (twice), the item branch's restricted
     *  gate, then the six viewer-gate binds, then the clause's viewer. */
    public function challengePage(string $slug, int $me, int $viewer = 0): ?array
    {
        if (!preg_match('#^[a-z0-9-]+$#', $slug)) return null;
        $mute = $viewer > 0 ? MuteRepository::clause('s') : '';
        $binds = [$me, $me, $slug, $slug, $me, $me, $me, $me, $me, $me, $me];
        if ($viewer > 0) $binds[] = $viewer;
        $rows = $this->db->all(
            "SELECT '0' AS k, ch.title a, ch.summary b, ch.membership c, ch.slug d,
                    u.penname e, u.profile_slug f, ch.created_at g,
                    CAST((SELECT COUNT(*) FROM challenge_items x JOIN stories xs ON xs.id = x.story_id
                          WHERE x.challenge_id = ch.id AND x.confirmed = 1 AND xs.deleted_at IS NULL
                            AND (xs.is_restricted = 0 OR CAST(? AS INTEGER) != 0)) AS TEXT) h,
                    CAST((SELECT COUNT(*) FROM users v WHERE v.id = ? AND v.role = 'admin') AS TEXT) i,
                    CAST(ch.owner_id AS TEXT) j,
                    (SELECT json_group_array(json_object('id', p.id, 'text', p.prompt_text))
                     FROM (SELECT id, prompt_text FROM challenge_prompts WHERE challenge_id = ch.id ORDER BY position) p) n
             FROM challenges ch JOIN users u ON u.id = ch.owner_id WHERE ch.slug = ?
             UNION ALL
             SELECT '1' AS k, s.slug a, s.title b, ci.position c, CAST(ci.id AS TEXT) d,
                    CAST(ci.confirmed AS TEXT) e, CAST((SELECT COUNT(*) FROM chapters cch WHERE cch.story_id = s.id AND cch.validated = 1) AS TEXT) f,
                    s.updated_at g, CAST(s.word_count AS TEXT) h, NULL i, NULL j, NULL n
             FROM challenge_items ci
             JOIN challenges ch ON ch.id = ci.challenge_id
             JOIN stories s ON s.id = ci.story_id
             WHERE ch.slug = ?
               AND s.deleted_at IS NULL
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)
               AND ((ci.confirmed = 1 AND (s.validated = 1 OR s.author_id = CAST(? AS INTEGER) OR ch.owner_id = CAST(? AS INTEGER)
                    OR EXISTS (SELECT 1 FROM users v2 WHERE v2.id = CAST(? AS INTEGER) AND v2.role = 'admin')))
                    OR ch.owner_id = CAST(? AS INTEGER) OR s.author_id = CAST(? AS INTEGER)
                    OR EXISTS (SELECT 1 FROM users v3 WHERE v3.id = CAST(? AS INTEGER) AND v3.role = 'admin')){$mute}
             ORDER BY k, c",
            $binds
        );
        if ($rows === []) return null;
        $prompts = json_decode((string) $rows[0]['n'], true);
        $challenge = [
            'title' => $rows[0]['a'], 'summary' => $rows[0]['b'], 'membership' => $rows[0]['c'],
            'slug' => $rows[0]['d'], 'owner_penname' => $rows[0]['e'], 'owner_slug' => $rows[0]['f'],
            'created_at' => $rows[0]['g'], 'item_count' => (int) $rows[0]['h'],
            'is_admin' => (int) $rows[0]['i'], 'owner_id' => (int) $rows[0]['j'],
            'prompts' => is_array($prompts) ? $prompts : [],
        ];
        $items = [];
        foreach (array_slice($rows, 1) as $r) {
            $items[] = ['slug' => $r['a'], 'title' => $r['b'], 'position' => (int) $r['c'],
                'item_id' => (int) $r['d'], 'confirmed' => (int) $r['e'],
                'chapter_count' => (int) $r['f'], 'updated_at' => $r['g'], 'word_count' => (int) $r['h']];
        }
        return ['challenge' => $challenge, 'items' => $items];
    }

    /** The public index: every challenge newest-first with its visible item
     *  count (the same guest gates as the page fold's badge, one query). */
    public function indexRows(int $me): array
    {
        $rows = $this->db->all(
            "SELECT ch.slug, ch.title, ch.summary, ch.membership, ch.created_at,
                    (SELECT COUNT(*) FROM challenge_items x JOIN stories xs ON xs.id = x.story_id
                     WHERE x.challenge_id = ch.id AND x.confirmed = 1 AND xs.deleted_at IS NULL
                       AND (xs.is_restricted = 0 OR CAST(? AS INTEGER) != 0)) AS item_count
             FROM challenges ch ORDER BY ch.created_at DESC, ch.id DESC", [$me]);
        foreach ($rows as &$r) $r['item_count'] = (int) $r['item_count'];
        unset($r);
        return $rows;
    }

    /** 'added' | 'pending' | 'confirmed' | 'duplicate'; RuntimeException for
     *  closed challenges, foreign stories, unknown slugs, the series addItem
     *  contract verbatim. Actor must be the story's author or coauthor; the
     *  challenge owner or admin may add any story. When the row already
     *  exists and the actor is owner/admin, a pending row upgrades to
     *  confirmed and the method returns 'confirmed' (the same outcome as the
     *  explicit confirm action, so the controller fires the same
     *  challenge_confirm notification); an already-confirmed row (or a
     *  non-owner resubmission) is 'duplicate'. */
    public function addItem(string $challengeSlug, string $storySlug, int $actorId, bool $isAdmin): string
    {
        $this->db->begin();
        try {
            $ch = $this->db->one('SELECT id, owner_id, membership FROM challenges WHERE slug = ?', [$challengeSlug]);
            $sto = $this->db->one('SELECT id, author_id FROM stories WHERE slug = ? AND deleted_at IS NULL', [$storySlug]);
            if ($ch === null || $sto === null) throw new \RuntimeException('not found');
            $isOwner = (int) $ch['owner_id'] === $actorId;
            $isStorySide = (int) $sto['author_id'] === $actorId
                || $this->db->one('SELECT 1 AS x FROM coauthors WHERE story_id = ? AND user_id = ?', [$sto['id'], $actorId]) !== null;
            if (!$isOwner && !$isAdmin && $ch['membership'] === 'closed') throw new \RuntimeException('challenge is closed');
            if (!$isStorySide && !$isOwner && !$isAdmin) throw new \RuntimeException('not your story');
            $confirmed = ($isOwner || $isAdmin || $ch['membership'] === 'open') ? 1 : 0;
            $guard = $this->db->query(
                'INSERT OR IGNORE INTO challenge_items (challenge_id, story_id, position, confirmed)
                 SELECT ?, ?, COALESCE(MAX(position), 0) + 1, ? FROM challenge_items WHERE challenge_id = ?',
                [$ch['id'], $sto['id'], $confirmed, $ch['id']]
            );
            if ($guard->rowCount() === 0) {
                if ($isOwner || $isAdmin) {
                    $up = $this->db->query(
                        'UPDATE challenge_items SET confirmed = 1 WHERE challenge_id = ? AND story_id = ? AND confirmed = 0',
                        [$ch['id'], $sto['id']]
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

    /** Owner or admin confirms a moderated submission. Returns
     *  [storyAuthor, storyTitle, storySlug] for the notification, null when
     *  the item is already confirmed or not on this challenge. */
    public function confirmItem(string $challengeSlug, int $itemId, int $actorId, bool $isAdmin): ?array
    {
        $ch = $this->db->one('SELECT id, owner_id FROM challenges WHERE slug = ?', [$challengeSlug]);
        if ($ch === null || ((int) $ch['owner_id'] !== $actorId && !$isAdmin)) throw new \RuntimeException('not found');
        $row = $this->db->one(
            'SELECT ci.id, s.author_id, s.title, s.slug FROM challenge_items ci
             JOIN stories s ON s.id = ci.story_id WHERE ci.id = ? AND ci.challenge_id = ? AND ci.confirmed = 0',
            [$itemId, $ch['id']]
        );
        if ($row === null) return null;
        $this->db->query('UPDATE challenge_items SET confirmed = 1 WHERE id = ?', [$row['id']]);
        return [(int) $row['author_id'], (string) $row['title'], (string) $row['slug']];
    }

    /** Owner, admin, or the story's own author may remove (the withdrawal
     *  path: a member pulls their story back out). */
    public function removeItem(string $challengeSlug, string $storySlug, int $actorId, bool $isAdmin): bool
    {
        $row = $this->db->one(
            'SELECT ci.id, ch.owner_id, s.author_id FROM challenge_items ci
             JOIN challenges ch ON ch.id = ci.challenge_id JOIN stories s ON s.id = ci.story_id
             WHERE ch.slug = ? AND s.slug = ?', [$challengeSlug, $storySlug]);
        if ($row === null) return false;
        if ((int) $row['owner_id'] !== $actorId && (int) $row['author_id'] !== $actorId && !$isAdmin) return false;
        $this->db->query('DELETE FROM challenge_items WHERE id = ?', [$row['id']]);
        return true;
    }

    /** One-line role check for the controller's write guards (writes are not
     *  budget-pinned, plan review finding 12: no Adminness::isAdmin exists). */
    public function viewerIsAdmin(int $userId): bool
    {
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$userId])['c'] === 1;
    }

    /** Slugs of challenges whose CONFIRMED items include the story (the
     *  seriesSlugsForStory rider, mirrored: a chapter publish, story edit,
     *  or queue action must refresh the challenge pages displaying it). */
    public function slugsForStory(int $storyId): array
    {
        return array_map(
            fn (array $r): string => (string) $r['slug'],
            $this->db->all('SELECT DISTINCT c.slug FROM challenges c JOIN challenge_items ci ON ci.challenge_id = c.id WHERE ci.story_id = ? AND ci.confirmed = 1', [$storyId])
        );
    }
}
