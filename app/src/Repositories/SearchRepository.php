<?php // app/src/Repositories/SearchRepository.php
namespace App\Repositories;
use Kip\Database;

final class SearchRepository
{
    private const MAX_TOKENS = 8;
    private ?bool $fts = null;

    public function __construct(private Database $db) {}

    /** Memoized FTS5 probe (the Auth::kindSplit precedent). */
    public function ftsAvailable(): bool
    {
        if ($this->fts === null) {
            try { $this->db->one('SELECT rowid FROM stories_fts LIMIT 1'); $this->fts = true; }
            catch (\PDOException) { $this->fts = false; }
        }
        return $this->fts;
    }

    /** @param array{category?:string,rating_id?:int,completed?:bool,language?:string} $filters */
    public function search(string $q, array $filters, int $perPage, int $offset, int $me): array
    {
        if (trim($q) === '' || self::matchExpression($q) === '') {
            return ['rows' => [], 'hasMore' => false, 'mode' => 'none'];
        }
        // Finding 6: never probe ftsAvailable() on the page path (the probe is a
        // second content query and breaks the budget pin); try FTS and memoize
        // the negative in the catch. ftsAvailable() stays public for direct tests.
        if ($this->fts !== false) {
            try { return $this->searchFts($q, $filters, $perPage, $offset, $me); }
            catch (\PDOException) { $this->fts = false; }
        }
        return $this->searchLike($q, $filters, $perPage, $offset, $me);
    }

    private function filterSql(array $filters, array &$params): string
    {
        $sql = '';
        if (!empty($filters['category'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM story_categories sc JOIN categories cc ON cc.id = sc.category_id WHERE sc.story_id = s.id AND cc.slug = ?)';
            $params[] = $filters['category'];
        }
        if (!empty($filters['rating_id'])) { $sql .= ' AND s.rating_id = ?'; $params[] = (int) $filters['rating_id']; }
        if (isset($filters['completed']) && $filters['completed'] !== null) { $sql .= ' AND s.completed = ?'; $params[] = $filters['completed'] ? 1 : 0; }
        if (!empty($filters['language'])) { $sql .= ' AND s.language = ?'; $params[] = $filters['language']; }
        return $sql;
    }

    private function selectList(): string
    {
        return 's.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at, u.penname, u.profile_slug, r.label AS rating_label';
    }

    private function fromGates(): string
    {
        return 'JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id'
            . ' WHERE s.validated = 1 AND s.deleted_at IS NULL AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)';
    }

    public function searchFts(string $q, array $filters, int $perPage, int $offset, int $me): array
    {
        $match = self::matchExpression($q);
        // Bind order (finding 2, probed): the two MATCH ?s live in the FROM
        // derived table, which binds BEFORE fromGates' CAST in the WHERE; then
        // filter params in filterSql's fixed order; then LIMIT/OFFSET.
        $params = [$match, $match, $me];
        $filter = $this->filterSql($filters, $params);
        $params[] = $perPage + 1; $params[] = $offset;
        $rows = $this->db->all(
            "SELECT {$this->selectList()}, MIN(f.rank) AS rank FROM (
                SELECT rowid AS story_id, bm25(stories_fts) AS rank FROM stories_fts WHERE stories_fts MATCH ?
                UNION
                SELECT story_id, bm25(chapters_fts) AS rank FROM chapters_fts WHERE chapters_fts MATCH ?
            ) f JOIN stories s ON s.id = f.story_id {$this->fromGates()}{$filter}
            GROUP BY s.id, s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at, u.penname, u.profile_slug, r.label
            ORDER BY rank ASC LIMIT ? OFFSET ?",
            $params
        );
        $hasMore = count($rows) > $perPage;
        return ['rows' => array_slice($rows, 0, $perPage), 'hasMore' => $hasMore, 'mode' => 'fts'];
        // Finding 3, probed: bm25() CANNOT sit inside MIN(...) GROUP BY in the
        // chapters arm ("unable to use function bm25 in the requested context");
        // the plain UNION arm is correct because the outer GROUP BY + MIN(f.rank)
        // already aggregates both arms per story.
    }

    public function searchLike(string $q, array $filters, int $perPage, int $offset, int $me): array
    {
        $like = self::likePattern($q);
        // Bind order (finding 7, probed): fromGates' CAST ? first, then the
        // filter ?s, then the three LIKE ?s, then LIMIT/OFFSET - strictly the
        // SQL text order. The draft's [$me, $like x3] + filters misbinds
        // whenever any filter is set and fails SILENTLY (empty results).
        $params = [$me];
        $filter = $this->filterSql($filters, $params);
        $params[] = $like; $params[] = $like; $params[] = $like;
        $params[] = $perPage + 1; $params[] = $offset;
        $rows = $this->db->all(
            "SELECT {$this->selectList()} FROM stories s {$this->fromGates()}{$filter}
              AND (s.title LIKE ? ESCAPE '\\' OR s.summary LIKE ? ESCAPE '\\' OR EXISTS (
                    SELECT 1 FROM chapters c WHERE c.story_id = s.id AND c.content LIKE ? ESCAPE '\\'))
             ORDER BY s.updated_at DESC, s.id DESC LIMIT ? OFFSET ?",
            $params
        );
        $hasMore = count($rows) > $perPage;
        return ['rows' => array_slice($rows, 0, $perPage), 'hasMore' => $hasMore, 'mode' => 'like'];
    }

    /** FTS5 MATCH input must never see raw user syntax: split on whitespace,
     *  strip quotes, wrap each token in double quotes (implicit AND), cap at
     *  MAX_TOKENS. Empty result means "do not run a query at all". */
    public static function matchExpression(string $q): string
    {
        $tokens = preg_split('/\s+/', trim($q)) ?: [];
        $tokens = array_values(array_filter(array_map(
            fn (string $t): string => trim(str_replace('"', ' ', $t)),
            $tokens
        ), fn (string $t): bool => $t !== ''));
        $tokens = array_slice($tokens, 0, self::MAX_TOKENS);
        return implode(' ', array_map(fn (string $t): string => '"' . $t . '"', $tokens));
    }

    /** LIKE pattern with %, _, backslash escaped (the fallback path). */
    public static function likePattern(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($q)) . '%';
    }
}
