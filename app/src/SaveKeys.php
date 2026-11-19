<?php
namespace App;

use Kip\Database;

/** The editor's JSON save keys: a retry after a lost response replays the
 *  recorded redirect instead of writing twice. One row per user and key,
 *  written inside the save's own transaction. */
final class SaveKeys
{
    private const TTL = 14 * 86400;

    public function __construct(private Database $db, private int $userId) {}

    public static function valid(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $key) === 1;
    }

    /** @return ?array{redirect: string}|array{conflict: true} null when the key is new */
    public function lookup(string $key, string $hash): ?array
    {
        $row = $this->db->one('SELECT request_hash, redirect FROM chapter_save_requests WHERE user_id = ? AND save_key = ?', [$this->userId, $key]);
        if ($row === null) return null;
        return hash_equals((string) $row['request_hash'], $hash) ? ['redirect' => (string) $row['redirect']] : ['conflict' => true];
    }

    /** Inside the caller's transaction. */
    public function record(string $key, string $hash, int $chapterId, string $operation, string $redirect): void
    {
        $this->db->query('DELETE FROM chapter_save_requests WHERE user_id = ? AND created_at < ?', [$this->userId, date('c', time() - self::TTL)]);
        $this->db->query('INSERT INTO chapter_save_requests (user_id, save_key, request_hash, chapter_id, operation, redirect, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->userId, $key, $hash, $chapterId, $operation, $redirect, date('c')]);
    }
}
