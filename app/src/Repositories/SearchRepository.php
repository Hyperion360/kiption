<?php // app/src/Repositories/SearchRepository.php
namespace App\Repositories;
use Kip\Database;

final class SearchRepository
{
    private const MAX_TOKENS = 8;
    private ?bool $fts = null;
    private ?bool $spelling = null;

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
    public function search(string $q, array $filters, int $perPage, int $offset, int $me, int $viewer = 0, string $sort = 'relevance'): array
    {
        $r = $this->searchWithTaxonomies($q, $filters, $perPage, $offset, $me, $viewer, $sort);
        return ['rows' => $r['rows'], 'hasMore' => $r['hasMore'], 'mode' => $r['mode']];
    }

    /** The page's taxonomy rows share one statement with the gated result page.
     *  The result subquery owns ranking and pagination; partition preserves its
     *  encounter order and sorts taxonomy rows independently by position.
     *  Missing optional spelling objects retry exact FTS once. Missing FTS
     *  objects fall back to LIKE; other database errors remain visible.
     *  @return array{rows:array,hasMore:bool,mode:string,capped:bool,ratings:array,categories:array} */
    public function searchWithTaxonomies(string $q, array $filters, int $perPage, int $offset, int $me, int $viewer = 0, string $sort = 'relevance'): array
    {
        $tokens = self::sanitizedTokens($q);
        $capped = count($tokens) > self::MAX_TOKENS;
        if ($tokens !== []) {
            $rows = $this->ftsRows($q, $filters, $perPage, $offset, $me, $viewer, $sort);
            if ($rows !== null) return $this->partition($rows, $perPage, 'fts', $capped, $filters);
        }
        [$filter] = DiscoveryFilter::sql($filters);
        $mode = $tokens !== [] ? 'like' : ($filter !== '' ? 'filter' : 'none');
        [$sql, $binds] = $this->plainStatement($tokens !== [] ? $q : '', $filters, $perPage, $offset,
            $me, $viewer, $mode !== 'none');
        return $this->partition($this->db->all($sql, $binds), $perPage, $mode, $capped, $filters);
    }

    private function partition(array $rows, int $perPage, string $mode, bool $capped, array $filters, bool $ranked = false): array
    {
        $tags = $filters['tag_filter'] ?? TagFilter::fromQuery($filters);
        $result = DiscoveryFold::partition($rows, $perPage, array_merge($tags->include, $tags->exclude));
        if (!$ranked) {
            foreach ($result['rows'] as &$row) unset($row['rank']);
            unset($row);
        }
        return $result + ['mode' => $mode, 'capped' => $capped];
    }

    private function selectList(): string
    {
        return 's.id,s.slug,s.title,s.summary,s.completed,s.word_count,s.updated_at,s.created_at,
            s.author_id,s.rating_id,s.language,u.penname,u.profile_slug,r.label AS rating_label';
    }

    /** The text and visibility binds precede the shared facet binds. */
    private function plainStatement(string $q, array $filters, int $perPage, int $offset,
        int $me, int $viewer, bool $listStories = true): array
    {
        $source = "SELECT {$this->selectList()},0 tier,0 distance,0 field_rank,0.0 rank
            FROM stories s {$this->fromGates()}";
        $binds = [$me];
        if ($viewer > 0) { $source .= MuteRepository::clause('s'); $binds[] = $viewer; }
        $source .= ' AND s.access_hash IS NULL';
        if ($q !== '') {
            $source .= ' AND ' . $this->likeGroup();
            array_push($binds, ...array_fill(0, 4, self::likePattern($q)));
        }
        return DiscoveryFold::statement($source, $binds, $filters, 'updated', $perPage, $offset, false, $listStories);
    }

    private function fromGates(): string
    {
        return 'JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id'
            . ' WHERE s.validated = 1 AND s.deleted_at IS NULL AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)';
    }

    /** Chapter-arm rows (f.chapter_id set) surface only VALIDATED chapters:
     *  every reader surface gates ch.validated = 1 unconditionally (the TOC
     *  blob, /story/read), so search must not make pending chapter text or
     *  titles discoverable ahead of moderation. The post-password rider
     *  (access_hash IS NULL) keeps a locked chapter from making its story
     *  match: the FTS arm is search's third prose channel, beside the two
     *  LIKE folds. Story-arm rows carry a NULL chapter_id and pass untouched.
     *  Param-free: the documented bind arrays are unchanged. Rides INSIDE the
     *  fts_matches CTE as its WHERE, before the per-story fold. */
    private function chapterGate(): string
    {
        return ' WHERE (f.chapter_id IS NULL OR EXISTS (SELECT 1 FROM chapters c WHERE c.id = f.chapter_id AND c.validated = 1 AND c.access_hash IS NULL))';
    }

    public function searchFts(string $q, array $filters, int $perPage, int $offset, int $me, int $viewer = 0, string $sort = 'relevance'): array
    {
        $rows = $this->ftsRows($q, $filters, $perPage, $offset, $me, $viewer, $sort);
        if ($rows === null) return $this->searchLike($q, $filters, $perPage, $offset, $me, $viewer);
        $result = $this->partition($rows, $perPage, 'fts', false, $filters, true);
        return ['rows' => $result['rows'], 'hasMore' => $result['hasMore'], 'mode' => 'fts'];
    }

    /** The spelling JSON binds precede the two MATCH binds, then visibility,
     *  mute, filters, and pagination. A failed optional prepare is its probe. */
    private function ftsRows(string $q, array $filters, int $perPage, int $offset, int $me, int $viewer,
        string $sort): ?array
    {
        if ($this->fts === false) return null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$spell, $params] = $this->spelling !== false
                ? \App\Search\SpellingSql::ctes(\App\Search\Spelling::probes($q), true) : ['', []];
            $match = self::matchExpression($q);
            array_push($params, $match, $match, $me);
            $mute = $viewer > 0 ? MuteRepository::clause('s') : '';
            if ($viewer > 0) $params[] = $viewer;
            [$sql, $params] = DiscoveryFold::statement($this->ftsSource($mute, $spell), $params,
                $filters, $sort === 'updated' ? 'updated' : 'relevance', $perPage, $offset, true);
            try {
                return $this->db->all($sql, $params);
            } catch (\PDOException $e) {
                if ($spell !== '' && $this->spelling !== false && self::missingSpelling($e)) {
                    $this->spelling = false;
                    continue;
                }
                if (self::missingFts($e)) {
                    $this->fts = false;
                    return null;
                }
                throw $e;
            }
        }
        return null;
    }

    private static function missingSpelling(\PDOException $e): bool
    {
        return preg_match('/no such table: (?:main\.)?(search_words|search_deletes|search_word_posts)\b/', $e->getMessage()) === 1;
    }

    private static function missingFts(\PDOException $e): bool
    {
        return preg_match('/no such table: (?:main\.)?(stories_fts|chapters_fts)\b|no such module: fts5\b/', $e->getMessage()) === 1;
    }

    /** Legacy trigram expression helper, retained for callers of the older
     *  optional index. Live search uses the bounded spelling vocabulary. */
    public static function trigramExpression(string $q): string
    {
        $parts = [];
        $seen = [];
        $budget = 30;
        foreach (array_slice(self::sanitizedTokens($q), 0, self::MAX_TOKENS) as $t) {
            $len = mb_strlen($t);
            if ($len < 4) { continue; }
            for ($i = 0; $i < $len && $budget > 0; $i++) {
                $variant = mb_substr($t, 0, $i) . mb_substr($t, $i + 1);
                if (mb_strlen($variant) < 3 || isset($seen[$variant])) { continue; }
                $seen[$variant] = true;
                $parts[] = '"' . $variant . '"';
                $budget--;
            }
            if ($budget <= 0) { break; }
        }
        return implode(' OR ', $parts);
    }

    /** Rank exact matches before spelling matches, with a stable ID tie-break.
     *  The per-story FTS aggregate stays outside the stories primary-key seek;
     *  chapter validation and access checks remain inside that aggregate. */
    private function ftsSource(string $mute, string $spell = ''): string
    {
        $prefix = $spell === '' ? '' : $spell . ', ';
        $spelling = $spell === '' ? ''
            : ' UNION ALL SELECT p.story_id, 0.0, 1, p.distance, p.field_rank FROM spell_matches p';
        return "WITH {$prefix}fts_matches AS (
                SELECT f.story_id AS story_id, MIN(f.rank) AS rank FROM (
                    SELECT NULL AS chapter_id, rowid AS story_id, bm25(stories_fts, 10.0, 1.0, 5.0) AS rank FROM stories_fts WHERE stories_fts MATCH ?
                    UNION
                    SELECT rowid AS chapter_id, story_id, bm25(chapters_fts) AS rank FROM chapters_fts WHERE chapters_fts MATCH ?
                ) f{$this->chapterGate()}
                GROUP BY f.story_id
            )
            SELECT {$this->selectList()}, m.rank AS rank, m.tier, m.distance, m.field_rank FROM (
                SELECT m.story_id, m.rank, 0 AS tier, 0 AS distance, 0 AS field_rank FROM fts_matches m{$spelling}
            ) m
            CROSS JOIN stories s
            JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
            WHERE s.id = m.story_id AND s.validated = 1 AND s.deleted_at IS NULL AND s.access_hash IS NULL AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0){$mute}";
    }

    public function searchLike(string $q, array $filters, int $perPage, int $offset, int $me, int $viewer = 0): array
    {
        [$sql, $binds] = $this->plainStatement($q, $filters, $perPage, $offset, $me, $viewer);
        $result = $this->partition($this->db->all($sql, $binds), $perPage, 'like', false, $filters);
        return ['rows' => $result['rows'], 'hasMore' => $result['hasMore'], 'mode' => 'like'];
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
     *  MAX_TOKENS. The LAST token carries a prefix star when it is at least
     *  two characters, so "ang" finds "angst" and "rabbit ho" finds "hole";
     *  a one-character word matches exactly. Empty result means "do not run
     *  a query at all". */
    public static function matchExpression(string $q): string
    {
        $tokens = array_slice(self::sanitizedTokens($q), 0, self::MAX_TOKENS);
        $last = count($tokens) - 1;
        $parts = [];
        foreach ($tokens as $i => $t) {
            $parts[] = '"' . $t . '"' . ($i === $last && mb_strlen($t, 'UTF-8') >= 2 ? '*' : '');
        }
        return implode(' ', $parts);
    }

    /** LIKE pattern with %, _, backslash escaped (the fallback path). A curly
     *  apostrophe folds to the straight one here, and likeGroup() folds the
     *  compared columns the same way, so "don't" finds "don’t" and back. */
    public static function likePattern(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], str_replace('’', "'", trim($q))) . '%';
    }

    /** The LIKE match group shared by the fallback seam and the page fold:
     *  title, summary, validated chapter content, and tag names. Curly
     *  apostrophes fold to the straight one on both sides (the pattern via
     *  likePattern, the column via REPLACE), so "don't" finds "don’t" either
     *  way. Emits exactly four LIKE ?s, in this order. */
    private function likeGroup(): string
    {
        return "(REPLACE(s.title, '’', '''') LIKE ? ESCAPE '\\'"
            . " OR REPLACE(s.summary, '’', '''') LIKE ? ESCAPE '\\'"
            . " OR EXISTS (SELECT 1 FROM chapters c WHERE c.story_id = s.id AND c.validated = 1 AND c.access_hash IS NULL"
            . " AND REPLACE(c.content, '’', '''') LIKE ? ESCAPE '\\')"
            . " OR EXISTS (SELECT 1 FROM story_tags lx JOIN tags lt ON lt.id = lx.tag_id WHERE lx.story_id = s.id"
            . " AND REPLACE(lt.name, '’', '''') LIKE ? ESCAPE '\\'))";
    }
}
