<?php // app/src/Controllers/BeaconController.php
namespace App\Controllers;
use Kip\{Database, Http\Response};

/** The zero-JS read beacon: cached chapter pages embed
 *  <img src="/beacon/read/{storyId}/{chapterId}">, and this route counts the
 *  read into page_stats and returns the SAME 1x1 GIF for every outcome (no
 *  oracle: a forged pair renders byte-identically to a counted read). Reads
 *  are approximate and labeled (the master plan's R4 stance): no bot
 *  filtering, no identity, aggregate day-keyed rows only. Restricted stories
 *  DO count (recorded): members read them and the beacon carries no
 *  identity, so the count leaks nothing; stats stay author-gated. */
final class BeaconController
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(private Database $db) {}

    public function read(string $storyId, string $chapterId): Response
    {
        // The router's segment whitelist admits non-digits and negatives
        // (/beacon/read/1/-3 dispatches), so the integer guard lives here.
        if (preg_match('/^[1-9][0-9]{0,8}$/', $storyId) !== 1
            || preg_match('/^[1-9][0-9]{0,8}$/', $chapterId) !== 1) {
            return new Response('Page not found', 404);
        }
        $pair = $this->db->one(
            'SELECT 1 AS x FROM stories s JOIN chapters c ON c.story_id = s.id
             WHERE s.id = ? AND c.id = ? AND s.validated = 1 AND s.deleted_at IS NULL AND c.validated = 1',
            [$storyId, $chapterId]);
        if ($pair !== null) {
            // Two upserts: the chapter row and the story-level rollup
            // (chapter_id 0, the schema sentinel that every read aggregate
            // reads). A write path: the one-query page budget does not apply.
            $this->db->query(
                "INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (strftime('%Y-%m-%d', 'now'), ?, ?, 1)
                 ON CONFLICT (day, story_id, chapter_id) DO UPDATE SET reads = reads + 1",
                [$storyId, $chapterId]);
            $this->db->query(
                "INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (strftime('%Y-%m-%d', 'now'), ?, 0, 1)
                 ON CONFLICT (day, story_id, chapter_id) DO UPDATE SET reads = reads + 1",
                [$storyId]);
        }
        return new Response(base64_decode(self::GIF), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }
}
