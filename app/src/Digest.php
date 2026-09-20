<?php // app/src/Digest.php
namespace App;

use Kip\Database;
use Kip\Mailer;

final class Digest
{
    public function __construct(private Database $db, private Mailer $mailer, private string $baseUrl) {}

    /** Batch every digest-eligible member's notifications since their marker.
     *  @return int number of members mailed */
    public function send(): int
    {
        $members = $this->db->all(
            "SELECT DISTINCT u.id, u.email, p.digest_sent_at
             FROM users u
             JOIN user_prefs p ON p.user_id = u.id
             WHERE u.email_verified_at IS NOT NULL AND u.approved_at IS NOT NULL
               AND (EXISTS (SELECT 1 FROM follows f WHERE f.follower_id = u.id AND f.notify_mode = 'digest')
                    OR p.notify_favorite_digest = 1)");
        // One template fetch per digest run, not per member (mail sends are not
        // page surfaces; the per-member values interpolate at send time).
        [$subject, $body] = Templates::get($this->db, 'digest', 'Your archive digest',
            "Since {since}:\n\n{lines}\n--\nYou receive this because at least one follow is in digest mode.");
        $mailed = 0;
        foreach ($members as $m) {
            $since = $m['digest_sent_at'] ?? '1970-01-01T00:00:00Z';
            $rows = $this->db->all(
                'SELECT kind, story_title, created_at FROM notifications
                 WHERE user_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 200',
                [$m['id'], $since]);
            if ($rows === []) continue;
            $lines = '';
            foreach ($rows as $r) {
                $lines .= '- ' . match ($r['kind']) {
                    'update' => 'New chapter in ' . $r['story_title'],
                    'kudos' => 'Kudos on ' . $r['story_title'],
                    'favorite' => 'A new favorite: ' . $r['story_title'],
                    'review' => 'A review on ' . $r['story_title'],
                    'reply' => 'A reply to your review',
                    'follow' => 'A new follower',
                    default => $r['kind'],
                } . " (" . $r['created_at'] . ")\n";
            }
            try {
                $this->mailer->send($m['email'], $subject,
                    strtr($body, ['{since}' => $since, '{lines}' => $lines]));
                // Written in SQLite's own created_at format (UTC, milliseconds) so the
                // next run's string comparison against notifications.created_at is exact:
                // a PHP date('c') marker sorts around the '.mmmZ' suffix and re-mails rows
                // batched in the same second.
                $this->db->query("UPDATE user_prefs SET digest_sent_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE user_id = ?", [$m['id']]);
                $mailed++;
            } catch (\Throwable $e) {
                error_log("digest mail failed for user {$m['id']}: {$e->getMessage()}");
            }
        }
        return $mailed;
    }
}
