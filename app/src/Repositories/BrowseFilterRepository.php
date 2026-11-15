<?php // app/src/Repositories/BrowseFilterRepository.php
declare(strict_types=1);
namespace App\Repositories;
use Kip\Database;

/** The filter page's ONE statement: the picker (tag types, tags, the selected
 *  tags' names, categories, languages) and the paged results ride one compound,
 *  so the page stays inside the one-content-query budget. Arms are emitted in
 *  order and carry no ORDER BY on the compound itself, because that would force
 *  a temp sort over every row; each arm that needs an order owns it.
 *
 *  Planner rules the EXPLAIN test pins:
 *  - word and language predicates are written with a unary plus, so SQLite
 *    cannot choose idx_stories_words / idx_stories_language as the scan and
 *    then sort every other order by hand. Each sort keeps its own index.
 *  - the plus removes column affinity, and the driver binds numbers as text,
 *    so the word bounds carry CAST(? AS INTEGER).
 *  - the per-type tag cap is a window function with no outer ORDER BY: the
 *    index idx_tags_type_name already yields (type, name) order.
 *
 *  Binds follow SQL text order: selected tag ids, then the results arm
 *  (language, word bounds, category, tag constraints, mute viewer, limit, offset). */
final class BrowseFilterRepository
{
    public const TAGS_PER_TYPE = 100;

    private const ORDER = [
        'updated' => 's.updated_at DESC, s.id DESC',
        'created' => 's.created_at DESC, s.id DESC',
        'words'   => 's.word_count DESC, s.id DESC',
        'title'   => 's.title COLLATE NOCASE, s.id',
    ];

    public function __construct(private Database $db) {}

    /** @param int $viewer the member whose mutes apply; 0 for a guest or when the mute feature is off
     *  @return array{types:list<array{id:int,name:string}>,tags:list<array{id:int,name:string,type:int}>,selected:list<array{id:int,name:string,type:int}>,categories:list<array{slug:string,name:string}>,languages:list<string>,rows:list<array<string,mixed>>,hasMore:bool} */
    public function page(BrowseQuery $q, int $viewer = 0): array
    {
        [$sql, $binds] = $this->statement($q, $viewer);
        $types = $tags = $selected = $cats = $langs = $stories = [];
        foreach ($this->db->all($sql, $binds) as $r) {
            switch ($r['k']) {
                case 'g': $types[] = ['id' => (int) $r['a'], 'name' => (string) $r['b'], 'position' => (int) $r['c']]; break;
                case 't': $tags[] = ['id' => (int) $r['a'], 'name' => (string) $r['b'], 'type' => (int) $r['c']]; break;
                case 'x': $selected[] = ['id' => (int) $r['a'], 'name' => (string) $r['b'], 'type' => (int) $r['c']]; break;
                case 'c': $cats[] = ['slug' => (string) $r['a'], 'name' => (string) $r['b'], 'position' => (int) $r['c']]; break;
                case 'l': $langs[] = (string) $r['a']; break;
                default:
                    $stories[] = [
                        'slug' => (string) $r['a'], 'title' => (string) $r['b'], 'summary' => (string) $r['c'],
                        'completed' => (int) $r['d'], 'word_count' => (int) $r['e'], 'updated_at' => (string) $r['f'],
                        'penname' => (string) $r['g'], 'rating_label' => (string) $r['h'],
                    ];
            }
        }
        // The taxonomy arms are tiny, so their order is settled here, not in SQL.
        usort($tags, static fn (array $a, array $b): int => [$a['type'], strtolower($a['name']), $a['id']] <=> [$b['type'], strtolower($b['name']), $b['id']]);
        usort($types, static fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);
        usort($cats, static fn (array $a, array $b): int => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);
        sort($langs);
        return [
            'types' => array_map(static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name']], $types),
            'tags' => $tags,
            'selected' => $selected,
            'categories' => array_map(static fn (array $c): array => ['slug' => $c['slug'], 'name' => $c['name']], $cats),
            'languages' => $langs,
            'rows' => array_slice($stories, 0, BrowseQuery::PER_PAGE),
            'hasMore' => count($stories) > BrowseQuery::PER_PAGE,
        ];
    }

    /** @return array{0:string,1:list<mixed>} the statement and its binds, in SQL text order */
    public function statement(BrowseQuery $q, int $viewer = 0): array
    {
        $binds = [];
        $nulls = static fn (int $n): string => implode(', ', array_fill(0, $n, 'NULL'));
        $per = self::TAGS_PER_TYPE;
        // Languages in use, found by hopping the (validated, language) index one value at a time.
        $sql = "WITH RECURSIVE lang(l) AS (
                SELECT MIN(language) FROM stories WHERE validated = 1 AND language <> '' AND deleted_at IS NULL AND is_restricted = 0
                UNION ALL
                SELECT (SELECT MIN(language) FROM stories WHERE validated = 1 AND language > lang.l AND deleted_at IS NULL AND is_restricted = 0) FROM lang WHERE l IS NOT NULL
            )
            SELECT 'g' k, id a, name b, position c, NULL d, NULL e, NULL f, NULL g, NULL h, NULL i FROM tag_types
            UNION ALL
            SELECT 't', t.id, t.name, t.tag_type_id, {$nulls(6)}
              FROM tag_types g JOIN tags t ON t.id IN (
                   SELECT id FROM tags WHERE tag_type_id = g.id AND +canonical_id IS NULL
                    ORDER BY name COLLATE NOCASE LIMIT {$per})";
        $ids = array_merge($q->tags->include, $q->tags->exclude);
        if ($ids !== []) {
            $sql .= "
            UNION ALL
            SELECT 'x', t.id, t.name, t.tag_type_id, {$nulls(6)} FROM tags t WHERE t.id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ')';
            array_push($binds, ...$ids);
        }
        $sql .= "
            UNION ALL
            SELECT 'c', slug, name, position, {$nulls(6)} FROM categories
            UNION ALL
            SELECT 'l', l, {$nulls(8)} FROM lang WHERE l IS NOT NULL
            UNION ALL
            SELECT * FROM (
              SELECT 's', s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at, u.penname, r.label, NULL
                FROM stories s JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
               WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0";
        if ($q->status !== 'any') {
            $sql .= ' AND s.completed = ' . ($q->status === 'complete' ? '1' : '0');
        }
        if ($q->language !== '') {
            $sql .= ' AND +s.language = ?';
            $binds[] = $q->language;
        }
        if ($q->wmin !== null) {
            $sql .= ' AND +s.word_count >= CAST(? AS INTEGER)';
            $binds[] = $q->wmin;
        }
        if ($q->wmax !== null) {
            $sql .= ' AND +s.word_count <= CAST(? AS INTEGER)';
            $binds[] = $q->wmax;
        }
        if ($q->cat !== '') {
            $sql .= ' AND EXISTS (SELECT 1 FROM story_categories sc JOIN categories cc ON cc.id = sc.category_id WHERE sc.story_id = s.id AND cc.slug = ?)';
            $binds[] = $q->cat;
        }
        $sql .= $q->tags->sql($binds, 's');
        if ($viewer > 0) {
            $sql .= MuteRepository::clause('s');
            $binds[] = $viewer;
        }
        $sql .= ' ORDER BY ' . self::ORDER[$q->sort] . ' LIMIT ? OFFSET ?)';
        $binds[] = BrowseQuery::PER_PAGE + 1;
        $binds[] = $q->offset();
        return [$sql, $binds];
    }
}
