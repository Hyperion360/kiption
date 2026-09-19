<?php // app/src/Notifications.php
namespace App;

use Kip\Database;

final class Notifications
{
    public function __construct(private Database $db) {}

    /** One notification. $storyTitle is denormalized so the inbox never joins stories
     *  (titles survive story renames as they were at event time; deletions cascade the row). */
    public function create(int $userId, string $kind, ?int $storyId, ?int $actorId, ?string $storyTitle): void
    {
        $this->db->query(
            'INSERT INTO notifications (user_id, kind, story_id, actor_id, story_title) VALUES (?, ?, ?, ?, ?)',
            [$userId, $kind, $storyId, $actorId, $storyTitle]);
    }

    public function markAllRead(int $userId): void
    {
        $this->db->query('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [date('c'), $userId]);
    }

    /** ONE query: newest 50 with the actor penname folded in via a scalar subquery. */
    public function inboxRows(int $userId): array
    {
        return $this->db->all(
            'SELECT n.kind, n.story_id, n.story_title, n.read_at, n.created_at,
                    (SELECT penname FROM users u WHERE u.id = n.actor_id) AS actor
             FROM notifications n WHERE n.user_id = ?
             ORDER BY n.created_at DESC LIMIT 50', [$userId]);
    }
}
