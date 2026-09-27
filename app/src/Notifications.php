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

    /** ONE query: newest 50 with actor penname, actor profile slug, and story
     *  slug folded in via scalar subqueries. The actor_slug fold (finding 5)
     *  is param-free and serves the 'pm' kind's thread link; it changes
     *  nothing for the story-bound kinds. */
    public function inboxRows(int $userId): array
    {
        return $this->db->all(
            'SELECT n.kind, n.story_id, n.story_title, n.read_at, n.created_at,
                    (SELECT penname FROM users u WHERE u.id = n.actor_id) AS actor,
                    (SELECT profile_slug FROM users us WHERE us.id = n.actor_id) AS actor_slug,
                    (SELECT slug FROM stories st WHERE st.id = n.story_id) AS story_slug
             FROM notifications n WHERE n.user_id = ?
             ORDER BY n.created_at DESC LIMIT 50', [$userId]);
    }
}
