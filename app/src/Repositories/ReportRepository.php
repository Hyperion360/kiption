<?php // app/src/Repositories/ReportRepository.php
namespace App\Repositories;

use Kip\Database;

final class ReportRepository
{
    public function __construct(private Database $db) {}

    /** @return array{0: bool|string} true, or an error slug: 'notfound'|'duplicate' */
    public function reportStory(string $slug, ?int $reporterId, string $reason, string $ip): array
    {
        $story = $this->db->one('SELECT id FROM stories WHERE slug = ? AND deleted_at IS NULL AND validated = 1', [$slug]);
        if ($story === null) return ['notfound'];
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) return ['empty'];
        $stmt = $this->db->query(
            'INSERT INTO reports (reporter_id, story_id, reason) SELECT ?, ?, ?
             WHERE NOT EXISTS (SELECT 1 FROM reports r WHERE r.story_id = ? AND r.reporter_id IS ? AND r.resolved_at IS NULL)',
            [$reporterId, $story['id'], $reason, $story['id'], $reporterId]);
        return $stmt->rowCount() === 1 ? [true] : ['duplicate'];
    }

    /** @return array{0: bool|string} */
    public function reportReview(int $reviewId, ?int $reporterId, string $reason): array
    {
        $review = $this->db->one('SELECT id FROM reviews WHERE id = ?', [$reviewId]);
        if ($review === null) return ['notfound'];
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) return ['empty'];
        $stmt = $this->db->query(
            'INSERT INTO reports (reporter_id, review_id, reason) SELECT ?, ?, ?
             WHERE NOT EXISTS (SELECT 1 FROM reports r WHERE r.review_id = ? AND r.reporter_id IS ? AND r.resolved_at IS NULL)',
            [$reporterId, $reviewId, $reason, $reviewId, $reporterId]);
        return $stmt->rowCount() === 1 ? [true] : ['duplicate'];
    }

    public function resolve(int $reportId): bool
    {
        $stmt = $this->db->query('UPDATE reports SET resolved_at = ? WHERE id = ? AND resolved_at IS NULL', [date('c'), $reportId]);
        return $stmt->rowCount() === 1;
    }
}
