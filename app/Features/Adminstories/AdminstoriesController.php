<?php // app/Features/Adminstories/AdminstoriesController.php
namespace App\Features\Adminstories;
use Kip\{Database, Http\Request, Http\Response, Session};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\AuthoringRepository;

/** Admin-only story tools the generic panel cannot safely provide: author
 *  reassignment (a cascade the raw table edit would skip) and the featured
 *  flag the home page consumes. Both use the SQL admin gate, not
 *  Adminness::requireModerator: reassignment is admin-only per the operator
 *  framing (the coauthor-inclusive story gate is a different, weaker thing).
 *
 *  The class name is deliberately Adminstories (no inner capital): the router
 *  resolves /adminstories to the studly name AdminstoriesController and the
 *  PSR-4 autoloader maps that literally; a camelCase class only resolves on a
 *  case-insensitive filesystem and would 404 whole on Linux (QA 10a;
 *  AdminToolsTest pins the contract for every controller). */
final class AdminstoriesController
{
    public function __construct(
        private Request $request, private Database $db, private Session $session, private \Kip\App $app,
    ) {}

    /** Move a story to a new author by penname. The lookup is the coauthor
     *  idiom (COLLATE NOCASE full member: approved, verified, unlocked), so
     *  an unknown or half-registered penname 404s instead of silently
     *  orphaning the story onto a locked account. */
    #[AuthAttr] #[Post]
    public function reassign(string $slug): Response
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $story = $this->db->one('SELECT id, author_id FROM stories WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        if ($story === null) return new Response('Page not found', 404);
        $penname = trim($this->request->postStr('penname'));
        $target = $penname === '' ? null : $this->db->one(
            'SELECT id FROM users WHERE penname = ? COLLATE NOCASE AND approved_at IS NOT NULL AND email_verified_at IS NOT NULL AND is_locked = 0',
            [$penname]);
        if ($target === null) return new Response('Page not found', 404);
        [$storyId, $oldId, $newId] = [(int) $story['id'], (int) $story['author_id'], (int) $target['id']];
        if ($newId === $oldId) return Response::redirect('/story/view/' . $slug); // no-op: nothing to move or purge
        $this->db->query('UPDATE stories SET author_id = ? WHERE id = ?', [$newId, $storyId]);
        // Finding 14: the coauthor rows of BOTH authors pointing at this story
        // go. A target who was already a coauthor would otherwise end up author
        // AND coauthor; the old author's stale row must not survive either.
        $this->db->query('DELETE FROM coauthors WHERE story_id = ? AND user_id IN (?, ?)', [$storyId, $oldId, $newId]);
        // The full purge set: the story's own surfaces under its new author
        // key, BOTH profiles (the story counts moved for both), and the whole
        // authors directory (its counts and letter pages moved too).
        $repo = new AuthoringRepository($this->db);
        $cats = $repo->categorySlugs($storyId);
        [$seriesSlugs, $newSlug] = $repo->purgeData($storyId, $newId);
        $oldSlug = (string) ($this->db->one('SELECT profile_slug FROM users WHERE id = ?', [$oldId])['profile_slug'] ?? '');
        $cache = new \App\StaticCache\Cache((string) (($this->app->config('static_cache', []) ?? [])['dir'] ?? dirname(__DIR__, 3) . '/public/cache'));
        // The list rider (finding 4) rides the reassignment too: the belt-and-
        // braces set for every derived surface the story feeds.
        $cache->purgeStory($slug, $cats, $seriesSlugs, $newSlug, (new \App\Repositories\ListsRepository($this->db))->publicListSlugsForStory($storyId));
        if ($oldSlug !== '') $cache->purgeUser($oldSlug);
        if ($newSlug !== '') $cache->purgeUser($newSlug); // belt-and-braces with purgeStory's pass
        $cache->purgeAuthors();
        return Response::redirect('/story/view/' . $slug);
    }

    /** Toggle the featured flag. Only the home page consumes it, but the
     *  invalidation contract still rides purgeStory (its unconditional '/'
     *  unlink is the point; the story, series and author unlinks are the
     *  standard set, harmless and rebuilt on demand). Categories do not
     *  change, so no category slugs are passed. */
    #[AuthAttr] #[Post]
    public function featured(string $slug): Response
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $story = $this->db->one('SELECT id, author_id FROM stories WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        if ($story === null) return new Response('Page not found', 404);
        $this->db->query('UPDATE stories SET featured = 1 - featured WHERE id = ?', [(int) $story['id']]);
        $repo = new AuthoringRepository($this->db);
        [$seriesSlugs, $authorSlug] = $repo->purgeData((int) $story['id'], (int) $story['author_id']);
        (new \App\StaticCache\Cache((string) (($this->app->config('static_cache', []) ?? [])['dir'] ?? dirname(__DIR__, 3) . '/public/cache')))
            ->purgeStory($slug, [], $seriesSlugs, $authorSlug);
        return Response::redirect('/story/view/' . $slug);
    }

    /** The SQL admin gate (role = 'admin' iff is_admin = 1 makes one check enough). */
    private function admin(): bool
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$me])['c'] === 1;
    }
}
