<?php // app/src/Repositories/AuthoringRepository.php
namespace App\Repositories;

use Kip\Database;

final class AuthoringRepository
{
    public function __construct(private Database $db) {}

    /** ONE query for the story form: taxonomy rows for every caller, plus the
     *  story row (k='s') and its coauthors (k='co') when editing. Ownership
     *  (author, coauthor, or admin) is enforced in SQL. Output columns:
     *  k, a..j, n, o, p, q, l, m; d is the per-branch sort key (position).
     *  l/m ride only on the 's' branch: the story's author_id and a
     *  viewer-admin scalar, so the form can show coauthor management to the
     *  owner/admin alone. n/o/p/q ride the 's' branch only too: the
     *  syndication pair (canonical_url, crosspost_url) the edit form
     *  prefills, then round_robin + gift_to.
     *  Compound SELECTs may only ORDER BY output columns. */
    public function formData(?string $slug, int $userId): array
    {
        $taxonomy = "SELECT 'cat' AS k, c.id AS a, c.name AS b, c.slug AS c, c.position AS d, NULL AS e, NULL AS f, NULL AS g, NULL AS h, NULL AS i, NULL AS j, NULL AS n, NULL AS o, NULL AS p, NULL AS q, NULL AS l, NULL AS m
                     FROM categories c
                     UNION ALL
                     SELECT 'r', r.id, r.label, NULL, r.position, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL
                     FROM ratings r";
        if ($slug === null) {
            return $this->db->all($taxonomy . ' ORDER BY k, d');
        }
        return $this->db->all(
            "SELECT 's' AS k, s.id AS a, s.title AS b, s.summary AS c, s.notes AS d,
                    (SELECT GROUP_CONCAT(sc.category_id) FROM story_categories sc WHERE sc.story_id = s.id) AS e,
                    s.rating_id AS f, s.completed AS g,
                    (SELECT json_group_array(json_object('position', ch.position, 'title', ch.title, 'validated', ch.validated))
                     FROM chapters ch WHERE ch.story_id = s.id) AS h,
                    s.is_restricted AS i, s.language AS j,
                    s.canonical_url AS n, s.crosspost_url AS o, s.round_robin AS p, s.gift_to AS q,
                    s.author_id AS l,
                    (SELECT COUNT(*) FROM users adm WHERE adm.id = ? AND adm.role = 'admin') AS m
             FROM stories s
             WHERE s.slug = ? AND s.deleted_at IS NULL
               AND (s.author_id = ?
                    OR EXISTS (SELECT 1 FROM coauthors ca WHERE ca.story_id = s.id AND ca.user_id = ?)
                    OR EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND u.role = 'admin'))
             UNION ALL " . $taxonomy . "
             UNION ALL
             SELECT 'co' AS k, cu.id AS a, cu.penname AS b, NULL AS c, cu.id AS d,
                    NULL AS e, NULL AS f, NULL AS g, NULL AS h, NULL AS i, NULL AS j, NULL AS n, NULL AS o, NULL AS p, NULL AS q, NULL AS l, NULL AS m
             FROM coauthors c2 JOIN stories s2 ON s2.id = c2.story_id JOIN users cu ON cu.id = c2.user_id
             WHERE s2.slug = ?
             ORDER BY k, d",
            // SIX binds in text order: the 's' branch's SELECT-list admin scalar
            // first (SELECT-list binds precede WHERE binds), then the 's' WHERE
            // (slug + three gate binds), then the 'co' branch's slug. Count the
            // ?s before touching this array.
            [$userId, $slug, $userId, $userId, $userId, $slug]);
    }

    public function slugTaken(string $slug): bool
    {
        return $this->db->one('SELECT 1 AS x FROM stories WHERE slug = ?', [$slug]) !== null;
    }

    /** @return array{0: int, 1: string, 2: array, 3: array, 4: string} id, slug,
     *  category slugs, series slugs, author profile slug (purge coordinates) */
    public function createStory(int $userId, string $title, string $summary, string $notes, int $ratingId,
                                array $categoryIds, bool $validated, bool $restricted, string $language,
                                bool $roundRobin = false, string $giftTo = ''): array
    {
        $slug = \App\Slug::unique(fn(string $s): bool => $this->slugTaken($s), \App\Slug::make($title));
        $this->db->begin();
        try {
            $this->db->query(
                'INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, validated, is_restricted, language, round_robin, gift_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$title, $slug, $summary, $notes, $userId, $ratingId, $validated ? 1 : 0, $restricted ? 1 : 0, $language, $roundRobin ? 1 : 0, $giftTo]);
            $storyId = (int) $this->db->lastInsertId();
            $this->writeCategories($storyId, $categoryIds);
            $this->db->commit();
            [$seriesSlugs, $authorSlug] = $this->purgeData($storyId, $userId);
            return [$storyId, $slug, $this->categorySlugs($storyId), $seriesSlugs, $authorSlug];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0: string, 1: array, 2: array, 3: string} new slug, category
     *  slugs before+after, series slugs, author profile slug (purge coordinates).
     *  The syndication pair arrives pre-validated by the controller (http(s)
     *  only, 200 chars max, never both set); empty strings clear the state. */
    public function updateStory(string $slug, int $userId, string $title, string $summary, string $notes,
                                int $ratingId, array $categoryIds, bool $completed, bool $restricted, string $language,
                                bool $roundRobin = false, string $giftTo = '',
                                string $canonicalUrl = '', string $crosspostUrl = ''): array
    {
        $story = $this->ownStory($slug, $userId);
        $newSlug = $slug;
        if (\App\Slug::make($title) !== \App\Slug::make($story['title'])) {
            $newSlug = \App\Slug::unique(fn(string $s): bool => $this->slugTaken($s), \App\Slug::make($title));
        }
        $oldCats = $this->categorySlugs((int) $story['id']);
        $this->db->begin();
        try {
            $this->db->query('UPDATE stories SET title = ?, slug = ?, summary = ?, notes = ?, rating_id = ?,
                              completed = ?, is_restricted = ?, language = ?, round_robin = ?, gift_to = ?,
                              canonical_url = ?, crosspost_url = ?,
                              updated_at = ? WHERE id = ?',
                [$title, $newSlug, $summary, $notes, $ratingId, $completed ? 1 : 0, $restricted ? 1 : 0, $language,
                 $roundRobin ? 1 : 0, $giftTo,
                 $canonicalUrl === '' ? null : $canonicalUrl, $crosspostUrl === '' ? null : $crosspostUrl,
                 date('c'), $story['id']]);
            $this->writeCategories((int) $story['id'], $categoryIds);
            $this->db->commit();
            [$seriesSlugs, $authorSlug] = $this->purgeData((int) $story['id'], (int) $story['author_id']);
            return [$newSlug, array_values(array_unique(array_merge($oldCats, $this->categorySlugs((int) $story['id'])))), $seriesSlugs, $authorSlug];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0: string, 1: array, 2: array, 3: string}|null slug, category
     *  slugs, series slugs, author profile slug (purge coordinates) */
    public function deleteStory(string $slug, int $userId): ?array
    {
        $story = $this->ownStory($slug, $userId);
        $cats = $this->categorySlugs((int) $story['id']);
        $this->db->query('UPDATE stories SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL', [date('c'), $story['id']]);
        [$seriesSlugs, $authorSlug] = $this->purgeData((int) $story['id'], (int) $story['author_id']);
        return [$slug, $cats, $seriesSlugs, $authorSlug];
    }

    /** Author, coauthor, or admin (admin resolved SQL-side; a bound-bool admin
     *  param would silently revoke admin access at the ($slug, $userId)-only
     *  call sites, plan review finding 7). */
    public function ownStory(string $slug, int $userId): array
    {
        $story = $this->db->one(
            "SELECT id, title, author_id FROM stories WHERE slug = ? AND deleted_at IS NULL
              AND (author_id = ?
                   OR EXISTS (SELECT 1 FROM coauthors ca WHERE ca.story_id = stories.id AND ca.user_id = ?)
                   OR EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND u.role = 'admin'))",
            [$slug, $userId, $userId, $userId]);
        if ($story === null) throw new \RuntimeException('not found');
        return $story;
    }

    private function writeCategories(int $storyId, array $categoryIds): void
    {
        $this->db->query('DELETE FROM story_categories WHERE story_id = ?', [$storyId]);
        foreach (array_unique(array_map('intval', $categoryIds)) as $cid) {
            if ($cid > 0) {
                // INSERT ... SELECT drops unknown ids (forged or stale input) instead of
                // tripping the FK constraint, mirroring the non-numeric filter upstream.
                $this->db->query('INSERT INTO story_categories (story_id, category_id) SELECT ?, id FROM categories WHERE id = ?',
                    [$storyId, $cid]);
            }
        }
    }

    /** @return string[] */
    public function categorySlugs(int $storyId): array
    {
        return array_column($this->db->all(
            'SELECT c.slug FROM story_categories sc JOIN categories c ON c.id = sc.category_id WHERE sc.story_id = ?',
            [$storyId]), 'slug');
    }

    /** Purge coordinates shared by every write return (plan review finding 18):
     *  the confirmed series slugs containing the story plus the author's
     *  profile slug. Cache::purgeStory consumes both, so a member story
     *  changing refreshes its series pages and the author's profile pages. */
    public function purgeData(int $storyId, int $authorId): array
    {
        return [
            (new SeriesRepository($this->db))->seriesSlugsForStory($storyId),
            (string) ($this->db->one('SELECT profile_slug FROM users WHERE id = ?', [$authorId])['profile_slug'] ?? ''),
        ];
    }

    /** Story owner or admin STRICTLY (NOT the coauthor-inclusive story gate:
     *  coauthors gain editing rights, not coauthor-management rights, matching
     *  the owner/admin-only form section) adds; the coauthor must be a full
     *  member, not the author, not already attached. Returns the attached id. */
    public function addCoauthor(string $slug, string $penname, int $actorId): int
    {
        $story = $this->db->one(
            "SELECT s.id, s.author_id, (SELECT COUNT(*) FROM users v WHERE v.id = ? AND v.role = 'admin') is_admin
             FROM stories s WHERE s.slug = ?", [$actorId, $slug]);
        if ($story === null
            || ((int) $story['author_id'] !== $actorId && (int) $story['is_admin'] === 0)) {
            throw new \RuntimeException('not found');
        }
        $user = $this->db->one("SELECT id, email, penname, profile_slug FROM users WHERE penname = ? COLLATE NOCASE AND approved_at IS NOT NULL AND email_verified_at IS NOT NULL AND is_locked = 0", [$penname]);
        if ($user === null) throw new \RuntimeException('member not found');
        if ((int) $user['id'] === (int) $story['author_id']) throw new \RuntimeException('already the author');
        $guard = $this->db->query('INSERT OR IGNORE INTO coauthors (story_id, user_id) VALUES (?, ?)', [$story['id'], $user['id']]);
        if ($guard->rowCount() === 0) throw new \RuntimeException('already a coauthor');
        return (int) $user['id'];
    }

    /** Owner/admin may remove anyone; anyone may remove themselves (leave). */
    public function removeCoauthor(string $slug, int $targetId, int $actorId): bool
    {
        $story = $this->db->one(
            'SELECT s.id, s.author_id, (SELECT COUNT(*) FROM users v WHERE v.id = ? AND v.role = \'admin\') is_admin
             FROM stories s WHERE s.slug = ?', [$actorId, $slug]);
        if ($story === null) return false;
        if ($targetId !== $actorId && (int) $story['author_id'] !== $actorId && (int) $story['is_admin'] === 0) return false;
        $guard = $this->db->query('DELETE FROM coauthors WHERE story_id = ? AND user_id = ?', [$story['id'], $targetId]);
        return $guard->rowCount() > 0;
    }

    /** Chapter row + story context, owner/coauthor/admin gated; one query.
     *  For NEW chapters ($position null) the next position rides along
     *  (NULL row). */
    public function chapterFormData(string $slug, ?int $position, int $userId): ?array
    {
        $chapterJoin = $position === null
            ? 'LEFT JOIN chapters ch ON ch.story_id = s.id AND ch.position = (SELECT COALESCE(MAX(position), 0) + 1 FROM chapters WHERE story_id = s.id)'
            : 'LEFT JOIN chapters ch ON ch.story_id = s.id AND ch.position = ' . (int) $position;
        return $this->db->one(
            "SELECT s.id AS story_id, s.title AS story_title, s.slug, ch.title, ch.notes_before, ch.content, ch.notes_after, ch.publish_at, ch.position
             FROM stories s {$chapterJoin}
             WHERE s.slug = ? AND s.deleted_at IS NULL
               AND (s.author_id = ?
                    OR EXISTS (SELECT 1 FROM coauthors ca WHERE ca.story_id = s.id AND ca.user_id = ?)
                    OR EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND u.role = 'admin'))",
            [$slug, $userId, $userId, $userId]);
    }

    /** @return array{0: array, 1: array, 2: string} category slugs, series slugs,
     *  author profile slug (purge coordinates) */
    public function createChapter(string $slug, int $userId, string $title, string $content, string $before, string $after, bool $validated, ?string $publishAt = null): array
    {
        $story = $this->ownStory($slug, $userId);
        $words = \App\Markdown::wordCount($content);
        // Scheduling is explicit: a publish_at holds the chapter at validated = 0
        // regardless of the author's auto-validate standing (it goes live when
        // releaseDue fires, not before).
        $live = ($validated && $publishAt === null) ? 1 : 0;
        $this->db->begin();
        try {
            $this->db->query(
                'INSERT INTO chapters (story_id, position, title, notes_before, content, notes_after, validated, word_count, publish_at)
                 SELECT ?, COALESCE(MAX(position), 0) + 1, ?, ?, ?, ?, ?, ?, ? FROM chapters WHERE story_id = ?',
                [$story['id'], $title, $before, $content, $after, $live, $words, $publishAt, $story['id']]);
            $this->touchStory($story['id']);
            $this->db->commit();
            [$seriesSlugs, $authorSlug] = $this->purgeData((int) $story['id'], (int) $story['author_id']);
            return [$this->categorySlugs((int) $story['id']), $seriesSlugs, $authorSlug];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0: array, 1: array, 2: string} */
    public function updateChapter(string $slug, int $position, int $userId, string $title, string $content, string $before, string $after, ?string $publishAt = null): array
    {
        $story = $this->ownStory($slug, $userId);
        $this->db->begin();
        try {
            // The schedule follows the form: the input repopulates from the row
            // (chapterFormData selects publish_at), so an untouched schedule
            // round-trips and an emptied field clears it. A live chapter with a
            // stray publish_at is inert: releaseDue's WHERE demands validated = 0.
            $this->db->query('UPDATE chapters SET title = ?, notes_before = ?, content = ?, notes_after = ?,
                              word_count = ?, publish_at = ?, updated_at = ? WHERE story_id = ? AND position = ?',
                [$title, $before, $content, $after, \App\Markdown::wordCount($content), $publishAt, date('c'), $story['id'], $position]);
            $this->touchStory($story['id']);
            $this->db->commit();
            [$seriesSlugs, $authorSlug] = $this->purgeData((int) $story['id'], (int) $story['author_id']);
            return [$this->categorySlugs((int) $story['id']), $seriesSlugs, $authorSlug];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0: array, 1: array, 2: string} */
    public function deleteChapter(string $slug, int $position, int $userId): array
    {
        $story = $this->ownStory($slug, $userId);
        $this->db->begin();
        try {
            $this->db->query('DELETE FROM chapters WHERE story_id = ? AND position = ?', [$story['id'], $position]);
            $this->db->query('UPDATE chapters SET position = position - 1 WHERE story_id = ? AND position > ?', [$story['id'], $position]);
            $this->touchStory($story['id']);
            $this->db->commit();
            [$seriesSlugs, $authorSlug] = $this->purgeData((int) $story['id'], (int) $story['author_id']);
            return [$this->categorySlugs((int) $story['id']), $seriesSlugs, $authorSlug];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Updated-at + live word-count rollup, called inside the caller's transaction. */
    private function touchStory(int $storyId): void
    {
        $this->db->query('UPDATE stories SET updated_at = ?,
                          word_count = (SELECT COALESCE(SUM(word_count), 0) FROM chapters WHERE story_id = ?)
                          WHERE id = ?', [date('c'), $storyId, $storyId]);
    }

    /** Queue page in ONE query: the moderator gate is the zeroth branch.
     *  k='0gate' sorts ahead of every content row, so the LIMIT window can
     *  never cut it (a missing gate row reads as non-moderator = 403).
     *  No 'gate' row in the result = caller is not a moderator (403). */
    public function queueRows(int $userId): array
    {
        return $this->db->all(
            "SELECT '0gate' AS k, u.id AS a, u.penname AS b, u.role AS c, NULL AS d, NULL AS e, NULL AS f
             FROM users u WHERE u.id = ? AND u.role IN ('moderator', 'admin')
             UNION ALL
             SELECT 'story', s.id, s.title, s.slug, au.penname, s.updated_at, NULL
             FROM stories s JOIN users au ON au.id = s.author_id
             WHERE s.validated = 0 AND s.deleted_at IS NULL
             UNION ALL
             SELECT 'chapter', ch.id, ch.title, s.slug, au2.penname, ch.updated_at, NULL
             FROM chapters ch JOIN stories s ON s.id = ch.story_id JOIN users au2 ON au2.id = s.author_id
             WHERE ch.validated = 0 AND s.deleted_at IS NULL AND s.validated = 1
             UNION ALL
             SELECT 'member', us.id, us.penname, us.email, NULL, us.created_at, NULL
             FROM users us WHERE us.approved_at IS NULL AND us.email_verified_at IS NOT NULL
             UNION ALL
             SELECT 'report', rp.id, rp.reason, COALESCE(st.slug, ''), (SELECT body FROM reviews rb WHERE rb.id = rp.review_id), rp.created_at, NULL
             FROM reports rp LEFT JOIN stories st ON st.id = rp.story_id
             WHERE rp.resolved_at IS NULL
             ORDER BY k, e LIMIT 151", [$userId]);
    }

    /** Approve a story and every chapter under it. Returns [slug, cats, seriesSlugs,
     *  authorSlug] (purge coordinates) or null. */
    public function approveStory(int $storyId): ?array
    {
        $story = $this->db->one('SELECT id, slug, author_id FROM stories WHERE id = ? AND validated = 0 AND deleted_at IS NULL', [$storyId]);
        if ($story === null) return null;
        $cats = $this->categorySlugs((int) $story['id']);
        $this->db->begin();
        try {
            $this->db->query('UPDATE stories SET validated = 1, updated_at = ? WHERE id = ?', [date('c'), $story['id']]);
            $this->db->query('UPDATE chapters SET validated = 1 WHERE story_id = ?', [$story['id']]);
            $this->db->commit();
            [$seriesSlugs, $authorSlug] = $this->purgeData((int) $story['id'], (int) $story['author_id']);
            return [(string) $story['slug'], $cats, $seriesSlugs, $authorSlug];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** @return array{0: string, 1: array, 2: array, 3: string}|null */
    public function approveChapter(int $chapterId): ?array
    {
        $ch = $this->db->one(
            'SELECT ch.id, ch.story_id, s.slug, s.author_id FROM chapters ch JOIN stories s ON s.id = ch.story_id
             WHERE ch.id = ? AND ch.validated = 0 AND s.deleted_at IS NULL', [$chapterId]);
        if ($ch === null) return null;
        $cats = $this->categorySlugs((int) $ch['story_id']);
        $this->db->begin();
        try {
            // The publish_at clear is a no-op on the queue path (it is already
            // NULL there) and the release path's exit: an approved chapter
            // holds no schedule left to honor.
            $this->db->query('UPDATE chapters SET validated = 1, publish_at = NULL, updated_at = ? WHERE id = ?', [date('c'), $ch['id']]);
            $this->db->query('UPDATE stories SET updated_at = ? WHERE id = ?', [date('c'), $ch['story_id']]);
            $this->db->commit();
            [$seriesSlugs, $authorSlug] = $this->purgeData((int) $ch['story_id'], (int) $ch['author_id']);
            return [(string) $ch['slug'], $cats, $seriesSlugs, $authorSlug];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** Release every due scheduled chapter through the queue's own
     *  approveChapter, so the notify hooks and the purge coordinates ride
     *  the same flip the moderation queue uses. Idempotent by construction:
     *  the WHERE demands validated = 0 plus a due publish_at, and
     *  approveChapter clears both. The canonical Y-m-d\TH:i:s\Z storage makes
     *  the lexicographic compare against strftime's %f-instant exact.
     *  @return array<int, array{0: string, 1: array, 2: array, 3: string, 4: int}>
     *  per row: story slug, category slugs, series slugs, author profile
     *  slug, story id (the arm's purge + fan-out coordinates) */
    public function releaseDue(): array
    {
        $due = $this->db->all(
            "SELECT ch.id, ch.story_id FROM chapters ch JOIN stories s ON s.id = ch.story_id
             WHERE ch.validated = 0 AND ch.publish_at IS NOT NULL
               AND ch.publish_at <= strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
               AND s.deleted_at IS NULL");
        $released = [];
        foreach ($due as $row) {
            $coords = $this->approveChapter((int) $row['id']);
            if ($coords === null) continue; // raced to approved or vanished between the two queries
            $released[] = [$coords[0], $coords[1], $coords[2], $coords[3], (int) $row['story_id']];
        }
        return $released;
    }

    /** @return array{0: string, 1: array, 2: array, 3: string}|null */
    public function removeStory(int $storyId): ?array
    {
        $story = $this->db->one('SELECT id, slug, author_id FROM stories WHERE id = ? AND deleted_at IS NULL AND validated = 0', [$storyId]);
        if ($story === null) return null;
        $cats = $this->categorySlugs((int) $story['id']);
        $this->db->query('UPDATE stories SET deleted_at = ? WHERE id = ?', [date('c'), $story['id']]);
        [$seriesSlugs, $authorSlug] = $this->purgeData((int) $story['id'], (int) $story['author_id']);
        return [(string) $story['slug'], $cats, $seriesSlugs, $authorSlug];
    }

    /** @return array{0: string, 1: array, 2: array, 3: string}|null */
    public function removeChapter(int $chapterId): ?array
    {
        $ch = $this->db->one(
            'SELECT ch.id, ch.story_id, ch.position, s.slug, s.author_id FROM chapters ch JOIN stories s ON s.id = ch.story_id
             WHERE ch.id = ? AND ch.validated = 0 AND s.deleted_at IS NULL', [$chapterId]);
        if ($ch === null) return null;
        $cats = $this->categorySlugs((int) $ch['story_id']);
        $this->db->begin();
        try {
            $this->db->query('DELETE FROM chapters WHERE id = ?', [$ch['id']]);
            $this->db->query('UPDATE chapters SET position = position - 1 WHERE story_id = ? AND position > ?',
                [$ch['story_id'], $ch['position']]);
            $this->db->commit();
            [$seriesSlugs, $authorSlug] = $this->purgeData((int) $ch['story_id'], (int) $ch['author_id']);
            return [(string) $ch['slug'], $cats, $seriesSlugs, $authorSlug];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** Post-commit lookup for the notify hooks: story coordinates + the latest
     *  VALIDATED chapter (the thing followers can actually read). */
    public function storyForNotify(string $slug): ?array
    {
        return $this->db->one(
            "SELECT s.id AS story_id, s.author_id, s.title, s.slug,
                    COALESCE((SELECT c.position FROM chapters c WHERE c.story_id = s.id AND c.validated = 1
                              ORDER BY c.position DESC LIMIT 1), 0) AS latest_position,
                    (SELECT COUNT(*) FROM chapters c2 WHERE c2.story_id = s.id AND c2.validated = 1) AS live_chapters
             FROM stories s WHERE s.slug = ? AND s.deleted_at IS NULL", [$slug]);
    }
}
