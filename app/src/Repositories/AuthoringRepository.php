<?php // app/src/Repositories/AuthoringRepository.php
namespace App\Repositories;

use Kip\Database;

final class AuthoringRepository
{
    public function __construct(private Database $db) {}

    /** ONE query for the story form: taxonomy rows for every caller, plus the
     *  story row (k='s') when editing. Ownership (author or admin) is enforced
     *  in SQL. Output columns: k, a..h; d is the per-branch sort key (position).
     *  Compound SELECTs may only ORDER BY output columns. */
    public function formData(?string $slug, int $userId): array
    {
        $taxonomy = "SELECT 'cat' AS k, c.id AS a, c.name AS b, c.slug AS c, c.position AS d, NULL AS e, NULL AS f, NULL AS g, NULL AS h
                     FROM categories c
                     UNION ALL
                     SELECT 'r', r.id, r.label, NULL, r.position, NULL, NULL, NULL, NULL
                     FROM ratings r";
        if ($slug === null) {
            return $this->db->all($taxonomy . ' ORDER BY k, d');
        }
        return $this->db->all(
            "SELECT 's' AS k, s.id AS a, s.title AS b, s.summary AS c, s.notes AS d,
                    (SELECT GROUP_CONCAT(sc.category_id) FROM story_categories sc WHERE sc.story_id = s.id) AS e,
                    s.rating_id AS f, s.completed AS g,
                    (SELECT json_group_array(json_object('position', ch.position, 'title', ch.title, 'validated', ch.validated))
                     FROM chapters ch WHERE ch.story_id = s.id) AS h
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
                                array $categoryIds, bool $validated): array
    {
        $slug = \App\Slug::unique(fn(string $s): bool => $this->slugTaken($s), \App\Slug::make($title));
        $this->db->begin();
        try {
            $this->db->query(
                'INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, validated) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$title, $slug, $summary, $notes, $userId, $ratingId, $validated ? 1 : 0]);
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
                                int $ratingId, array $categoryIds, bool $completed): array
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
                              completed = ?, updated_at = ? WHERE id = ?',
                [$title, $newSlug, $summary, $notes, $ratingId, $completed ? 1 : 0, date('c'), $story['id']]);
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
                $this->db->query('INSERT INTO story_categories (story_id, category_id) VALUES (?, ?)', [$storyId, $cid]);
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
}
