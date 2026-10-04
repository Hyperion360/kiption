<?php // app/Features/Recommend/RecommendController.php
namespace App\Features\Recommend;
use Kip\{App, Database, Http\Request, Http\Response, Session};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Notifications;

/** Member recommendations (POST /recommend/add/{slug} and /recommend/remove/{slug},
 *  the /follow/author/{id} URL shape). Member-gated and CSRF-checked by the
 *  kernel (#[Auth] + #[Post]); the first URL segment 'recommend' is the rate
 *  bucket (config.php's per-first-segment rule). The upsert and the author
 *  notification ride ONE transaction; the notification fires only on the
 *  insert path, so a no-op rerun never re-notifies (the follow precedent). */
final class RecommendController
{
    public function __construct(
        private Database $db, private Request $request, private Session $session,
        private App $app,
    ) {}

    #[AuthAttr] #[Post]
    public function add(string $slug): Response
    {
        $me = (int) $this->session->get('user_id');
        $story = $this->db->one('SELECT id, author_id, title FROM stories WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        if ($story === null) return new Response('Page not found', 404);
        $inserted = false;
        $this->db->begin();
        try {
            // ON CONFLICT DO NOTHING: a re-recommend is a no-op (rowCount 0),
            // and only the fresh insert notifies the author (never the
            // recommender's own works: the actor-exclusion precedent).
            $stmt = $this->db->query(
                'INSERT INTO recommendations (user_id, story_id, note) VALUES (?, ?, ?)
                 ON CONFLICT (user_id, story_id) DO NOTHING',
                [$me, (int) $story['id'], $this->oneLineNote()]);
            $inserted = $stmt->rowCount() === 1;
            if ($inserted && (int) $story['author_id'] !== $me) {
                (new Notifications($this->db))
                    ->create((int) $story['author_id'], 'recommendation', (int) $story['id'], $me, (string) $story['title']);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if ($inserted) $this->purge($slug, $me);
        return Response::redirect('/story/view/' . $slug);
    }

    #[AuthAttr] #[Post]
    public function remove(string $slug): Response
    {
        $me = (int) $this->session->get('user_id');
        $story = $this->db->one('SELECT id FROM stories WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        if ($story === null) return new Response('Page not found', 404);
        $stmt = $this->db->query('DELETE FROM recommendations WHERE user_id = ? AND story_id = ?', [$me, (int) $story['id']]);
        if ($stmt->rowCount() === 1) $this->purge($slug, $me);
        return Response::redirect('/story/view/' . $slug);
    }

    /** Both cached surfaces move on a recommend: the story page's public
     *  count (purgeStory, the kudos precedent) and the recommender's own
     *  profile page (purgeUser, the new Recommended section). */
    private function purge(string $slug, int $me): void
    {
        $cache = \App\StaticCache\Cache::configured($this->app);
        $cache->purgeStory($slug, []);
        $profileSlug = $this->db->one('SELECT profile_slug FROM users WHERE id = ?', [$me]);
        if ($profileSlug !== null && (string) $profileSlug['profile_slug'] !== '') {
            $cache->purgeUser((string) $profileSlug['profile_slug']);
        }
    }

    /** One line, at most 200 characters: the note rides the profile's
     *  GROUP_CONCAT blob, so a newline would break the one-line list shape
     *  and an essay would drown it. */
    private function oneLineNote(): string
    {
        $note = trim($this->request->postStr('note'));
        return mb_substr((string) preg_replace('/\s+/u', ' ', $note), 0, 200);
    }
}
