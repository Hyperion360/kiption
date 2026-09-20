<?php // app/src/Repositories/NewsRepository.php
namespace App\Repositories;

use Kip\Database;

/** The news fold: both reader surfaces are budget-1 pages, so the index
 *  listing and the item-with-comments view each resolve in ONE statement.
 *  Writes are member comments (throttled) and the admin form's inserts and
 *  updates, none of which are budget-pinned. */
final class NewsRepository
{
    private const COMMENT_WINDOW = 50; // the story view's recent-reviews cap precedent

    public function __construct(private Database $db) {}

    /** Index page rows, newest first: id + title + published_at plus the
     *  comment-count scalar, one query. */
    public function listing(int $perPage, int $offset): array
    {
        return array_map(
            fn (array $r): array => [
                'id' => (int) $r['id'], 'title' => (string) $r['title'],
                'published_at' => (string) $r['published_at'], 'comment_count' => (int) $r['comment_count'],
            ],
            $this->db->all(
                'SELECT id, title, published_at,
                        (SELECT COUNT(*) FROM news_comments nc WHERE nc.news_id = news.id) comment_count
                 FROM news ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?',
                [$perPage, $offset]
            )
        );
    }

    /** One item with its comments in ONE query, the seriesPage tab-fold idiom:
     *  k='0' is the news row (with author penname and the total comment count,
     *  so the view never runs a second query for the heading), k='1' rows are
     *  the paged comment window with pennames. The paging subselect keeps the
     *  news row out of the LIMIT: the window applies to comments only. Order
     *  is by the bare aliases k, a (compound selects reject expressions); both
     *  a columns are INTEGER ids, so the sort is numeric without a CAST.
     *  Returns ['news' => ..., 'comments' => ..., 'comment_count' => int] or
     *  null when the id is unknown.
     *  @return array{news: array, comments: array, comment_count: int}|null */
    public function withComments(int $id, int $perPage, int $offset): ?array
    {
        $rows = $this->db->all(
            "SELECT '0' AS k, n.id a, n.title b, n.body c, n.published_at d, u.penname e,
                    (SELECT COUNT(*) FROM news_comments x WHERE x.news_id = n.id) f
             FROM news n LEFT JOIN users u ON u.id = n.author_id
             WHERE n.id = ?
             UNION ALL
             SELECT '1' AS k, nc.id a, NULL b, nc.body c, nc.created_at d, u2.penname e, NULL f
             FROM news_comments nc LEFT JOIN users u2 ON u2.id = nc.user_id
             WHERE nc.news_id = ?
               AND nc.id IN (SELECT x2.id FROM news_comments x2 WHERE x2.news_id = ?
                             ORDER BY x2.id DESC LIMIT ? OFFSET ?)
             ORDER BY k, a",
            // FIVE binds in order of appearance: the news id (WHERE, fold filter,
            // paging subselect), then the comment window's LIMIT and OFFSET.
            [$id, $id, $id, $perPage, $offset]
        );
        if ($rows === [] || $rows[0]['k'] !== '0') return null;
        $news = [
            'id' => (int) $rows[0]['a'], 'title' => (string) $rows[0]['b'],
            'body' => (string) $rows[0]['c'], 'published_at' => (string) $rows[0]['d'],
            'penname' => $rows[0]['e'] !== null ? (string) $rows[0]['e'] : null,
        ];
        $count = (int) $rows[0]['f'];
        $comments = [];
        foreach (array_slice($rows, 1) as $r) {
            $comments[] = [
                'id' => (int) $r['a'], 'body' => (string) $r['c'], 'created_at' => (string) $r['d'],
                'penname' => $r['e'] !== null ? (string) $r['e'] : null,
            ];
        }
        return ['news' => $news, 'comments' => $comments, 'comment_count' => $count];
    }

    /** The fixed recent-comments window the view renders (newest 50, the story
     *  view's reviews precedent; queries with ?page= stay cache-ineligible). */
    public function commentWindow(): array
    {
        return [self::COMMENT_WINDOW, 0];
    }

    /** Member comment write, finding 8's guarded INSERT..SELECT: the throttle
     *  is a COUNT inside the INSERT's WHERE, never a check-then-act pair (the
     *  report-intake and review-guard idiom; two racing members both counted
     *  zero and both stored under check-then-act, probe-verified there).
     *  Returns false when the one-per-member-per-item-per-hour guard refused
     *  the row (rowCount 0). */
    public function addComment(int $newsId, int $userId, string $body): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO news_comments (news_id, user_id, body)
             SELECT ?, ?, ?
             WHERE (SELECT COUNT(*) FROM news_comments
                    WHERE news_id = ? AND user_id = ? AND created_at > strftime('%Y-%m-%dT%H:%M:%fZ','now','-1 hour')) = 0",
            [$newsId, $userId, $body, $newsId, $userId]
        );
        return $stmt->rowCount() === 1;
    }

    public function find(int $id): ?array
    {
        $row = $this->db->one('SELECT id, title, body FROM news WHERE id = ?', [$id]);
        return $row === null ? null
            : ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'body' => (string) $row['body']];
    }
}
