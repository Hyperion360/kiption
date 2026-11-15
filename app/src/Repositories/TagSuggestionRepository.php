<?php // app/src/Repositories/TagSuggestionRepository.php
declare(strict_types=1);
namespace App\Repositories;

use Kip\Database;

/** Write-side rules for member tag suggestions: name normalization, the
 *  attach-or-park decision behind the tag_policy setting, and the caps that
 *  keep the queue honest. No HTTP. The caller owns the transaction (the
 *  story save calls submit inside its own), so every statement here assumes
 *  it runs inside one and never begins or commits. Pending names live only
 *  in tag_suggestions, never in tags, so no reader of tags can leak one. */
final class TagSuggestionRepository
{
    private const NAME_MIN = 2;
    private const NAME_MAX = 60;
    private const MAX_PER_SAVE = 5;
    private const MAX_PENDING_PER_MEMBER = 20;

    public function __construct(private Database $db) {}

    /** Split a typed comma list into clean names: control characters stripped,
     *  whitespace collapsed, angle brackets and backticks refused (a name can
     *  never carry markup), 2-60 characters, at most five per save,
     *  case-insensitive dedupe keeping the first spelling. */
    public static function normalize(string $raw): array
    {
        return self::normalizeDetailed($raw)[0];
    }

    /** @return array{0: list<string>, 1: array<string, string>} the clean
     *  names and, beside them, the pieces that were dropped with the reason:
     *  'name' for a refused or out-of-range piece. Empty pieces are not
     *  refused; a member typing a trailing comma said nothing. */
    private static function normalizeDetailed(string $raw): array
    {
        $out = [];
        $refused = [];
        $lower = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = preg_replace('/[\x00-\x1f\x7f]/', '', $piece) ?? '';
            $piece = trim((string) preg_replace('/\s+/u', ' ', $piece));
            if ($piece === '') { continue; }
            if (strpbrk($piece, '<>`') !== false || mb_strlen($piece) < self::NAME_MIN || mb_strlen($piece) > self::NAME_MAX) {
                $refused[$piece] = 'name';
                continue;
            }
            $key = mb_strtolower($piece);
            if (isset($lower[$key])) { continue; }
            $lower[$key] = true;
            $out[] = $piece;
            if (count($out) >= self::MAX_PER_SAVE) { break; }
        }
        return [$out, $refused];
    }

    /** Attach or park every typed name for one story save.
     *  @param array<int, string> $byType tag type id => comma list
     *  @param string $policy one of off, suggest, free (the tag_policy setting)
     *  @return array{attached: list<string>, pending: list<string>, created: list<string>, refused: array<string, string>}
     *  refused maps name => reason: name (bad name), type (the name is a live
     *  tag of another type; the same name in two types is not allowed),
     *  duplicate (a pending row already holds it), rejected (turned down here
     *  before), limit (the member's twenty pending), rate (the flood cap),
     *  off (the policy ignores suggestions). */
    public function submit(int $storyId, int $userId, array $byType, string $policy, string $ip = ''): array
    {
        $out = ['attached' => [], 'pending' => [], 'created' => [], 'refused' => []];
        if ($policy !== 'suggest' && $policy !== 'free') {
            foreach ($byType as $list) {
                foreach (self::normalizeDetailed((string) $list)[1] as $name => $why) { $out['refused'][$name] = $why; }
            }
            return $out;
        }
        if ($byType === []) { return $out; }
        $allowed = true;
        if ($ip !== '') {
            $allowed = (new \App\AttemptLimiter($this->db))->claim('tag_suggest', (string) $userId, $ip, 30, 60);
        }
        foreach ($byType as $typeId => $list) {
            $typeId = (int) $typeId;
            if ($this->db->one('SELECT 1 FROM tag_types WHERE id = ?', [$typeId]) === null) {
                continue; // an unknown type id filters nothing and parks nothing
            }
            foreach (self::normalizeDetailed((string) $list)[1] as $name => $why) {
                $out['refused'][$name] = $why; // dropped before any storage: no cap consumed
            }
            foreach (self::normalize((string) $list) as $name) {
                if (!$allowed) { $out['refused'][$name] = 'rate'; continue; }
                $this->place($out, $storyId, $userId, $typeId, $name, $policy);
            }
        }
        return $out;
    }

    /** One name's decision tree: existing same-type tag (through its
     *  canonical) attaches; another type's name is refused; otherwise the
     *  policy parks it pending or creates it live. */
    private function place(array &$out, int $storyId, int $userId, int $typeId, string $name, string $policy): void
    {
        $same = $this->db->one('SELECT id, canonical_id FROM tags WHERE tag_type_id = ? AND name = ? COLLATE NOCASE', [$typeId, $name]);
        if ($same !== null) {
            $this->attach($storyId, (int) $same['canonical_id'] ?: (int) $same['id']);
            $stored = $this->tagName((int) $same['canonical_id'] ?: (int) $same['id']);
            if (!in_array($stored, $out['attached'], true)) { $out['attached'][] = $stored; }
            return;
        }
        if ($this->db->one('SELECT 1 FROM tags WHERE name = ? COLLATE NOCASE', [$name]) !== null) {
            $out['refused'][$name] = 'type';
            return;
        }
        if ($policy === 'free') {
            $this->db->query('INSERT OR IGNORE INTO tags (tag_type_id, name) VALUES (?, ?)', [$typeId, $name]);
            $id = $this->db->one('SELECT id FROM tags WHERE tag_type_id = ? AND name = ? COLLATE NOCASE', [$typeId, $name]);
            if ($id === null) { $out['refused'][$name] = 'limit'; return; }
            $this->attach($storyId, (int) $id['id']);
            if (!in_array($name, $out['created'], true)) { $out['created'][] = $name; }
            return;
        }
        // suggest: park it pending. The counted insert caps each member at
        // twenty open rows; the UNIQUE (story, type, name) case-insensitive
        // index makes a resubmit a no-op, and a previously rejected name keeps
        // its rejected row so the member sees the outcome instead of waiting.
        $existing = $this->db->one(
            'SELECT status, name FROM tag_suggestions WHERE story_id = ? AND tag_type_id = ? AND name = ? COLLATE NOCASE',
            [$storyId, $typeId, $name]
        );
        if ($existing !== null) {
            $out['refused'][(string) $existing['name']] = $existing['status'] === 'rejected' ? 'rejected' : 'duplicate';
            return;
        }
        $inserted = $this->db->query(
            "INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at)
             SELECT ?, ?, ?, ?, 'pending', ?
             WHERE (SELECT COUNT(*) FROM tag_suggestions WHERE suggested_by = ? AND status = 'pending') < " . self::MAX_PENDING_PER_MEMBER,
            [$typeId, $name, $userId, $storyId, date('c'), $userId]
        )->rowCount() === 1;
        if (!$inserted) { $out['refused'][$name] = 'limit'; return; }
        if (!in_array($name, $out['pending'], true)) { $out['pending'][] = $name; }
    }

    /** The author's own pending rows, by id. Rows that are not theirs, or not
     *  pending, are silently kept: a forged id changes nothing. */
    public function withdraw(int $userId, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $i): bool => $i > 0));
        if ($ids === []) { return; }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->db->query(
            "DELETE FROM tag_suggestions WHERE suggested_by = ? AND status = 'pending' AND id IN ({$marks})",
            [$userId, ...$ids]
        );
    }

    private function attach(int $storyId, int $tagId): void
    {
        $this->db->query('INSERT OR IGNORE INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$storyId, $tagId]);
    }

    private function tagName(int $tagId): string
    {
        return (string) ($this->db->one('SELECT name FROM tags WHERE id = ?', [$tagId])['name'] ?? '');
    }
}
