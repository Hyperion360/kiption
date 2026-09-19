<?php // app/src/Repositories/EngagementRepository.php
namespace App\Repositories;

use Kip\Database;

final class EngagementRepository
{
    public function __construct(private Database $db) {}

    /** @return array{0: bool inserted, 1: int authorId, 2: string title}
     *  INSERT OR IGNORE rides the two partial unique indexes (member user_id,
     *  guest ip); Database::query returns the PDOStatement so rowCount() is the
     *  inserted-or-not signal. One statement, race-free. */
    public function addKudos(string $slug, ?int $userId, string $ip): array
    {
        $story = $this->db->one('SELECT id, author_id, title FROM stories WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        if ($story === null) return [false, 0, ''];
        $stmt = $this->db->query('INSERT OR IGNORE INTO story_kudos (story_id, user_id, ip) VALUES (?, ?, ?)',
            [$story['id'], $userId, $ip]);
        return [$stmt->rowCount() === 1, (int) $story['author_id'], (string) $story['title']];
    }

    /** @return array{0: bool added, 1: int authorId, 2: string title} */
    public function toggleFavorite(string $slug, int $userId): array
    {
        $story = $this->db->one('SELECT id, author_id, title FROM stories WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        if ($story === null) return [false, 0, ''];
        $existing = $this->db->one('SELECT id FROM favorites WHERE user_id = ? AND story_id = ?', [$userId, $story['id']]);
        if ($existing !== null) {
            $this->db->query('DELETE FROM favorites WHERE id = ?', [$existing['id']]);
            return [false, (int) $story['author_id'], (string) $story['title']];
        }
        $this->db->query('INSERT INTO favorites (user_id, story_id) VALUES (?, ?)', [$userId, $story['id']]);
        return [true, (int) $story['author_id'], (string) $story['title']];
    }

    /** ONE query: the user's favorited stories, newest first. */
    public function favoritesRows(int $userId): array
    {
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary, s.updated_at,
                    (SELECT COUNT(*) FROM story_kudos k WHERE k.story_id = s.id) AS kudos_count
             FROM favorites f JOIN stories s ON s.id = f.story_id
             WHERE f.user_id = ? AND s.deleted_at IS NULL
             ORDER BY f.created_at DESC LIMIT 100', [$userId]);
    }
}
