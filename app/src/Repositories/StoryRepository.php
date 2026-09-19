<?php // app/src/Repositories/StoryRepository.php
namespace App\Repositories;
use Kip\Database;

final class StoryRepository
{
    public function __construct(private Database $db) {}

    /** @return array<string,mixed>|null story row + penname, rating label/adult flag, category names */
    public function findStoryBySlug(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return null;
        return $this->db->one(
            'SELECT s.*, u.penname, r.label AS rating_label, r.is_adult, r.warning_text,
                    (SELECT GROUP_CONCAT(c.name, ", ") FROM story_categories sc
                     JOIN categories c ON c.id = sc.category_id
                     WHERE sc.story_id = s.id) AS category_names
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL',
            [$slug]
        );
    }

    /** @return list<array<string,mixed>> validated chapters, position order */
    public function chaptersForStory(int $storyId): array
    {
        return $this->db->all(
            'SELECT id, position, title, word_count, validated
             FROM chapters WHERE story_id = ? AND validated = 1
             ORDER BY position',
            [$storyId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentStories(int $perPage, int $offset): array
    {
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at,
                    u.penname, r.label AS rating_label
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT ? OFFSET ?',
            [$perPage, $offset]
        );
    }

    /** @return list<array<string,mixed>>|null null when the category slug is unknown */
    public function storiesInCategory(string $slug, int $perPage, int $offset): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return null;
        $cat = $this->db->one('SELECT id FROM categories WHERE slug = ?', [$slug]);
        if ($cat === null) return null;
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at,
                    u.penname, r.label AS rating_label
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             JOIN story_categories sc ON sc.story_id = s.id
             WHERE sc.category_id = ? AND s.validated = 1 AND s.deleted_at IS NULL
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT ? OFFSET ?',
            [(int) $cat['id'], $perPage, $offset]
        );
    }

    /** @return list<array<string,mixed>> */
    public function categoriesWithCounts(): array
    {
        return $this->db->all(
            'SELECT c.id, c.name, c.slug, c.description,
                    (SELECT COUNT(*) FROM story_categories sc
                     JOIN stories s ON s.id = sc.story_id
                     WHERE sc.category_id = c.id AND s.validated = 1 AND s.deleted_at IS NULL) AS story_count
             FROM categories c ORDER BY c.position, c.name'
        );
    }

    /** Single chapter with its story context for the reading page. */
    public function findChapter(int $storyId, int $position): ?array
    {
        return $this->db->one(
            'SELECT id, position, title, notes_before, content, notes_after, word_count
             FROM chapters WHERE story_id = ? AND position = ? AND validated = 1',
            [$storyId, $position]
        );
    }
}
