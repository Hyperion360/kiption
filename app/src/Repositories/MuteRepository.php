<?php // app/src/Repositories/MuteRepository.php
namespace App\Repositories;
use Kip\Database;

final class MuteRepository
{
    public function __construct(private Database $db) {}

    /** Flip one mute. Both statements are idempotent by shape: the insert
     *  rides the PK pair with OR IGNORE, the delete of a row that is not
     *  there is a clean no-op, so a double-submit or a stale tab writes the
     *  same state a single click would. */
    public function toggle(int $userId, int $authorId, bool $on): void
    {
        if ($on) {
            $this->db->query('INSERT OR IGNORE INTO muted (user_id, author_id) VALUES (?, ?)', [$userId, $authorId]);
        } else {
            $this->db->query('DELETE FROM muted WHERE user_id = ? AND author_id = ?', [$userId, $authorId]);
        }
    }

    /** THE one home of the listing anti-join fragment. Every viewer-gated
     *  listing appends this string to its WHERE and binds its viewer id at
     *  the clause's text position; nothing else may hand-copy it (seven
     *  hand-copied fragments is the misbind factory). The alias names the
     *  stories table at the call site ('s' everywhere except the folds).
     *  The ? is the FIRST bind the caller adds after the clause lands. */
    public static function clause(string $alias = 's'): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException("Bad table alias for the mute clause: {$alias}");
        }
        return " AND NOT EXISTS (SELECT 1 FROM muted mu WHERE mu.user_id = ? AND mu.author_id = {$alias}.author_id)";
    }
}
