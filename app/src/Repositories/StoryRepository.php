<?php // app/src/Repositories/StoryRepository.php
namespace App\Repositories;
use Kip\Database;

final class StoryRepository
{
    public function __construct(private Database $db) {}

    /** ONE query: story + author + rating + categories + chapter TOC blob,
     *  plus the series membership and coauthor byline blobs (param-free scalar
     *  subqueries, so the bind order below is unchanged).
     *  The blobs are JSON: titles are free text (admin CRUD writes chapters
     *  today), so no hand-rolled delimiter scheme is safe to parse. json_group_array
     *  yields [] when the story has no validated chapters. Decode and ksort in
     *  PHP so ordering never depends on aggregation internals.
     *  Restricted stories 404 for guests at the SQL level: the gate CASTs $me
     *  because PDO binds int 0 as TEXT and a bare ? != 0 would compare across
     *  storage classes TRUE, failing the gate OPEN for guests.
     *  @return array<string,mixed>|null */
    public function findStoryBySlug(string $slug, int $me = 0): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return null;
        return $this->db->one(
            'SELECT s.*, u.penname, r.label AS rating_label, r.is_adult, r.warning_text,
                    (SELECT COUNT(*) FROM story_kudos k WHERE k.story_id = s.id) AS kudos_count,
                    (SELECT COUNT(*) FROM favorites f WHERE f.story_id = s.id) AS favorite_count,
                    (SELECT COUNT(*) FROM story_kudos k2 WHERE k2.story_id = s.id AND k2.user_id = ?) AS kudos_by_me,
                    (SELECT COUNT(*) FROM favorites f2 WHERE f2.story_id = s.id AND f2.user_id = ?) AS favorite_by_me,
                    (SELECT COUNT(*) FROM follows fo WHERE fo.author_id = s.author_id AND fo.follower_id = ?) AS following_author,
                    (SELECT rh.marked_at FROM reading_history rh WHERE rh.story_id = s.id AND rh.user_id = ?) AS marked_at_me,
                    (SELECT GROUP_CONCAT(c.name, ", ") FROM story_categories sc
                     JOIN categories c ON c.id = sc.category_id
                     WHERE sc.story_id = s.id) AS category_names,
                    (SELECT json_group_array(json_object(\'position\', ch.position, \'title\', ch.title, \'word_count\', ch.word_count))
                     FROM chapters ch WHERE ch.story_id = s.id AND ch.validated = 1
                     ORDER BY ch.position) AS chapters_blob,
                    (SELECT json_group_array(json_object(\'id\', r.id, \'user_id\', r.user_id, \'penname\',
                            (SELECT penname FROM users ru WHERE ru.id = r.user_id), \'guest_name\', r.guest_name,
                            \'body\', r.body, \'rating\', r.rating, \'parent_id\', r.parent_id, \'created_at\', r.created_at))
                     FROM (SELECT r.* FROM reviews r WHERE r.story_id = s.id ORDER BY r.created_at DESC LIMIT 50) r) AS reviews_blob,
                    (SELECT COUNT(*) FROM reviews r2 WHERE r2.story_id = s.id) AS review_count,
                    (SELECT su.support_url FROM users su WHERE su.id = s.author_id) AS support_url,
                    (SELECT json_group_array(json_object(\'s\', ser.slug, \'t\', ser.title))
                     FROM series_items si JOIN series ser ON ser.id = si.series_id
                     WHERE si.story_id = s.id AND si.confirmed = 1) AS series_blob,
                    (SELECT json_group_array(json_object(\'n\', cu.penname, \'p\', cu.profile_slug))
                     FROM coauthors ca JOIN users cu ON cu.id = ca.user_id WHERE ca.story_id = s.id) AS coauthors_blob
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)',
            [$me, $me, $me, $me, $slug, $me]
        );
    }

    /** ONE query for the reading page: story meta plus the target chapter
     *  pivoted via conditional aggregation, plus the validated-position list
     *  for prev/next (positions can be non-contiguous when a middle chapter
     *  is unvalidated). ch_title NULL means the chapter does not exist.
     *  Same restricted gate as findStoryBySlug (CAST is load-bearing).
     *  @return array<string,mixed>|null */
    public function findStoryWithChapter(string $slug, int $position, int $me = 0): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return null;
        return $this->db->one(
            'SELECT s.id, s.slug, s.title, s.summary, s.completed, s.created_at, s.updated_at, s.word_count,
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
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)
             GROUP BY s.id',
            [$position, $position, $position, $position, $position, $slug, $me]
        );
    }

    /** @return list<array<string,mixed>> Listings and feeds are guest surfaces and
     *  cannot personalize per viewer, so restricted works never appear here; members
     *  reach them by direct URL (the story queries gate per-viewer instead). */
    public function recentStories(int $perPage, int $offset): array
    {
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at, s.created_at,
                    u.penname, r.label AS rating_label
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT ? OFFSET ?',
            [$perPage, $offset]
        );
    }

    /** Language-filtered listing for the browse filter (recentStories' shape,
     *  capped at 50, no pagination). The query string that drives it makes
     *  these pages cache-ineligible: Cache refuses queryful GETs. */
    public function storiesInLanguage(string $language): array
    {
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at, s.created_at,
                    u.penname, r.label AS rating_label
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.language = ? AND s.is_restricted = 0
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT 50',
            [$language]
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
             WHERE cc.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0
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
