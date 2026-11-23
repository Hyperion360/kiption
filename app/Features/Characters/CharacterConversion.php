<?php // app/Features/Characters/CharacterConversion.php
declare(strict_types=1);
namespace App\Features\Characters;

use App\Repositories\TagSuggestionRepository;
use Kip\Database;

/** Moves the old Characters list into one "Characters" tag type, so characters
 *  get suggestions, synonyms and merging like every other tag. plan() is the
 *  read-only preview: two statements, nothing written. Names are matched
 *  case-insensitively, the same rule TagSuggestionRepository uses; the name
 *  rules themselves (length, no markup) are TagSuggestionRepository::normalize,
 *  never a copy. */
final class CharacterConversion
{
    public const TYPE_NAME = 'Characters';
    /** The word the admin types to confirm; matched without regard to case. */
    public const CONFIRM_WORD = 'convert';

    public function __construct(private Database $db) {}

    /** @return array{characters: int, stories: int, merges: array<string, list<string>>,
     *  blocked: array<string, string>, invalid: list<string>, categoryBound: list<array{name: string, category: string}>,
     *  reused: list<string>} merges: kept name => the spellings folded into it;
     *  blocked: name => the other tag type that already owns it; categoryBound:
     *  one row per character that loses its category (two fandoms can share a name); reused: names already tags of
     *  the Characters type. */
    public function plan(): array
    {
        $rows = $this->db->all(
            'SELECT ch.id, ch.name, cg.name AS category,
                    (SELECT COUNT(DISTINCT story_id) FROM story_characters) AS stories
             FROM characters ch LEFT JOIN categories cg ON cg.id = ch.category_id
             ORDER BY ch.id');
        $tagRows = $this->db->all(
            'SELECT t.name, tt.name AS type_name FROM tags t JOIN tag_types tt ON tt.id = t.tag_type_id');
        $owner = [];
        foreach ($tagRows as $t) $owner[mb_strtolower((string) $t['name'])] = (string) $t['type_name'];

        $out = ['characters' => count($rows), 'stories' => (int) ($rows[0]['stories'] ?? 0), 'merges' => [], 'blocked' => [],
                'invalid' => [], 'categoryBound' => [], 'reused' => []];
        $kept = [];
        foreach ($rows as $r) {
            $name = trim((string) $r['name']);
            if (TagSuggestionRepository::normalize($name) !== [$name]) { $out['invalid'][] = $name; continue; }
            $key = mb_strtolower($name);
            if ($r['category'] !== null) $out['categoryBound'][] = ['name' => $name, 'category' => (string) $r['category']];
            if (isset($kept[$key])) {
                if ($kept[$key] !== $name) $out['merges'][$kept[$key]][] = $name;
                continue;
            }
            $kept[$key] = $name;
            $type = $owner[$key] ?? null;
            if ($type === null) continue;
            if (mb_strtolower($type) === mb_strtolower(self::TYPE_NAME)) $out['reused'][] = $name;
            else $out['blocked'][$name] = $type;
        }
        return $out;
    }

    /** Does the move. Refuses (changing nothing) while plan() lists a blocked or
     *  invalid name. Otherwise, in one transaction: finds or makes the type,
     *  finds or makes one tag per case-insensitive name (first spelling wins),
     *  links every story that had the character, checks the link count, and only
     *  then deletes the old rows (the irreversible step). Any failure rolls the
     *  whole move back and rethrows. A second run finds no characters and does
     *  nothing. Category bindings are not kept: tags are archive-wide.
     *  @return array{status: string, tags: int, links: int, plan: array<string,mixed>} */
    public function run(int $adminId): array
    {
        $plan = $this->plan();
        if ($plan['characters'] === 0) return ['status' => 'nothing', 'tags' => 0, 'links' => 0, 'plan' => $plan];
        if ($plan['blocked'] !== [] || $plan['invalid'] !== []) return ['status' => 'refused', 'tags' => 0, 'links' => 0, 'plan' => $plan];

        $this->db->begin();
        try {
            $result = $this->move($adminId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['status' => 'converted', 'plan' => $plan] + $result;
    }

    /** @return array{tags: int, links: int} */
    private function move(int $adminId): array
    {
        $typeId = $this->typeId();
        $tagIds = [];  // lower-cased name => tag id
        foreach ($this->db->all('SELECT id, name FROM tags WHERE tag_type_id = ?', [$typeId]) as $t) {
            $tagIds[mb_strtolower((string) $t['name'])] = (int) $t['id'];
        }
        $made = 0;
        $pairs = [];   // "story:tag" => [story, tag]
        $rows = $this->db->all(
            'SELECT ch.id, ch.name, sc.story_id FROM characters ch
             LEFT JOIN story_characters sc ON sc.character_id = ch.id ORDER BY ch.id, sc.story_id');
        foreach ($rows as $r) {
            $name = trim((string) $r['name']);
            $key = mb_strtolower($name);
            if (!isset($tagIds[$key])) {
                $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$typeId, $name]);
                $tagIds[$key] = (int) $this->db->lastInsertId();
                $made++;
            }
            if ($r['story_id'] !== null) $pairs[$r['story_id'] . ':' . $tagIds[$key]] = [(int) $r['story_id'], $tagIds[$key]];
        }

        $ids = array_values(array_unique($tagIds));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $had = [];
        foreach ($this->db->all("SELECT story_id, tag_id FROM story_tags WHERE tag_id IN ($marks)", $ids) as $l) $had[$l['story_id'] . ':' . $l['tag_id']] = true;
        foreach ($pairs as $key => [$story, $tag]) {
            if (!isset($had[$key])) $this->db->query('INSERT OR IGNORE INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$story, $tag]);
        }
        $expected = count($had + $pairs);
        $actual = (int) $this->db->one("SELECT COUNT(*) AS n FROM story_tags WHERE tag_id IN ($marks)", $ids)['n'];
        if ($actual !== $expected) {
            throw new \RuntimeException("Link count mismatch ($actual of $expected); nothing was converted.");
        }

        $this->db->query('DELETE FROM story_characters');
        $this->db->query('DELETE FROM characters');
        \App\ActivityLog::record($this->db, $adminId, 'characters.convert', "tags $made, links " . count($pairs));
        return ['tags' => $made, 'links' => count($pairs)];
    }

    private function typeId(): int
    {
        $row = $this->db->one('SELECT id FROM tag_types WHERE name = ? COLLATE NOCASE', [self::TYPE_NAME]);
        if ($row !== null) return (int) $row['id'];
        $position = (int) $this->db->one('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM tag_types')['p'];
        $this->db->query('INSERT INTO tag_types (name, position) VALUES (?, ?)', [self::TYPE_NAME, $position]);
        return (int) $this->db->lastInsertId();
    }
}
