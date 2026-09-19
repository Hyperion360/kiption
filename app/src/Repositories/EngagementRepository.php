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

    /** @return array{0: bool inserted, 1: int authorId} fresh follows notify; idempotent re-follows do not.
     *  AuthorId 0 = no such user: the caller 404s before the FK can raise a 500. */
    public function addFollow(int $followerId, int $authorId): array
    {
        if ($followerId === $authorId) return [false, $authorId];
        if ($this->db->one('SELECT id FROM users WHERE id = ?', [$authorId]) === null) return [false, 0];
        $stmt = $this->db->query('INSERT OR IGNORE INTO follows (follower_id, author_id) VALUES (?, ?)', [$followerId, $authorId]);
        return [$stmt->rowCount() === 1, $authorId];
    }

    public function unfollow(int $followerId, int $authorId): void
    {
        $this->db->query('DELETE FROM follows WHERE follower_id = ? AND author_id = ?', [$followerId, $authorId]);
    }

    public function cycleNotifyMode(int $followerId, int $authorId): void
    {
        $this->db->query("UPDATE follows SET notify_mode = CASE notify_mode
            WHEN 'site' THEN 'email' WHEN 'email' THEN 'digest' ELSE 'site' END
            WHERE follower_id = ? AND author_id = ?", [$followerId, $authorId]);
    }

    /** @return array{0: int[], 1: array<int, string>} follower ids (inbox) and user_id => email (immediate mode) */
    public function followersToNotify(int $authorId): array
    {
        $rows = $this->db->all(
            "SELECT f.follower_id, CASE WHEN f.notify_mode = 'email' THEN u.email ELSE NULL END AS email
             FROM follows f JOIN users u ON u.id = f.follower_id WHERE f.author_id = ?", [$authorId]);
        $ids = [];
        $emails = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['follower_id'];
            if ($r['email'] !== null) $emails[(int) $r['follower_id']] = (string) $r['email'];
        }
        return [$ids, $emails];
    }

    /** Site-inbox notification targets: every follower regardless of mode (email/digest ADD delivery, never replace). */
    public function followerIds(int $authorId): array
    {
        return array_column($this->db->all('SELECT follower_id FROM follows WHERE author_id = ?', [$authorId]), 'follower_id');
    }

    /** Fire-and-forget progress upsert; NEVER moves the marker backwards.
     *  Exactly THREE params (OV probe: a fourth 'now' param is a binding error;
     *  updated_at refreshes via excluded.updated_at = the strftime default). */
    public function recordProgress(int $userId, int $storyId, int $position): void
    {
        $this->db->query(
            'INSERT INTO reading_history (user_id, story_id, last_position) VALUES (?, ?, ?)
             ON CONFLICT (user_id, story_id) DO UPDATE SET
                last_position = MAX(last_position, excluded.last_position),
                updated_at = excluded.updated_at',
            [$userId, $storyId, $position]);
    }

    public function toggleMark(string $slug, int $userId): bool
    {
        $story = $this->db->one('SELECT id FROM stories WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        if ($story === null) return false;
        $row = $this->db->one('SELECT marked_at FROM reading_history WHERE user_id = ? AND story_id = ?', [$userId, $story['id']]);
        if ($row === null) {
            $this->db->query('INSERT INTO reading_history (user_id, story_id, last_position, marked_at) VALUES (?, ?, 1, ?)',
                [$userId, $story['id'], date('c')]);
            return true;
        }
        if ($row['marked_at'] === null) {
            $this->db->query('UPDATE reading_history SET marked_at = ? WHERE user_id = ? AND story_id = ?', [date('c'), $userId, $story['id']]);
            return true;
        }
        $this->db->query('UPDATE reading_history SET marked_at = NULL WHERE user_id = ? AND story_id = ?', [$userId, $story['id']]);
        return false;
    }
}
