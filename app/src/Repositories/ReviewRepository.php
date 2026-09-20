<?php // app/src/Repositories/ReviewRepository.php
namespace App\Repositories;

use Kip\Database;

final class ReviewRepository
{
    public function __construct(private Database $db) {}

    /** @return array{0: bool added, 1: int authorId, 2: string title, 3: string|null error} */
    public function addReview(string $slug, ?int $userId, ?string $guestName, string $body, ?int $rating, string $ip): array
    {
        $story = $this->db->one('SELECT id, author_id, title FROM stories WHERE slug = ? AND deleted_at IS NULL AND validated = 1', [$slug]);
        if ($story === null) return [false, 0, '', null];
        $body = trim($body);
        if ($body === '' || strlen($body) > 5000) return [false, 0, '', 'Review text is required (max 5000 characters).'];
        if ($rating !== null && $rating > 10) $rating = 10; // clamp high; negatives clamp to 0
        if ($rating !== null && $rating < 0) $rating = 0;
        if ($userId === null) {
            $guestName = trim((string) $guestName);
            if ($guestName === '' || strlen($guestName) > 40) return [false, 0, '', 'Guest name is required (max 40 characters).'];
            $today = date('Y-m-d');
            $count = (int) $this->db->one(
                "SELECT COUNT(*) c FROM reviews WHERE story_id = ? AND user_id IS NULL AND ip = ? AND substr(created_at, 1, 10) = ?",
                [$story['id'], $ip, $today])['c'];
            if ($count >= 1) return [false, 0, '', 'You already reviewed this story today.'];
            $this->db->query('INSERT INTO reviews (story_id, user_id, guest_name, body, rating, ip) VALUES (?, NULL, ?, ?, ?, ?)',
                [$story['id'], $guestName, $body, $rating, $ip]);
            return [true, (int) $story['author_id'], (string) $story['title'], null];
        }
        $this->db->query('INSERT INTO reviews (story_id, user_id, body, rating, ip) VALUES (?, ?, ?, ?, ?)',
            [$story['id'], $userId, $body, $rating, $ip]);
        return [true, (int) $story['author_id'], (string) $story['title'], null];
    }
}
