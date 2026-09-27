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

    /** @param array{category?:string,rating_id?:int,completed?:bool,language?:string} $filters
     *  Thin wrapper over the page fold: /search uses searchWithTaxonomies()
     *  directly so the filter taxonomies ride the same statement; the Task 2
     *  seams and this dispatcher stay public exactly as shipped, each with
     *  the optional mute viewer appended as the last parameter. */
    public function search(string $q, array $filters, int $perPage, int $offset, int $me, int $viewer = 0): array
    {
        $r = $this->searchWithTaxonomies($q, $filters, $perPage, $offset, $me, $viewer);
        return ['rows' => $r['rows'], 'hasMore' => $r['hasMore'], 'mode' => $r['mode']];
    }

    /** ONE statement for the whole /search page: the filter taxonomies (k='r'
     *  ratings, k='c' categories) ride the SAME compound as the paged, gated
     *  results (k='s', the queue-fold shape), so the surface stays budget-1
     *  including the filter UI. Finding 4: no ORDER BY inside compound
     *  branches; section order rides output columns k ('c' < 'r' < 's', form
     *  order) and p, the RAW INTEGER section position - taxonomy rows carry
     *  their real position, results carry ROW_NUMBER mirroring the
     *  subselect's own ordering. Probed on this runtime: ORDER BY
     *  k, CAST(p AS INTEGER) does NOT prepare in a compound ("2nd ORDER BY
     *  term does not match any column in the result set"), so p is emitted
     *  raw and ordered bare (the /top finding-5 lesson). Empty token set:
     *  taxonomy-only compound, mode 'none', no results query. FTS failures
     *  (missing virtual tables, PDOException) memoize the negative and fall
     *  back to the LIKE fold (finding 6: never probe ftsAvailable() here).
     *  Binds, strictly by SQL text order (COUNT the ?s against the array):
     *  taxonomy branches carry none; the results subselect keeps the Task 2
     *  bind orders verbatim with the Task 2 mute viewer slotted immediately
     *  after the restricted-gate $me (the clause rides right after
     *  fromGates' CAST in text) - FTS [$match, $match, $me, $viewer,
     *  ...filters..., perPage+1, $offset] (the MATCH ?s live in the FROM
     *  derived table, binding before fromGates' WHERE CAST), LIKE [$me,
     *  $viewer, ...filters..., $like x3, perPage+1, $offset]. Anonymous
     *  callers ($viewer = 0) keep the exact pre-mute statements.
     *  @param array{category?:string,rating_id?:int,completed?:bool,language?:string} $filters
     *  @return array{rows:array,hasMore:bool,mode:string,capped:bool,ratings:array,categories:array} */
    public function searchWithTaxonomies(string $q, array $filters, int $perPage, int $offset, int $me, int $viewer = 0): array
    {
        $tokens = self::sanitizedTokens($q);
        $capped = count($tokens) > self::MAX_TOKENS;
        $taxonomy = "SELECT 'r' k, r.position p, CAST(r.id AS TEXT) a, r.label b, NULL c, NULL d, NULL e, NULL f, NULL g, NULL h FROM ratings r
                     UNION ALL
                     SELECT 'c', c.position, c.slug, c.name, NULL, NULL, NULL, NULL, NULL, NULL FROM categories c";
        if ($tokens === []) {
            return $this->partition($this->db->all($taxonomy . ' ORDER BY k, p'), $perPage, 'none', $capped);
        }
        $mute = $viewer > 0 ? MuteRepository::clause('s') : '';
        if ($this->fts !== false) {
            try {
                $match = self::matchExpression($q);
                $params = [$match, $match, $me];
                if ($viewer > 0) $params[] = $viewer;
                $filter = $this->filterSql($filters, $params);
                $params[] = $perPage + 1; $params[] = $offset;
                $rows = $this->db->all(
                    $taxonomy . " UNION ALL
                    SELECT 's', ROW_NUMBER() OVER (ORDER BY st.rank ASC), st.slug, st.title, st.summary,
                           st.completed, st.word_count, st.updated_at, st.penname, st.rating_label
                    FROM (SELECT {$this->selectList()}, MIN(f.rank) AS rank FROM (
                            SELECT NULL AS chapter_id, rowid AS story_id, bm25(stories_fts) AS rank FROM stories_fts WHERE stories_fts MATCH ?
                            UNION
                            SELECT rowid AS chapter_id, story_id, bm25(chapters_fts) AS rank FROM chapters_fts WHERE chapters_fts MATCH ?
                          ) f JOIN stories s ON s.id = f.story_id {$this->fromGates()}{$mute}{$this->chapterGate()}{$filter}
                          GROUP BY s.id, s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at, u.penname, u.profile_slug, r.label
                          ORDER BY rank ASC LIMIT ? OFFSET ?) st
                    ORDER BY k, p",
                    $params
                );
                return $this->partition($rows, $perPage, 'fts', $capped);
            } catch (\PDOException) { $this->fts = false; }
        }
        $like = self::likePattern($q);
        $params = [$me];
        if ($viewer > 0) $params[] = $viewer;
        $filter = $this->filterSql($filters, $params);
        $params[] = $like; $params[] = $like; $params[] = $like;
        $params[] = $perPage + 1; $params[] = $offset;
        $rows = $this->db->all(
            $taxonomy . " UNION ALL
            SELECT 's', ROW_NUMBER() OVER (ORDER BY st.updated_at DESC, st.id DESC), st.slug, st.title, st.summary,
                   st.completed, st.word_count, st.updated_at, st.penname, st.rating_label
            FROM (SELECT {$this->selectList()}, s.id FROM stories s {$this->fromGates()}{$mute}{$filter}
                  AND (s.title LIKE ? ESCAPE '\\' OR s.summary LIKE ? ESCAPE '\\' OR EXISTS (
                    SELECT 1 FROM chapters c WHERE c.story_id = s.id AND c.validated = 1 AND c.content LIKE ? ESCAPE '\\'))
                 ORDER BY s.updated_at DESC, s.id DESC LIMIT ? OFFSET ?) st
            ORDER BY k, p",
            $params
        );
        return $this->partition($rows, $perPage, 'like', $capped);
    }

    /** Split the fold into its sections; result rows normalize to the browse
     *  listing shape so the views share the row markup. The perPage+1 probe
     *  row rides the 's' rows and is sliced off here (hasMore). */
    private function partition(array $rows, int $perPage, string $mode, bool $capped): array
    {
        $ratings = [];
        $categories = [];
        $stories = [];
        foreach ($rows as $r) {
            if ($r['k'] === 'r') { $ratings[] = ['id' => (int) $r['a'], 'label' => (string) $r['b']]; continue; }
            if ($r['k'] === 'c') { $categories[] = ['slug' => (string) $r['a'], 'name' => (string) $r['b']]; continue; }
            $stories[] = [
                'slug' => (string) $r['a'], 'title' => (string) $r['b'], 'summary' => (string) $r['c'],
                'completed' => (int) $r['d'], 'word_count' => (int) $r['e'], 'updated_at' => (string) $r['f'],
                'penname' => (string) $r['g'], 'rating_label' => (string) $r['h'],
            ];
        }
        return [
            'rows' => array_slice($stories, 0, $perPage),
            'hasMore' => count($stories) > $perPage,
            'mode' => $mode,
            'capped' => $capped,
            'ratings' => $ratings,
            'categories' => $categories,
        ];
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

    /** Chapter-arm rows (f.chapter_id set) surface only VALIDATED chapters:
     *  every reader surface gates ch.validated = 1 unconditionally (the TOC
     *  blob, /story/read), so search must not make pending chapter text or
     *  titles discoverable ahead of moderation. Story-arm rows carry a NULL
     *  chapter_id and pass untouched. Param-free: the documented bind arrays
     *  are unchanged. */
    private function chapterGate(): string
    {
        return ' AND (f.chapter_id IS NULL OR EXISTS (SELECT 1 FROM chapters c WHERE c.id = f.chapter_id AND c.validated = 1))';
    }

    public function searchFts(string $q, array $filters, int $perPage, int $offset, int $me, int $viewer = 0): array
    {
        $match = self::matchExpression($q);
        $mute = $viewer > 0 ? MuteRepository::clause('s') : '';
        // Bind order (finding 2, probed): the two MATCH ?s live in the FROM
        // derived table, which binds BEFORE fromGates' CAST in the WHERE; the
        // Task 2 mute viewer sits immediately after that CAST (the clause's
        // text position); then filter params in filterSql's fixed order; then
        // LIMIT/OFFSET.
        $params = [$match, $match, $me];
        if ($viewer > 0) $params[] = $viewer;
        $filter = $this->filterSql($filters, $params);
        $params[] = $perPage + 1; $params[] = $offset;
        $rows = $this->db->all(
            "SELECT {$this->selectList()}, MIN(f.rank) AS rank FROM (
                SELECT NULL AS chapter_id, rowid AS story_id, bm25(stories_fts) AS rank FROM stories_fts WHERE stories_fts MATCH ?
                UNION
                SELECT rowid AS chapter_id, story_id, bm25(chapters_fts) AS rank FROM chapters_fts WHERE chapters_fts MATCH ?
            ) f JOIN stories s ON s.id = f.story_id {$this->fromGates()}{$mute}{$this->chapterGate()}{$filter}
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

    public function searchLike(string $q, array $filters, int $perPage, int $offset, int $me, int $viewer = 0): array
    {
        $like = self::likePattern($q);
        $mute = $viewer > 0 ? MuteRepository::clause('s') : '';
        // Bind order (finding 7, probed): fromGates' CAST ? first, then the
        // Task 2 mute viewer (the clause rides immediately after the CAST in
        // text), then the filter ?s, then the three LIKE ?s, then LIMIT/OFFSET
        // - strictly the SQL text order. The draft's [$me, $like x3] + filters
        // misbinds whenever any filter is set and fails SILENTLY (empty
        // results); the viewer bind must not reopen that trap.
        $params = [$me];
        if ($viewer > 0) $params[] = $viewer;
        $filter = $this->filterSql($filters, $params);
        $params[] = $like; $params[] = $like; $params[] = $like;
        $params[] = $perPage + 1; $params[] = $offset;
        $rows = $this->db->all(
            "SELECT {$this->selectList()} FROM stories s {$this->fromGates()}{$mute}{$filter}
              AND (s.title LIKE ? ESCAPE '\\' OR s.summary LIKE ? ESCAPE '\\' OR EXISTS (
                    SELECT 1 FROM chapters c WHERE c.story_id = s.id AND c.validated = 1 AND c.content LIKE ? ESCAPE '\\'))
             ORDER BY s.updated_at DESC, s.id DESC LIMIT ? OFFSET ?",
            $params
        );
        $hasMore = count($rows) > $perPage;
        return ['rows' => array_slice($rows, 0, $perPage), 'hasMore' => $hasMore, 'mode' => 'like'];
    }

    /** Quote-stripped, whitespace-split tokens of the query, empties dropped:
     *  the pre-cap token view matchExpression() slices (and the page hint
     *  reads for the "beyond the first 8 are ignored" note). */
    private static function sanitizedTokens(string $q): array
    {
        $tokens = preg_split('/\s+/', trim($q)) ?: [];
        return array_values(array_filter(array_map(
            fn (string $t): string => trim(str_replace('"', ' ', $t)),
            $tokens
        ), fn (string $t): bool => $t !== ''));
    }

    /** FTS5 MATCH input must never see raw user syntax: split on whitespace,
     *  strip quotes, wrap each token in double quotes (implicit AND), cap at
     *  MAX_TOKENS. Empty result means "do not run a query at all". */
    public static function matchExpression(string $q): string
    {
        $tokens = array_slice(self::sanitizedTokens($q), 0, self::MAX_TOKENS);
        return implode(' ', array_map(fn (string $t): string => '"' . $t . '"', $tokens));
    }

    /** LIKE pattern with %, _, backslash escaped (the fallback path). */
    public static function likePattern(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($q)) . '%';
    }
}
