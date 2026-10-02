<?php // app/src/Repositories/StoryRepository.php
namespace App\Repositories;
use Kip\Database;

final class StoryRepository
{
    public function __construct(private Database $db) {}

    /** ONE query: story + author + rating + categories + chapter TOC blob,
     *  plus the series membership and coauthor byline blobs (param-free scalar
     *  subqueries, so the bind order below is unchanged). The tags blob joins
     *  the fold the same param-free way: story_tags resolved through each
     *  tag's canonical (one COALESCE level; merges never chain) with its type
     *  name, so a story tagged with a retired synonym renders the canonical.
     *  The reviews fold is a WINDOW split: the 50 newest ROOT reviews (walked by
     *  idx_reviews_roots), a 200-reply blob of the replies to those roots (bounded
     *  by construction), and a root-only COUNT (replies never inflate the headline;
     *  a reply flood can no longer push roots out of the window). All three are
     *  param-free scalar subqueries, so the bind array below is unchanged too.
     *  The blobs are JSON: titles are free text (admin CRUD writes chapters
     *  today), so no hand-rolled delimiter scheme is safe to parse. json_group_array
     *  yields [] when the story has no validated chapters. Decode and ksort in
     *  PHP so ordering never depends on aggregation internals.
     *  Restricted stories 404 for guests at the SQL level: the gate CASTs $me
     *  because PDO binds int 0 as TEXT and a bare ? != 0 would compare across
     *  storage classes TRUE, failing the gate OPEN for guests.
     *  @return array<string,mixed>|null */
    public function findStoryBySlug(string $slug, int $me = 0): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return null;
        // C4 progress fold, member path only: the reading_history LEFT JOIN and
        // its two derived columns exist ONLY when $me !== 0, so the guest
        // statement stays byte-identical to the shape the static-cache Builder
        // renders (guest cached bytes never vary by reader). The member id is
        // inlined as a cast int, never a bind: the join then seeks reading_history's
        // (user_id, story_id) PK directly. last_position keeps its existing
        // furthest-read MAX() semantics (the C3 removal note); read_pct and
        // minutes_left derive in StoryController::progressOf() from the
        // validated chapters blob the page already decoded (review: the SQL
        // pair below ran the identical chapter-range SUM twice per render),
        // clamped against stale word_count there. marked_at rides the join
        // too (the old scalar subquery read the same PK row a second time).
        $progressJoin = $me !== 0 ? ' LEFT JOIN reading_history rh2 ON rh2.story_id = s.id AND rh2.user_id = ' . $me : '';
        $progressCols = $me !== 0 ? ',
                    rh2.last_position,
                    rh2.marked_at AS marked_at_me' : '';
        // C5 bookmarks fold, member path only too: notes are free text, so the
        // blob is JSON (the TOC discipline; no delimiter survives a note).
        // Ordering rides the derived table (created_at, then chapter_id to
        // break same-timestamp ties deterministically); the chapters LEFT JOIN
        // resolves the position for the sheet's links.
        $bookmarksCols = $me !== 0 ? ',
                    (SELECT json_group_array(json_object(\'position\', cb.position, \'note\', b.note, \'at\', b.created_at))
                     FROM (SELECT b.* FROM bookmarks b WHERE b.user_id = ' . $me . ' AND b.story_id = s.id
                           ORDER BY b.created_at, b.chapter_id) b
                     LEFT JOIN chapters cb ON cb.id = b.chapter_id) AS bookmarks_blob' : '';
        return $this->db->one(
            'SELECT s.*, u.penname, u.profile_slug, r.label AS rating_label, r.is_adult, r.warning_text,
                    (SELECT COUNT(*) FROM story_kudos k WHERE k.story_id = s.id) AS kudos_count,
                    (SELECT COUNT(*) FROM favorites f WHERE f.story_id = s.id) AS favorite_count,
                    (SELECT COUNT(*) FROM story_kudos k2 WHERE k2.story_id = s.id AND k2.user_id = ?) AS kudos_by_me,
                    (SELECT COUNT(*) FROM favorites f2 WHERE f2.story_id = s.id AND f2.user_id = ?) AS favorite_by_me,
                    (SELECT COUNT(*) FROM follows fo WHERE fo.author_id = s.author_id AND fo.follower_id = ?) AS following_author,
                    (SELECT GROUP_CONCAT(c.name, ", ") FROM story_categories sc
                     JOIN categories c ON c.id = sc.category_id
                     WHERE sc.story_id = s.id) AS category_names,
                    (SELECT json_group_array(json_object(\'position\', ch.position, \'title\', ch.title, \'word_count\', ch.word_count))
                     FROM chapters ch WHERE ch.story_id = s.id AND ch.validated = 1
                     ORDER BY ch.position) AS chapters_blob,
                    (SELECT json_group_array(json_object(\'id\', r.id, \'user_id\', r.user_id, \'penname\',
                            (SELECT penname FROM users ru WHERE ru.id = r.user_id), \'guest_name\', r.guest_name,
                            \'body\', r.body, \'rating\', r.rating, \'created_at\', r.created_at))
                     FROM (SELECT r.* FROM reviews r WHERE r.story_id = s.id AND r.parent_id IS NULL
                           ORDER BY r.created_at DESC, r.id DESC LIMIT 50) r) AS reviews_blob,
                    (SELECT json_group_array(json_object(\'root_id\', r2.parent_id, \'id\', r2.id, \'user_id\', r2.user_id, \'penname\',
                            (SELECT penname FROM users ru2 WHERE ru2.id = r2.user_id), \'guest_name\', r2.guest_name,
                            \'body\', r2.body, \'created_at\', r2.created_at))
                     FROM (SELECT r2.* FROM reviews r2 WHERE r2.parent_id IN (
                               SELECT r.id FROM reviews r WHERE r.story_id = s.id AND r.parent_id IS NULL
                               ORDER BY r.created_at DESC, r.id DESC LIMIT 50)
                           ORDER BY r2.created_at DESC, r2.id DESC LIMIT 200) r2) AS replies_blob,
                    (SELECT COUNT(*) FROM reviews r3 WHERE r3.story_id = s.id AND r3.parent_id IS NULL) AS review_count,
                    (SELECT su.support_url FROM users su WHERE su.id = s.author_id) AS support_url,
                    (SELECT json_group_array(json_object(\'s\', ser.slug, \'t\', ser.title))
                     FROM series_items si JOIN series ser ON ser.id = si.series_id
                     WHERE si.story_id = s.id AND si.confirmed = 1) AS series_blob,
                    (SELECT json_group_array(json_object(\'i\', cu.id, \'n\', cu.penname, \'p\', cu.profile_slug))
                     FROM coauthors ca JOIN users cu ON cu.id = ca.user_id WHERE ca.story_id = s.id) AS coauthors_blob,
                    (SELECT json_group_array(json_object(\'type\', tt.name, \'name\', COALESCE(cu2.name, t.name)))
                     FROM story_tags st
                     JOIN tags t ON t.id = st.tag_id
                     LEFT JOIN tags cu2 ON cu2.id = t.canonical_id
                     JOIN tag_types tt ON tt.id = t.tag_type_id
                     WHERE st.story_id = s.id) AS tags_blob' . $progressCols . $bookmarksCols . '
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id' . $progressJoin . '
             WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)',
            [$me, $me, $me, $slug, $me]
        );
    }

    /** ONE query for the whole-work reading view: findStoryBySlug's fold
     *  verbatim except that the chapter TOC blob is swapped for a
     *  content-carrying blob (position, title, prose, word_count per validated
     *  chapter; the derived table alias carries the ORDER BY, the Task 1
     *  lesson). The WHERE and the bind list are therefore findStoryBySlug's
     *  unchanged: validated + not-deleted + the CAST'd restricted gate, with
     *  the four viewer subqueries ahead of the slug. Memory stance: a very
     *  long work arrives as one string by design (the read IS the whole work)
     *  and the view is never static-cached (size), so the cost is bounded by
     *  the story itself.
     *  @return array<string,mixed>|null */
    public function wholeWork(string $slug, int $me = 0): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return null;
        return $this->db->one(
            'SELECT s.*, u.penname, u.profile_slug, r.label AS rating_label, r.is_adult, r.warning_text,
                    (SELECT COUNT(*) FROM story_kudos k WHERE k.story_id = s.id) AS kudos_count,
                    (SELECT COUNT(*) FROM favorites f WHERE f.story_id = s.id) AS favorite_count,
                    (SELECT COUNT(*) FROM story_kudos k2 WHERE k2.story_id = s.id AND k2.user_id = ?) AS kudos_by_me,
                    (SELECT COUNT(*) FROM favorites f2 WHERE f2.story_id = s.id AND f2.user_id = ?) AS favorite_by_me,
                    (SELECT COUNT(*) FROM follows fo WHERE fo.author_id = s.author_id AND fo.follower_id = ?) AS following_author,
                    (SELECT GROUP_CONCAT(c.name, ", ") FROM story_categories sc
                     JOIN categories c ON c.id = sc.category_id
                     WHERE sc.story_id = s.id) AS category_names,
                    (SELECT json_group_array(json_object(\'position\', c.position, \'title\', c.title, \'content\', c.content, \'word_count\', c.word_count))
                     FROM (SELECT * FROM chapters WHERE story_id = s.id AND validated = 1 ORDER BY position) c) AS chapters_blob,
                    (SELECT json_group_array(json_object(\'id\', r.id, \'user_id\', r.user_id, \'penname\',
                            (SELECT penname FROM users ru WHERE ru.id = r.user_id), \'guest_name\', r.guest_name,
                            \'body\', r.body, \'rating\', r.rating, \'created_at\', r.created_at))
                     FROM (SELECT r.* FROM reviews r WHERE r.story_id = s.id AND r.parent_id IS NULL
                           ORDER BY r.created_at DESC, r.id DESC LIMIT 50) r) AS reviews_blob,
                    (SELECT json_group_array(json_object(\'root_id\', r2.parent_id, \'id\', r2.id, \'user_id\', r2.user_id, \'penname\',
                            (SELECT penname FROM users ru2 WHERE ru2.id = r2.user_id), \'guest_name\', r2.guest_name,
                            \'body\', r2.body, \'created_at\', r2.created_at))
                     FROM (SELECT r2.* FROM reviews r2 WHERE r2.parent_id IN (
                               SELECT r.id FROM reviews r WHERE r.story_id = s.id AND r.parent_id IS NULL
                               ORDER BY r.created_at DESC, r.id DESC LIMIT 50)
                           ORDER BY r2.created_at DESC, r2.id DESC LIMIT 200) r2) AS replies_blob,
                    (SELECT COUNT(*) FROM reviews r3 WHERE r3.story_id = s.id AND r3.parent_id IS NULL) AS review_count,
                    (SELECT su.support_url FROM users su WHERE su.id = s.author_id) AS support_url,
                    (SELECT json_group_array(json_object(\'s\', ser.slug, \'t\', ser.title))
                     FROM series_items si JOIN series ser ON ser.id = si.series_id
                     WHERE si.story_id = s.id AND si.confirmed = 1) AS series_blob,
                    (SELECT json_group_array(json_object(\'i\', cu.id, \'n\', cu.penname, \'p\', cu.profile_slug))
                     FROM coauthors ca JOIN users cu ON cu.id = ca.user_id WHERE ca.story_id = s.id) AS coauthors_blob
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)',
            [$me, $me, $me, $slug, $me]
        );
    }

    /** ONE query for the reading page: story meta plus the target chapter
     *  pivoted via conditional aggregation, plus the validated-position list
     *  for prev/next (positions can be non-contiguous when a middle chapter
     *  is unvalidated). ch_title NULL means the chapter does not exist.
     *  Same restricted gate as findStoryBySlug (CAST is load-bearing). The
     *  syndication pair rides along so chapter reads apply the SAME Head
     *  branch as the story view (the deindex must cover both surfaces).
     *  ch_id pivots the chapter's id for the read beacon img (finding 5:
     *  the beacon wants chapters.id, never the position).
     *  @return array<string,mixed>|null */
    public function findStoryWithChapter(string $slug, int $position, int $me = 0): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return null;
        // C4: the titled TOC blob rides every chapter read (findStoryBySlug's
        // expression verbatim, alias shifted to ch3 because this statement
        // already aliases chapters as ch and ch2), so the read page can render
        // a titled contents sheet AND derive prev/next positions from its keys
        // (review: the old positions_blob GROUP_CONCAT scanned the same
        // chapter set a second time). The member fold also carries the stored
        // theme row (1:1 PK) so the Text sheet's radio can default from it —
        // a typography-only save must never rewrite the stored theme from a
        // cookie-absent default (adversarial finding F2). The review count
        // rides the same statement (alias r4, distinct from ratings r): the
        // chapter-end Review link reads it instead of spending a query.
        $progressJoin = $me !== 0 ? ' LEFT JOIN reading_history rh2 ON rh2.story_id = s.id AND rh2.user_id = ' . $me
            . ' LEFT JOIN user_prefs up ON up.user_id = ' . $me : '';
        $progressCols = $me !== 0 ? ',
                    rh2.last_position,
                    up.theme AS prefs_theme' : '';
        // The C5 bookmarks fold, same member-only shape as findStoryBySlug.
        $bookmarksCols = $me !== 0 ? ',
                    (SELECT json_group_array(json_object(\'position\', cb.position, \'note\', b.note, \'at\', b.created_at))
                     FROM (SELECT b.* FROM bookmarks b WHERE b.user_id = ' . $me . ' AND b.story_id = s.id
                           ORDER BY b.created_at, b.chapter_id) b
                     LEFT JOIN chapters cb ON cb.id = b.chapter_id) AS bookmarks_blob' : '';
        return $this->db->one(
            'SELECT s.id, s.slug, s.title, s.summary, s.completed, s.created_at, s.updated_at, s.word_count,
                    s.canonical_url, s.crosspost_url,
                    u.penname, r.label AS rating_label, r.is_adult, r.warning_text,
                    MAX(CASE WHEN ch.position = ? THEN ch.id END) AS ch_id,
                    MAX(CASE WHEN ch.position = ? THEN ch.title END) AS ch_title,
                    MAX(CASE WHEN ch.position = ? THEN ch.notes_before END) AS ch_notes_before,
                    MAX(CASE WHEN ch.position = ? THEN ch.content END) AS ch_content,
                    MAX(CASE WHEN ch.position = ? THEN ch.notes_after END) AS ch_notes_after,
                    MAX(CASE WHEN ch.position = ? THEN ch.word_count END) AS ch_word_count,
                    (SELECT json_group_array(json_object(\'position\', ch3.position, \'title\', ch3.title, \'word_count\', ch3.word_count))
                     FROM chapters ch3 WHERE ch3.story_id = s.id AND ch3.validated = 1
                     ORDER BY ch3.position) AS chapters_blob,
                    (SELECT COUNT(*) FROM reviews r4 WHERE r4.story_id = s.id AND r4.parent_id IS NULL) AS review_count' . $progressCols . $bookmarksCols . '
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             LEFT JOIN chapters ch ON ch.story_id = s.id AND ch.validated = 1' . $progressJoin . '
             WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)
             GROUP BY s.id',
            [$position, $position, $position, $position, $position, $position, $slug, $me]
        );
    }

    /** @return list<array<string,mixed>> The listings stay guest-cacheable:
     *  restricted works never appear here and members reach them by direct
     *  URL (the story queries gate per-viewer instead). A viewer > 0
     *  additionally drops that member's muted authors (the Task 2 clause)
     *  and folds her reading progress (the C4 member-only envelope idiom:
     *  the reading_history LEFT JOIN and the derived read_pct exist only
     *  when the viewer is a member, so the anonymous statement stays
     *  byte-identical and cached bytes never vary by reader; the viewer id
     *  is inlined as an int, never a bind, so the join seeks the
     *  (user_id, story_id) PK directly). $filter is the recent screen's
     *  facet (C10): the controller whitelists it, and anything the match
     *  below does not know reads as unfiltered, like the junk page param.
     *  $catSlug narrows to one category (the chips task): an EXISTS probe on
     *  story_categories/categories so a multi-category story is never
     *  duplicated, bound as a parameter, never interpolated. The cats_blob
     *  fold rides BOTH paths (guest and member): json_group_array over the
     *  (story_id, category_id) PK, so membership is already deduplicated and
     *  a DISTINCT would only buy a TEMP B-TREE sort on name.
     *  @param string $filter ''|complete|wip|under10k */
    public function recentStories(int $perPage, int $offset, int $viewer = 0, string $filter = '', string $catSlug = ''): array
    {
        $mute = $viewer > 0 ? MuteRepository::clause('s') : '';
        $facet = match ($filter) {
            'complete' => ' AND s.completed = 1',
            'wip' => ' AND s.completed = 0',
            'under10k' => ' AND s.word_count < 10000',
            default => '',
        };
        $catClause = $catSlug !== ''
            ? ' AND EXISTS (SELECT 1 FROM story_categories sc2
                     JOIN categories c2 ON c2.id = sc2.category_id
                     WHERE sc2.story_id = s.id AND c2.slug = ?)'
            : '';
        $progressJoin = $viewer > 0 ? ' LEFT JOIN reading_history rr ON rr.story_id = s.id AND rr.user_id = ' . $viewer : '';
        $progressCols = $viewer > 0 ? ',
                    rr.last_position,
                    CASE WHEN rr.last_position IS NULL THEN NULL ELSE
                      MIN(100, CAST(ROUND(100.0 * COALESCE((SELECT SUM(c2.word_count) FROM chapters c2
                         WHERE c2.story_id = s.id AND c2.validated = 1 AND c2.position <= rr.last_position), 0)
                       / NULLIF(s.word_count, 0)) AS INTEGER)) END AS read_pct' : '';
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at, s.created_at,
                    u.penname, r.label AS rating_label,
                    (SELECT json_group_array(json_object(\'slug\', c.slug, \'name\', c.name)) FROM story_categories sc
                     JOIN categories c ON c.id = sc.category_id
                     WHERE sc.story_id = s.id) AS cats_blob' . $progressCols . '
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id' . $progressJoin . '
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0'
            . $facet . $catClause . $mute .
            ' ORDER BY s.updated_at DESC, s.id DESC
             LIMIT ? OFFSET ?',
            match (true) {
                $viewer > 0 && $catSlug !== '' => [$catSlug, $viewer, $perPage, $offset],
                $viewer > 0 => [$viewer, $perPage, $offset],
                $catSlug !== '' => [$catSlug, $perPage, $offset],
                default => [$perPage, $offset],
            }
        );
    }

    /** The shared base for every site-level feed surface (/feed and /rss):
     *  recentStories' shape plus the syndication exclusion (external-canonical
     *  stories belong to their author's chosen home, not to our feeds). In
     *  full-text mode the first validated chapter rides along as a param-free
     *  subselect so both variants stay ONE statement (the one-query law).
     *  @return list<array<string,mixed>> */
    public function feedStories(int $perPage, int $offset, bool $fullText = false): array
    {
        $chapter = $fullText ? ',
                    (SELECT c.content FROM chapters c WHERE c.story_id = s.id AND c.validated = 1 ORDER BY c.position LIMIT 1) AS first_chapter' : '';
        return $this->db->all(
            "SELECT s.slug, s.title, s.summary, s.updated_at, s.created_at, u.penname{$chapter}
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0
               AND s.canonical_url IS NULL
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );
    }

    /** Language-filtered listing for the browse filter (recentStories' shape,
     *  capped at 50, no pagination). The query string that drives it makes
     *  these pages cache-ineligible: Cache refuses queryful GETs. The Task 2
     *  mute clause rides per viewer; anonymous keeps the two-bind shape. */
    public function storiesInLanguage(string $language, int $viewer = 0): array
    {
        $mute = $viewer > 0 ? MuteRepository::clause('s') : '';
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at, s.created_at,
                    u.penname, r.label AS rating_label
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.language = ? AND s.is_restricted = 0'
            . $mute .
            ' ORDER BY s.updated_at DESC, s.id DESC
             LIMIT 50',
            $viewer > 0 ? [$language, $viewer] : [$language]
        );
    }

    /** ONE query: the category listing joined on the category slug. The Task 2
     *  mute clause lands after the visibility gates, ahead of LIMIT/OFFSET, so
     *  the member bind order is [$slug, $viewer, $perPage, $offset]. */
    public function storiesInCategory(string $slug, int $perPage, int $offset, int $viewer = 0): array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return [];
        $mute = $viewer > 0 ? MuteRepository::clause('s') : '';
        return $this->db->all(
            'SELECT s.slug, s.title, s.summary, s.completed, s.word_count, s.updated_at,
                    u.penname, r.label AS rating_label
             FROM stories s
             JOIN users u ON u.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             JOIN story_categories sc ON sc.story_id = s.id
             JOIN categories cc ON cc.id = sc.category_id
             WHERE cc.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0'
            . $mute .
            ' ORDER BY s.updated_at DESC, s.id DESC
             LIMIT ? OFFSET ?',
            $viewer > 0 ? [$slug, $viewer, $perPage, $offset] : [$slug, $perPage, $offset]
        );
    }

    /** The per-category Atom feed as ONE anchor-row query: the category row is
     *  the anchor and the LEFT JOIN carries its visible stories. Zero rows
     *  means the slug is unknown: the controller 404s. A row with NULL story
     *  columns is an empty category: a valid EMPTY feed titled with the
     *  category name. The story gates match storiesInCategory plus the
     *  syndication exclusion, and the membership fold is an IN subselect so
     *  the LEFT JOIN never multiplies rows on multi-category stories. In
     *  full-text mode the first validated chapter rides along as above.
     *  storiesInCategory itself stays untouched (the HTML page's query).
     *  @return list<array<string,mixed>> */
    public function categoryFeed(string $slug, int $perPage, int $offset, bool $fullText = false): array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) return [];
        $chapter = $fullText ? ',
                    (SELECT c.content FROM chapters c WHERE c.story_id = s.id AND c.validated = 1 ORDER BY c.position LIMIT 1) AS first_chapter' : '';
        return $this->db->all(
            "SELECT cc.name AS feed_title, s.slug, s.title, s.summary, s.updated_at, s.created_at,
                    (SELECT su.penname FROM users su WHERE su.id = s.author_id) AS penname{$chapter}
             FROM categories cc
             LEFT JOIN stories s ON s.id IN (SELECT sc.story_id FROM story_categories sc WHERE sc.category_id = cc.id)
                   AND s.deleted_at IS NULL AND s.validated = 1 AND s.is_restricted = 0
                   AND s.canonical_url IS NULL
             WHERE cc.slug = ?
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT ? OFFSET ?",
            [$slug, $perPage, $offset]
        );
    }

    /** @return list<array<string,mixed>> */
    public function categoriesWithCounts(): array
    {
        return $this->db->all(
            'SELECT c.id, c.name, c.slug, c.description,
                    (SELECT COUNT(*) FROM story_categories sc
                     JOIN stories s ON s.id = sc.story_id
                     WHERE sc.category_id = c.id AND s.validated = 1 AND s.deleted_at IS NULL) AS story_count
             FROM categories c ORDER BY c.position, c.name'
        );
    }
}
