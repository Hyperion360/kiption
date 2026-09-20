<?php // app/src/Repositories/ToplistRepository.php
namespace App\Repositories;
use Kip\Database;

final class ToplistRepository
{
    public function __construct(private Database $db) {}

    /** The whole /top hub in ONE compound statement: four bounded 10-row
     *  aggregate branches (the queue-fold precedent), guest gates only -
     *  this is the anonymous cached variant, so restricted works are
     *  EXCLUDED outright, never personalized (the 6b leak class).
     *  BIND AUDIT: the fold has ZERO binds - an anonymous page with zero
     *  personalization (COUNT the ?s: there are none; the params array is
     *  empty by construction). Finding 5, probed: rank is emitted as a RAW
     *  INTEGER (no CAST around ROW_NUMBER) so the bare outer ORDER BY
     *  k, rank sorts numerically; a TEXT rank sorts lexicographically and
     *  a CAST inside a compound ORDER BY does not prepare. Finding 18:
     *  ROW_NUMBER numbers each branch AFTER the gates, so gated-out
     *  members renumber survivors contiguously - intended for an anonymous
     *  cached hub. Every branch emits k + rank + a..g = 9 columns, padded
     *  identically. EXPLAIN note (pre-ruled in the plan): TEMP B-TREE lines
     *  inside the branches are accepted for this page - bounded aggregates
     *  over the count indexes on a cacheable surface; the outer compound
     *  orders by bare aliases only. Reviews count ROOTS ONLY
     *  (parent_id IS NULL); Top rated requires >= 3 root ratings
     *  (rating IS NOT NULL) and carries the rounded average plus the count.
     *  @return array{favorites:array,kudos:array,reviews:array,rated:array} */
    public function hub(): array
    {
        $rows = $this->db->all(
            "SELECT 'k' k, ROW_NUMBER() OVER (ORDER BY x.c DESC, x.story_id) rank,
                    s.slug a, s.title b, u.penname c, u.profile_slug d, r.label e,
                    CAST(x.c AS TEXT) f, NULL g
             FROM (SELECT story_id, COUNT(*) c FROM story_kudos GROUP BY story_id ORDER BY c DESC, story_id LIMIT 10) x
             JOIN stories s ON s.id = x.story_id JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0
             UNION ALL
             SELECT 'f' k, ROW_NUMBER() OVER (ORDER BY x.c DESC, x.story_id) rank,
                    s.slug a, s.title b, u.penname c, u.profile_slug d, r.label e,
                    CAST(x.c AS TEXT) f, NULL g
             FROM (SELECT story_id, COUNT(*) c FROM favorites WHERE story_id IS NOT NULL GROUP BY story_id ORDER BY c DESC, story_id LIMIT 10) x
             JOIN stories s ON s.id = x.story_id JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0
             UNION ALL
             SELECT 'r' k, ROW_NUMBER() OVER (ORDER BY x.c DESC, x.story_id) rank,
                    s.slug a, s.title b, u.penname c, u.profile_slug d, r.label e,
                    CAST(x.c AS TEXT) f, NULL g
             FROM (SELECT story_id, COUNT(*) c FROM reviews WHERE parent_id IS NULL GROUP BY story_id ORDER BY c DESC, story_id LIMIT 10) x
             JOIN stories s ON s.id = x.story_id JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0
             UNION ALL
             SELECT 'g' k, ROW_NUMBER() OVER (ORDER BY x.a DESC, x.story_id) rank,
                    s.slug a, s.title b, u.penname c, u.profile_slug d, r.label e,
                    CAST(ROUND(x.a * 10) / 10 AS TEXT) f, CAST(x.n AS TEXT) g
             FROM (SELECT story_id, COUNT(*) n, Avg(rating) a FROM reviews WHERE parent_id IS NULL AND rating IS NOT NULL
                   GROUP BY story_id HAVING COUNT(*) >= 3 ORDER BY Avg(rating) DESC, story_id LIMIT 10) x
             JOIN stories s ON s.id = x.story_id JOIN users u ON u.id = s.author_id JOIN ratings r ON r.id = s.rating_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0
             ORDER BY k, rank",
            []
        );
        return $this->partition($rows);
    }

    /** Split the fold into its four sections; each row normalizes to named
     *  keys (the SearchRepository::partition precedent), count sections
     *  carrying the aggregate and the rated section the rounded average
     *  plus the rating count. Rows arrive rank-ordered per section (the
     *  compound's ORDER BY k, rank). */
    private function partition(array $rows): array
    {
        $sections = ['favorites' => [], 'kudos' => [], 'reviews' => [], 'rated' => []];
        foreach ($rows as $r) {
            $base = [
                'rank' => (int) $r['rank'],
                'slug' => (string) $r['a'],
                'title' => (string) $r['b'],
                'penname' => (string) $r['c'],
                'profile_slug' => (string) $r['d'],
                'rating_label' => (string) $r['e'],
            ];
            if ($r['k'] === 'f') { $sections['favorites'][] = $base + ['count' => (int) $r['f']]; continue; }
            if ($r['k'] === 'k') { $sections['kudos'][] = $base + ['count' => (int) $r['f']]; continue; }
            if ($r['k'] === 'r') { $sections['reviews'][] = $base + ['count' => (int) $r['f']]; continue; }
            $sections['rated'][] = $base + ['average' => (string) $r['f'], 'ratings' => (int) $r['g']];
        }
        return $sections;
    }
}
