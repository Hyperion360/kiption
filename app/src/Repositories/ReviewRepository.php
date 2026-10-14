<?php // app/src/Repositories/ReviewRepository.php
namespace App\Repositories;

use Kip\Database;

final class ReviewRepository
{
    public function __construct(private Database $db) {}

    /** @return array{0: bool added, 1: int authorId, 2: string title, 3: string|null error} */
    public function addReview(string $slug, ?int $userId, ?string $guestName, string $body, ?int $rating, string $ip): array
    {
        $story = $this->db->one('SELECT id, author_id, title, is_restricted FROM stories WHERE slug = ? AND deleted_at IS NULL AND validated = 1', [$slug]);
        if ($story === null) return [false, 0, '', null];
        $body = trim($body);
        if ($body === '' || strlen($body) > 5000) return [false, 0, '', 'Review text is required (max 5000 characters).'];
        if ($rating !== null && $rating > 10) $rating = 10; // clamp high; negatives clamp to 0
        if ($rating !== null && $rating < 0) $rating = 0;
        if ($userId === null) {
            // Restricted works are registered-readers-only: a guest POST must draw
            // the same 404 as the story page, never a 302 existence oracle.
            if ((int) $story['is_restricted'] === 1) return [false, 0, '', null];
            $guestName = trim((string) $guestName);
            if ($guestName === '' || strlen($guestName) > 40) return [false, 0, '', 'Guest name is required (max 40 characters).'];
            // One statement, the report-intake pattern: a count-check followed by a
            // separate INSERT is check-then-act (two concurrent guests both counted 0
            // and both stored, probe-verified); INSERT..SELECT WHERE NOT EXISTS is
            // atomic under SQLite write serialization.
            $today = date('Y-m-d');
            $stmt = $this->db->query(
                "INSERT INTO reviews (story_id, user_id, guest_name, body, rating, ip)
                 SELECT ?, NULL, ?, ?, ?, ?
                 WHERE NOT EXISTS (SELECT 1 FROM reviews
                                   WHERE story_id = ? AND user_id IS NULL AND ip = ? AND substr(created_at, 1, 10) = ?)",
                [$story['id'], $guestName, $body, $rating, $ip, $story['id'], $ip, $today]);
            if ($stmt->rowCount() !== 1) return [false, 0, '', 'You already reviewed this story today.'];
            return [true, (int) $story['author_id'], (string) $story['title'], null];
        }
        $this->db->query('INSERT INTO reviews (story_id, user_id, body, rating, ip) VALUES (?, ?, ?, ?, ?)',
            [$story['id'], $userId, $body, $rating, $ip]);
        return [true, (int) $story['author_id'], (string) $story['title'], null];
    }

    /** @return array{0: bool added, 1: int authorId, 2: string|null error} */
    public function addChapterReview(string $slug, int $position, ?int $userId, ?string $guestName, string $body, string $ip): array
    {
        $story = $this->db->one('SELECT id, author_id, is_restricted FROM stories WHERE slug = ? AND deleted_at IS NULL AND validated = 1', [$slug]);
        if ($story === null) return [false, 0, 'not found'];
        if ($userId === null && (int) $story['is_restricted'] === 1) return [false, 0, 'not found']; // before chapter/body: no position oracle
        $ch = $this->db->one('SELECT id FROM chapters WHERE story_id = ? AND position = ? AND validated = 1', [(int) $story['id'], $position]);
        if ($ch === null) return [false, 0, 'not found'];
        $body = trim($body);
        if ($body === '' || strlen($body) > 5000) return [false, 0, 'Comment text is required (max 5000 characters).'];
        if ($userId === null) {
            $guestName = trim((string) $guestName);
            if ($guestName === '' || strlen($guestName) > 40) return [false, 0, 'Guest name is required (max 40 characters).'];
            $today = date('Y-m-d');
            $stmt = $this->db->query(
                "INSERT INTO reviews (story_id, chapter_id, user_id, guest_name, body, ip)
                 SELECT ?, ?, NULL, ?, ?, ?
                 WHERE NOT EXISTS (SELECT 1 FROM reviews
                                   WHERE story_id = ? AND user_id IS NULL AND ip = ? AND substr(created_at, 1, 10) = ?)",
                [$story['id'], $ch['id'], $guestName, $body, $ip, $story['id'], $ip, $today]);
            if ($stmt->rowCount() !== 1) return [false, 0, 'You already reviewed this story today.'];
            return [true, (int) $story['author_id'], null];
        }
        $this->db->query('INSERT INTO reviews (story_id, chapter_id, user_id, body, ip) VALUES (?, ?, ?, ?, ?)',
            [$story['id'], $ch['id'], $userId, $body, $ip]);
        return [true, (int) $story['author_id'], null];
    }

    /** @return list<array<string,mixed>> the chapter's comments with their one-level replies, newest roots first */
    public function chapterReviews(int $storyId, int $position): array
    {
        $ch = $this->db->one('SELECT id FROM chapters WHERE story_id = ? AND position = ? AND validated = 1', [$storyId, $position]);
        if ($ch === null) return [];
        return $this->db->all(
            'SELECT r.id, r.body, r.rating, r.parent_id, r.created_at, r.user_id, u.penname, r.guest_name
             FROM reviews r LEFT JOIN users u ON u.id = r.user_id
             WHERE r.chapter_id = ?
             ORDER BY r.parent_id IS NOT NULL, r.created_at DESC, r.id', [(int) $ch['id']]);
    }

    /** @return array{0: bool added, 1: int notifyUserId, 2: int storyId, 3: string slug, 4: string|null error}
     *  Replies flatten to the ROOT's thread (one level); the root's author is notified. */
    public function addReply(int $reviewId, ?int $userId, string $body): array
    {
        $row = $this->db->one(
            'SELECT r.id, r.parent_id, r.story_id, r.user_id AS poster, s.slug, s.author_id AS story_author, r.chapter_id
             FROM reviews r JOIN stories s ON s.id = r.story_id
             WHERE r.id = ? AND s.deleted_at IS NULL', [$reviewId]);
        if ($row === null) return [false, 0, 0, '', 'not found'];
        $body = trim($body);
        if ($body === '' || strlen($body) > 5000) return [false, 0, 0, '', 'Reply text is required (max 5000 characters).'];
        $rootId = $row['parent_id'] !== null ? (int) $row['parent_id'] : (int) $row['id'];
        $root = $rootId === (int) $row['id'] ? $row
            : $this->db->one('SELECT r.id, r.story_id, r.user_id AS poster, s.slug, s.author_id AS story_author, r.parent_id, r.chapter_id FROM reviews r JOIN stories s ON s.id = r.story_id WHERE r.id = ?', [$rootId]);
        $posterId = $userId === null ? null : $userId;
        $guestName = $userId === null ? 'Guest' : null;
        $this->db->query('INSERT INTO reviews (story_id, chapter_id, user_id, guest_name, body, parent_id, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$row['story_id'], $root['chapter_id'], $posterId, $guestName, $body, $rootId, '']);
        $notify = $root['poster'] !== null ? (int) $root['poster'] : 0;
        return [true, $notify, (int) $row['story_id'], (string) $row['slug'], null];
    }
}
