<?php // app/src/Repositories/AuthoringRepository.php
namespace App\Repositories;

use Kip\Database;

final class AuthoringRepository
{
    public function __construct(private Database $db) {}

    /** ONE query for the story form: taxonomy rows for every caller, plus the
     *  story row (k='s') when editing. Ownership (author or admin) is enforced
     *  in SQL. Output columns: k, a..i; d is the per-branch sort key (position).
     *  Compound SELECTs may only ORDER BY output columns. */
    public function formData(?string $slug, int $userId): array
    {
        $taxonomy = "SELECT 'cat' AS k, c.id AS a, c.name AS b, c.slug AS c, c.position AS d, NULL AS e, NULL AS f, NULL AS g, NULL AS h, NULL AS i
                     FROM categories c
                     UNION ALL
                     SELECT 'r', r.id, r.label, NULL, r.position, NULL, NULL, NULL, NULL, NULL
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
                    s.is_restricted AS i
             FROM stories s
             WHERE s.slug = ? AND s.deleted_at IS NULL
               AND (s.author_id = ? OR EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND u.role = 'admin'))
             UNION ALL " . $taxonomy . ' ORDER BY k, d',
            [$slug, $userId, $userId]);
    }

    public function slugTaken(string $slug): bool
    {
        return $this->db->one('SELECT 1 AS x FROM stories WHERE slug = ?', [$slug]) !== null;
    }

    public function createStory(int $userId, string $title, string $summary, string $notes, int $ratingId,
                                array $categoryIds, bool $validated, bool $restricted): array
    {
        $slug = \App\Slug::unique(fn(string $s): bool => $this->slugTaken($s), \App\Slug::make($title));
        $this->db->begin();
        try {
            $this->db->query(
                'INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, validated, is_restricted) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$title, $slug, $summary, $notes, $userId, $ratingId, $validated ? 1 : 0, $restricted ? 1 : 0]);
            $storyId = (int) $this->db->lastInsertId();
            $this->writeCategories($storyId, $categoryIds);
            $this->db->commit();
            return [$storyId, $slug, $this->categorySlugs($storyId)];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0: string, 1: array} new slug, category slugs before+after */
    public function updateStory(string $slug, int $userId, string $title, string $summary, string $notes,
                                int $ratingId, array $categoryIds, bool $completed, bool $restricted): array
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
                              completed = ?, is_restricted = ?, updated_at = ? WHERE id = ?',
                [$title, $newSlug, $summary, $notes, $ratingId, $completed ? 1 : 0, $restricted ? 1 : 0, date('c'), $story['id']]);
            $this->writeCategories((int) $story['id'], $categoryIds);
            $this->db->commit();
            return [$newSlug, array_values(array_unique(array_merge($oldCats, $this->categorySlugs((int) $story['id']))))];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0: string, 1: array}|null slug + category slugs, for the purge caller */
    public function deleteStory(string $slug, int $userId): ?array
    {
        $story = $this->ownStory($slug, $userId);
        $cats = $this->categorySlugs((int) $story['id']);
        $this->db->query('UPDATE stories SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL', [date('c'), $story['id']]);
        return [$slug, $cats];
    }

    private function ownStory(string $slug, int $userId): array
    {
        $story = $this->db->one(
            "SELECT id, title FROM stories WHERE slug = ? AND deleted_at IS NULL
              AND (author_id = ? OR EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND u.role = 'admin'))",
            [$slug, $userId, $userId]);
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

    /** Chapter row + story context, owner/admin gated; one query. For NEW
     *  chapters ($position null) the next position rides along (NULL row). */
    public function chapterFormData(string $slug, ?int $position, int $userId): ?array
    {
        $chapterJoin = $position === null
            ? 'LEFT JOIN chapters ch ON ch.story_id = s.id AND ch.position = (SELECT COALESCE(MAX(position), 0) + 1 FROM chapters WHERE story_id = s.id)'
            : 'LEFT JOIN chapters ch ON ch.story_id = s.id AND ch.position = ' . (int) $position;
        return $this->db->one(
            "SELECT s.id AS story_id, s.title AS story_title, s.slug, ch.title, ch.notes_before, ch.content, ch.notes_after, ch.position
             FROM stories s {$chapterJoin}
             WHERE s.slug = ? AND s.deleted_at IS NULL
               AND (s.author_id = ? OR EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND u.role = 'admin'))",
            [$slug, $userId, $userId]);
    }

    /** @return array{0: array} category slugs for the purge caller */
    public function createChapter(string $slug, int $userId, string $title, string $content, string $before, string $after, bool $validated): array
    {
        $story = $this->ownStory($slug, $userId);
        $words = \App\Markdown::wordCount($content);
        $this->db->begin();
        try {
            $this->db->query(
                'INSERT INTO chapters (story_id, position, title, notes_before, content, notes_after, validated, word_count)
                 SELECT ?, COALESCE(MAX(position), 0) + 1, ?, ?, ?, ?, ?, ? FROM chapters WHERE story_id = ?',
                [$story['id'], $title, $before, $content, $after, $validated ? 1 : 0, $words, $story['id']]);
            $this->touchStory($story['id']);
            $this->db->commit();
            return [$this->categorySlugs((int) $story['id'])];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0: array} */
    public function updateChapter(string $slug, int $position, int $userId, string $title, string $content, string $before, string $after): array
    {
        $story = $this->ownStory($slug, $userId);
        $this->db->begin();
        try {
            $this->db->query('UPDATE chapters SET title = ?, notes_before = ?, content = ?, notes_after = ?,
                              word_count = ?, updated_at = ? WHERE story_id = ? AND position = ?',
                [$title, $before, $content, $after, \App\Markdown::wordCount($content), date('c'), $story['id'], $position]);
            $this->touchStory($story['id']);
            $this->db->commit();
            return [$this->categorySlugs((int) $story['id'])];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0: array} */
    public function deleteChapter(string $slug, int $position, int $userId): array
    {
        $story = $this->ownStory($slug, $userId);
        $this->db->begin();
        try {
            $this->db->query('DELETE FROM chapters WHERE story_id = ? AND position = ?', [$story['id'], $position]);
            $this->db->query('UPDATE chapters SET position = position - 1 WHERE story_id = ? AND position > ?', [$story['id'], $position]);
            $this->touchStory($story['id']);
            $this->db->commit();
            return [$this->categorySlugs((int) $story['id'])];
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
            "SELECT '0gate' AS k, u.id AS a, u.penname AS b, u.role AS c, NULL AS d, NULL AS e
             FROM users u WHERE u.id = ? AND u.role IN ('moderator', 'admin')
             UNION ALL
             SELECT 'story', s.id, s.title, s.slug, au.penname, s.updated_at
             FROM stories s JOIN users au ON au.id = s.author_id
             WHERE s.validated = 0 AND s.deleted_at IS NULL
             UNION ALL
             SELECT 'chapter', ch.id, ch.title, s.slug, au2.penname, ch.updated_at
             FROM chapters ch JOIN stories s ON s.id = ch.story_id JOIN users au2 ON au2.id = s.author_id
             WHERE ch.validated = 0 AND s.deleted_at IS NULL AND s.validated = 1
             UNION ALL
             SELECT 'member', us.id, us.penname, us.email, NULL, us.created_at
             FROM users us WHERE us.approved_at IS NULL AND us.email_verified_at IS NOT NULL
             ORDER BY k, e LIMIT 151", [$userId]);
    }

    /** Approve a story and every chapter under it. Returns [slug, cats] or null. */
    public function approveStory(int $storyId): ?array
    {
        $story = $this->db->one('SELECT id, slug FROM stories WHERE id = ? AND validated = 0 AND deleted_at IS NULL', [$storyId]);
        if ($story === null) return null;
        $cats = $this->categorySlugs((int) $story['id']);
        $this->db->begin();
        try {
            $this->db->query('UPDATE stories SET validated = 1, updated_at = ? WHERE id = ?', [date('c'), $story['id']]);
            $this->db->query('UPDATE chapters SET validated = 1 WHERE story_id = ?', [$story['id']]);
            $this->db->commit();
            return [(string) $story['slug'], $cats];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** @return array{0: string, 1: array}|null */
    public function approveChapter(int $chapterId): ?array
    {
        $ch = $this->db->one(
            'SELECT ch.id, ch.story_id, s.slug FROM chapters ch JOIN stories s ON s.id = ch.story_id
             WHERE ch.id = ? AND ch.validated = 0 AND s.deleted_at IS NULL', [$chapterId]);
        if ($ch === null) return null;
        $cats = $this->categorySlugs((int) $ch['story_id']);
        $this->db->begin();
        try {
            $this->db->query('UPDATE chapters SET validated = 1, updated_at = ? WHERE id = ?', [date('c'), $ch['id']]);
            $this->db->query('UPDATE stories SET updated_at = ? WHERE id = ?', [date('c'), $ch['story_id']]);
            $this->db->commit();
            return [(string) $ch['slug'], $cats];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** @return array{0: string, 1: array}|null */
    public function removeStory(int $storyId): ?array
    {
        $story = $this->db->one('SELECT id, slug FROM stories WHERE id = ? AND deleted_at IS NULL AND validated = 0', [$storyId]);
        if ($story === null) return null;
        $cats = $this->categorySlugs((int) $story['id']);
        $this->db->query('UPDATE stories SET deleted_at = ? WHERE id = ?', [date('c'), $story['id']]);
        return [(string) $story['slug'], $cats];
    }

    /** @return array{0: string, 1: array}|null */
    public function removeChapter(int $chapterId): ?array
    {
        $ch = $this->db->one(
            'SELECT ch.id, ch.story_id, ch.position, s.slug FROM chapters ch JOIN stories s ON s.id = ch.story_id
             WHERE ch.id = ? AND ch.validated = 0 AND s.deleted_at IS NULL', [$chapterId]);
        if ($ch === null) return null;
        $cats = $this->categorySlugs((int) $ch['story_id']);
        $this->db->begin();
        try {
            $this->db->query('DELETE FROM chapters WHERE id = ?', [$ch['id']]);
            $this->db->query('UPDATE chapters SET position = position - 1 WHERE story_id = ? AND position > ?',
                [$ch['story_id'], $ch['position']]);
            $this->db->commit();
            return [(string) $ch['slug'], $cats];
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
