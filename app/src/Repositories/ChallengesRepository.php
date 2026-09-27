<?php // app/src/Repositories/ChallengesRepository.php
namespace App\Repositories;
use Kip\Database;

/** The challenges module's storage, the ListsRepository mirror (the freshest
 *  CRUD precedent): owner-gated through own() everywhere, one 404 shape for
 *  strangers and unknown slugs alike. The public one-query page fold, item
 *  membership, and notifications land with the public surface (next task). */
final class ChallengesRepository
{
    public function __construct(private Database $db) {}

    public function create(int $ownerId, string $title, string $summary, string $membership): string
    {
        $slug = \App\Slug::unique(
            fn (string $s): bool => $this->db->one('SELECT id FROM challenges WHERE slug = ?', [$s]) !== null,
            \App\Slug::make($title, 'challenge')
        );
        $this->db->query('INSERT INTO challenges (owner_id, title, slug, summary, membership) VALUES (?, ?, ?, ?, ?)',
            [$ownerId, $title, $slug, $summary, $membership]);
        return $slug;
    }

    public function update(string $slug, string $title, string $summary, string $membership, int $ownerId): void
    {
        $row = $this->own($slug, $ownerId);
        $this->db->query("UPDATE challenges SET title = ?, summary = ?, membership = ?,
                updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$title, $summary, $membership, $row['id']]);
    }

    public function delete(string $slug, int $ownerId): void
    {
        $row = $this->own($slug, $ownerId);
        $this->db->query('DELETE FROM challenges WHERE id = ?', [$row['id']]); // prompts and items ride the FK cascade
    }

    /** The edit form's row (id, owner_id, slug, title, summary, membership).
     *  Owner-ONLY, the Lists idiom: no admin override; a stranger's slug
     *  reads as 404, never 403, so the ownership state stays unobservable.
     *  Throws RuntimeException('not found'). */
    public function own(string $slug, int $ownerId): array
    {
        $row = $this->db->one('SELECT id, owner_id, slug, title, summary, membership FROM challenges WHERE slug = ?', [$slug]);
        if ($row === null || (int) $row['owner_id'] !== $ownerId) throw new \RuntimeException('not found');
        return $row;
    }

    /** The edit form's prompt rows, ordered by position; gaps after a removal
     *  are fine, ordering is by position. */
    public function promptsForEdit(int $challengeId): array
    {
        return $this->db->all(
            'SELECT id, position, prompt_text FROM challenge_prompts WHERE challenge_id = ? ORDER BY position',
            [$challengeId]
        );
    }

    /** Append a prompt after the last position. Owner-gated via own() (a
     *  stranger's challenge is the same 404 as an unknown slug). Text arrives
     *  pre-clamped and pre-validated by the controller (1-500, the listInput
     *  clamp idiom: controllers validate, repositories trust). */
    public function addPrompt(string $challengeSlug, string $text, int $ownerId): void
    {
        $challenge = $this->own($challengeSlug, $ownerId);
        $this->db->query(
            'INSERT INTO challenge_prompts (challenge_id, position, prompt_text)
             SELECT ?, COALESCE(MAX(position), 0) + 1, ? FROM challenge_prompts WHERE challenge_id = ?',
            [$challenge['id'], $text, $challenge['id']]
        );
    }

    /** Remove a prompt; false when the challenge is not the actor's or the
     *  prompt is not on it (one 404 shape, the removeItem idiom: a stranger's
     *  challenge reads as absent, same as a missing prompt). */
    public function removePrompt(string $challengeSlug, int $promptId, int $ownerId): bool
    {
        try { $challenge = $this->own($challengeSlug, $ownerId); }
        catch (\RuntimeException) { return false; }
        $row = $this->db->one('SELECT id FROM challenge_prompts WHERE id = ? AND challenge_id = ?', [$promptId, $challenge['id']]);
        if ($row === null) return false;
        $this->db->query('DELETE FROM challenge_prompts WHERE id = ?', [$row['id']]);
        return true;
    }

    /** Swap a prompt with its nearest lower/higher neighbour: the corrected
     *  sentinel form, transactional, the restore keyed on position AND
     *  challenge (the sentinel lives on position, never on id). A boundary
     *  move (top up, bottom down) is a calm no-op, not an error. */
    public function reorderPrompts(string $challengeSlug, int $promptId, string $dir, int $ownerId): void
    {
        $challenge = $this->own($challengeSlug, $ownerId);
        $prompt = $this->db->one('SELECT id, position FROM challenge_prompts WHERE id = ? AND challenge_id = ?', [$promptId, $challenge['id']]);
        if ($prompt === null) throw new \RuntimeException('not found');
        $op = $dir === 'down' ? '>' : '<';
        $ord = $dir === 'down' ? 'ASC' : 'DESC';
        $neighbour = $this->db->one(
            "SELECT id, position FROM challenge_prompts WHERE challenge_id = ? AND position {$op} ? ORDER BY position {$ord} LIMIT 1",
            [$challenge['id'], $prompt['position']]
        );
        if ($neighbour === null) return;
        $this->db->begin();
        try {
            $this->db->query('UPDATE challenge_prompts SET position = -1 WHERE id = ?', [$prompt['id']]);
            $this->db->query('UPDATE challenge_prompts SET position = ? WHERE id = ?', [$prompt['position'], $neighbour['id']]);
            $this->db->query('UPDATE challenge_prompts SET position = ? WHERE challenge_id = ? AND position = -1', [$neighbour['position'], $challenge['id']]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
}
