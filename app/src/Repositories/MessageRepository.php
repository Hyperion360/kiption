<?php // app/src/Repositories/MessageRepository.php
namespace App\Repositories;
use App\Notifications;
use Kip\Database;

/** Private member-to-member messages. Unthrottled by recorded ruling
 *  (moderation rides the existing report queue; a throttle on conversations
 *  punishes normal back-and-forth), bodies clamped 1-5000 by the controller
 *  (the contact idiom) and rendered through the same markdown-at-rest
 *  pipeline as reviews, so raw HTML is unstoreable. Resolution is by
 *  PROFILE_SLUG (the findByProfileSlug gates: is_locked = 0, penname not
 *  null), folded into the page queries themselves so every surface stays a
 *  one-query shape (the storiesTab and authorFeed precedents). */
final class MessageRepository
{
    public function __construct(private Database $db) {}

    /** The INSERT plus the inbox notification (kind 'pm'; storyId and
     *  storyTitle null: a PM is never about a story row). The body arrives
     *  already clamped by the controller. */
    public function send(int $from, int $to, string $body): void
    {
        $this->db->query('INSERT INTO messages (sender_id, recipient_id, body) VALUES (?, ?, ?)', [$from, $to, $body]);
        (new Notifications($this->db))->create($to, 'pm', null, $from, null);
    }

    /** ONE query (the authorFeed anchor shape): the partner is the anchor row
     *  and the pair's newest-200 messages ride a LEFT JOIN from a derived
     *  table, re-ordered oldest-first outside it. Finding 3: windowing newest
     *  inside the derived table keeps the newest tail, where oldest-first +
     *  LIMIT would keep the FIRST 200 and drop it; the derived table cannot
     *  correlate to the anchor, so it resolves the same slug through its own
     *  index join. An empty thread renders one row with NULL message columns
     *  (a valid conversation opener, not a 404). FIVE binds in text order:
     *  [slug (the window's partner join), me, me (the pair arms),
     *  slug (the anchor)]. After the fetch, the recipient-side rows are
     *  marked read by a separate idempotent UPDATE (finding 4: it is the
     *  PM shape's excluded extra write under the page budget, and
     *  idempotence means a second open writes nothing). */
    public function thread(int $me, string $slug): ?array
    {
        if (!preg_match('#^[a-z0-9_-]+$#', $slug)) return null;
        $rows = $this->db->all(
            'SELECT u.id AS partner_id, u.penname AS partner_name, u.profile_slug AS partner_slug,
                    t.id AS mid, t.sender_id AS sender_id, t.body AS body, t.created_at AS created_at,
                    (SELECT penname FROM users su WHERE su.id = t.sender_id) AS sender_name
             FROM users u
             LEFT JOIN (SELECT m.id, m.sender_id, m.recipient_id, m.body, m.created_at
                        FROM messages m
                        JOIN users w ON w.profile_slug = ? AND w.is_locked = 0 AND w.penname IS NOT NULL
                        WHERE (m.sender_id = w.id AND m.recipient_id = ?)
                           OR (m.sender_id = ? AND m.recipient_id = w.id)
                        ORDER BY m.created_at DESC, m.id DESC LIMIT 200) t
                  ON t.sender_id = u.id OR t.recipient_id = u.id
             WHERE u.profile_slug = ? AND u.is_locked = 0 AND u.penname IS NOT NULL
             ORDER BY t.created_at, t.id',
            [$slug, $me, $me, $slug]);
        if ($rows === [] || (int) $rows[0]['partner_id'] === $me) return null;
        $this->markThreadRead($me, (int) $rows[0]['partner_id']);
        return $rows;
    }

    /** The idempotent read-marking: only rows I received that are still
     *  unread, so re-opening a thread updates nothing and read_at never
     *  churns. Served by idx_messages_recipient (recipient_id, read_at). */
    public function markThreadRead(int $me, int $other): void
    {
        $this->db->query(
            'UPDATE messages SET read_at = ? WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL',
            [date('c'), $me, $other]);
    }

    /** ONE query: the latest message per conversation via the GROUP BY
     *  NORMALIZED PAIR fold (finding 2, probed 6.6x faster than the
     *  correlated shape and half the binds, both index-served). The
     *  normalized pair packs the two ids small-id-first into one integer,
     *  so a conversation collapses to one group however the two members
     *  alternate directions. The per-row scalars fold the partner (the one
     *  of the pair who is not me) and MY incoming unread count (recipient
     *  side only, so my own replies never badge me; idx_messages_recipient
     *  serves the count). FIVE binds, every one $me, in text order:
     *  partner penname CASE, partner slug CASE, the unread scalar, then
     *  the fold's OR pair. The bounded TEMP B-TREE over the OR pair is
     *  pre-ruled and documented (finding 11, the review-window precedent). */
    public function inbox(int $me): array
    {
        return $this->db->all(
            'SELECT m.id, m.body, m.created_at,
                    CASE WHEN m.sender_id = ? THEN (SELECT penname FROM users pu WHERE pu.id = m.recipient_id)
                         ELSE (SELECT penname FROM users pu WHERE pu.id = m.sender_id) END AS partner_name,
                    CASE WHEN m.sender_id = ? THEN (SELECT profile_slug FROM users ps WHERE ps.id = m.recipient_id)
                         ELSE (SELECT profile_slug FROM users ps WHERE ps.id = m.sender_id) END AS partner_slug,
                    (SELECT COUNT(*) FROM messages un
                     WHERE un.recipient_id = ? AND un.read_at IS NULL
                       AND ((un.sender_id = m.sender_id AND un.recipient_id = m.recipient_id)
                         OR (un.sender_id = m.recipient_id AND un.recipient_id = m.sender_id))) AS unread
             FROM messages m
             JOIN (SELECT MAX(id) AS max_id FROM messages
                   WHERE sender_id = ? OR recipient_id = ?
                   GROUP BY CASE WHEN sender_id < recipient_id THEN sender_id * 1000000 + recipient_id
                                 ELSE recipient_id * 1000000 + sender_id END) t ON t.max_id = m.id
             ORDER BY m.id DESC',
            [$me, $me, $me, $me, $me]);
    }
}
