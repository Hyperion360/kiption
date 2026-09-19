<?php // app/src/Repositories/StoryRepository.php
namespace App\Repositories;
use Kip\Database;

final class StoryRepository
{
    public function __construct(private Database $db) {}

    /** ONE query: story + author + rating + categories + chapter TOC blob.
     *  The blob is "position|title|word_count" joined with "~"; parse and
     *  ksort in PHP so ordering never depends on GROUP_CONCAT internals.
     *  @return array<string,mixed>|null */
    public function findStoryBySlug(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return null;
        return $this->db->one(
            'SELECT s.*, u.penname, r.label AS rating_label, r.is_adult, r.warning_text,
                    (SELECT GROUP_CONCAT(c.name, ", ") FROM story_categories sc
                     JOIN categories c ON c.id = sc.category_id
                     WHERE sc.story_id = s.id) AS category_names,
                    (SELECT GROUP_CONCAT(CAST(ch.position AS TEXT) || "|" || ch.title || "|" || CAST(ch.word_count AS TEXT), "~")
                     FROM chapters ch WHERE ch.story_id = s.id AND ch.validated = 1) AS chapters_blob
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL',
            [$slug]
        );
    }

    /** ONE query for the reading page: story meta plus the target chapter
     *  pivoted via conditional aggregation, plus the validated-position list
     *  for prev/next (positions can be non-contiguous when a middle chapter
     *  is unvalidated). ch_title NULL means the chapter does not exist.
     *  @return array<string,mixed>|null */
    public function findStoryWithChapter(string $slug, int $position): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return null;
        return $this->db->one(
            'SELECT s.id, s.slug, s.title, s.summary, s.completed, s.updated_at, s.word_count,
                    u.penname, r.label AS rating_label, r.is_adult, r.warning_text,
                    MAX(CASE WHEN ch.position = ? THEN ch.title END) AS ch_title,
                    MAX(CASE WHEN ch.position = ? THEN ch.notes_before END) AS ch_notes_before,
                    MAX(CASE WHEN ch.position = ? THEN ch.content END) AS ch_content,
                    MAX(CASE WHEN ch.position = ? THEN ch.notes_after END) AS ch_notes_after,
                    MAX(CASE WHEN ch.position = ? THEN ch.word_count END) AS ch_word_count,
                    (SELECT GROUP_CONCAT(CAST(ch2.position AS TEXT), "~") FROM chapters ch2
                     WHERE ch2.story_id = s.id AND ch2.validated = 1) AS positions_blob
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             LEFT JOIN chapters ch ON ch.story_id = s.id AND ch.validated = 1
             WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL
             GROUP BY s.id',
            [$position, $position, $position, $position, $position, $slug]
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

    /** ONE query: the category listing joined on the category slug. */
    public function storiesInCategory(string $slug, int $perPage, int $offset): array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return [];
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at,
                    u.penname, r.label AS rating_label
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             JOIN story_categories sc ON sc.story_id = s.id
             JOIN categories cc ON cc.id = sc.category_id
             WHERE cc.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT ? OFFSET ?',
            [$slug, $perPage, $offset]
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
}
