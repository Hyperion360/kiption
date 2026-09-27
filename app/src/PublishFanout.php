<?php // app/src/PublishFanout.php
namespace App;

use Kip\Database;
use Kip\Mailer;

/** The publish fan-out: one copy of what every publish path (queue approve,
 *  a live chapter's create or edit, the release:due arm) owes a story's
 *  audience. Extracted from the two controllers' identical privates so the
 *  CLI arm shares it too instead of growing a third copy. */
final class PublishFanout
{
    public function __construct(private Database $db, private Mailer $mailer, private string $baseUrl) {}

    public function publish(string $slug): void
    {
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        $story = $repo->storyForNotify($slug);
        if ($story === null || (int) $story['live_chapters'] === 0) return;
        $engagement = new \App\Repositories\EngagementRepository($this->db);
        [$followerIds, $followerEmails] = $engagement->followersToNotify((int) $story['author_id']);
        [$favoriterIds, $favoriterEmails] = $engagement->favoritersToNotify((int) $story['story_id']);
        // Union with dedupe by id: a member who both follows the author and favorited
        // the story gets ONE notification row, never two.
        $uniqueIds = [];
        foreach ($followerIds as $id) { $uniqueIds[$id] = true; }
        foreach ($favoriterIds as $id) { $uniqueIds[$id] = true; }
        // One immediate email per member across both channels: merging the two
        // user_id-keyed email maps dedupes by construction (entries are the same
        // users.email either way).
        $emails = $favoriterEmails;
        foreach ($followerEmails as $id => $email) { $emails[$id] = $email; }
        $notifications = new Notifications($this->db);
        foreach (array_keys($uniqueIds) as $memberId) {
            $notifications->create((int) $memberId, 'update', (int) $story['story_id'], (int) $story['author_id'], (string) $story['title']);
        }
        // One template fetch per publish, not per recipient (the plan's no-cache
        // ruling scopes to the send, never to a per-member query).
        [$subject, $body] = Templates::get($this->db, 'story_update', 'Story update: {title}',
            "A story you follow has a new chapter:\n\n{title}\n{url}");
        $pairs = [
            '{title}' => (string) $story['title'], '{slug}' => (string) $story['slug'],
            '{pos}' => (string) $story['latest_position'],
            '{url}' => "{$this->baseUrl}/story/read/{$story['slug']}/{$story['latest_position']}",
        ];
        foreach ($emails as $memberId => $email) {
            try {
                $this->mailer->send($email, strtr($subject, $pairs), strtr($body, $pairs));
            } catch (\Throwable $e) {
                error_log("follower mail failed for member {$memberId}: {$e->getMessage()}");
            }
        }
    }
}
