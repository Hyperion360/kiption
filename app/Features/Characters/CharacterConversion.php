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

    public function __construct(private Database $db) {}

    /** @return array{characters: int, stories: int, merges: array<string, list<string>>,
     *  blocked: array<string, string>, invalid: list<string>, categoryBound: array<string, string>,
     *  reused: list<string>} merges: kept name => the spellings folded into it;
     *  blocked: name => the other tag type that already owns it; categoryBound:
     *  name => the category the character loses; reused: names already tags of
     *  the Characters type. */
    public function plan(): array
    {
        $rows = $this->db->all(
            'SELECT ch.id, ch.name, cg.name AS category,
                    (SELECT COUNT(*) FROM story_characters sc WHERE sc.character_id = ch.id) AS links
             FROM characters ch LEFT JOIN categories cg ON cg.id = ch.category_id
             ORDER BY ch.id');
        $tagRows = $this->db->all(
            'SELECT t.name, tt.name AS type_name FROM tags t JOIN tag_types tt ON tt.id = t.tag_type_id');
        $owner = [];
        foreach ($tagRows as $t) $owner[mb_strtolower((string) $t['name'])] = (string) $t['type_name'];

        $out = ['characters' => count($rows), 'stories' => 0, 'merges' => [], 'blocked' => [],
                'invalid' => [], 'categoryBound' => [], 'reused' => []];
        $out['stories'] = (int) $this->db->one('SELECT COUNT(DISTINCT story_id) AS n FROM story_characters')['n'];
        $kept = [];
        foreach ($rows as $r) {
            $name = trim((string) $r['name']);
            if (TagSuggestionRepository::normalize($name) !== [$name]) { $out['invalid'][] = $name; continue; }
            $key = mb_strtolower($name);
            if ($r['category'] !== null) $out['categoryBound'][$name] = (string) $r['category'];
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
}
