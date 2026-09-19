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
}
