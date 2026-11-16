<?php // app/Features/Tagqueue/TagqueueController.php
namespace App\Features\Tagqueue;
use App\{AdminScreen, Lang};
use Kip\Http\Response;
use Kip\Routing\{Auth, Post};

/** The owner's queue of waiting tag suggestions (admin only). Pending names
 *  live in tag_suggestions, never in tags, so nothing here is reachable from
 *  a public page; this screen is the only surface that reads them. The list
 *  and the merge picker ride ONE statement (the queue rows grouped by type
 *  and lower name, the live tags beside them for the Use Existing Tag
 *  select). Approve, merge and reject are Task 4.5's writes. */
final class TagqueueController extends AdminScreen
{
    /** @return array{groups: list<array{typeId: int, type: string, name: string, count: int, titles: list<string>, tags: list<array{id: int, name: string}>}>, total: int} */
    private function queue(): array
    {
        // The titles concat splits on a unit separator, not a visible one: a
        // title carrying a pipe would otherwise come back as two titles.
        $sep = "\x1f";
        $rows = $this->db->all(
            "SELECT 'q' AS k, s.tag_type_id AS a, tt.name AS b, MIN(s.name) AS c, COUNT(*) AS d,
                    GROUP_CONCAT(st.title, '{$sep}') AS e, tt.position AS f
             FROM tag_suggestions s
             JOIN tag_types tt ON tt.id = s.tag_type_id
             JOIN stories st ON st.id = s.story_id
             WHERE s.status = 'pending'
             GROUP BY s.tag_type_id, lower(s.name)
             UNION ALL
             SELECT 't', t.id, t.tag_type_id, t.name, NULL, NULL, NULL
             FROM tags t
             WHERE t.canonical_id IS NULL
             ORDER BY k, c"
        );
        $typeOrder = [];
        $groups = [];
        foreach ($rows as $r) {
            if ($r['k'] === 'q') {
                $typeId = (int) $r['a'];
                $typeOrder[$typeId] = [(int) $r['f'], (string) $r['b']];
                $groups[] = ['typeId' => $typeId, 'type' => (string) $r['b'], 'name' => (string) $r['c'],
                    'count' => (int) $r['d'], 'titles' => explode($sep, (string) $r['e']), 'tags' => []];
            }
        }
        // The merge picker's options: the live tags of the row's own type,
        // retired synonyms excluded (a retired target would resolve to its
        // canonical, which the select already offers directly).
        $tagsByType = [];
        foreach ($rows as $r) {
            if ($r['k'] !== 't') continue;
            $typeId = (int) $r['b'];
            $tagsByType[$typeId][] = ['id' => (int) $r['a'], 'name' => (string) $r['c']];
        }
        foreach ($tagsByType as &$tags) {
            usort($tags, fn (array $x, array $y): int => strcasecmp($x['name'], $y['name']));
        }
        unset($tags);
        foreach ($groups as &$g) {
            $g['tags'] = $tagsByType[$g['typeId']] ?? [];
        }
        unset($g);
        usort($groups, fn (array $x, array $y): int => [$typeOrder[$x['typeId']][0], $x['name']] <=> [$typeOrder[$y['typeId']][0], $y['name']]);
        return ['groups' => $groups, 'total' => count($groups)];
    }

    #[Auth]
    public function index(): Response|string
    {
        // The /manage idiom: valid() has just read the viewer's role into the
        // static memo, so the gate costs no second users query and the page
        // stays budget 1 with the queue statement.
        $uid = $this->session->get('user_id');
        if (!is_int($uid) || !\App\SessionGuard::valid($this->db, $this->session)) {
            return $this->deny() ?? new Response(Lang::t('error.forbidden'), 403);
        }
        if (\App\SessionGuard::role() !== 'admin') return new Response(Lang::t('error.forbidden'), 403);
        $q = $this->queue();
        return $this->page('tagqueue/index', 'tagqueue.heading', '/tagqueue', [
            'groups' => $q['groups'], 'total' => $q['total'],
        ]);
    }

    /** The admin gate for the writes, with the transaction's undo path: the
     *  gate's users row is a second query, which a write can afford. */
    private function gate(): ?Response
    {
        $g = $this->deny();
        return $g instanceof Response ? $g : null;
    }

    /** @return array{0: int, 1: string} the (type id, name) key the queue
     *  forms post; junk coerces to a key that holds no rows, which 404s. */
    private function keyInput(): array
    {
        return [(int) ($this->request->post['type_id'] ?? 0), trim((string) ($this->request->post['name'] ?? ''))];
    }

    /** Tell each distinct suggester what happened to their name, once, with
     *  the acting admin left out (the actor never notifies themselves). Runs
     *  after the action's commit, so a notification is never inside the
     *  transaction it describes. */
    private function notify(array $rows, string $kind, int $me, string $name): void
    {
        $notifications = new \App\Notifications($this->db);
        $seen = [];
        foreach ($rows as $r) {
            $user = (int) $r['user'];
            if (isset($seen[$user]) || $user === $me || $user <= 0) continue;
            $seen[$user] = true;
            $notifications->create($user, $kind, (int) $r['story'], $me, $name);
        }
    }

    #[Auth]
    #[Post]
    public function approve(): Response|string
    {
        if (($g = $this->gate()) !== null) return $g;
        [$typeId, $name] = $this->keyInput();
        $me = (int) $this->session->get('user_id');
        $touched = [];
        try {
            $this->atomically(function () use (&$touched, $typeId, $name, $me): void {
                $touched = (new \App\Repositories\TagSuggestionRepository($this->db))->approve($typeId, $name, $me);
            });
        } catch (\App\SuggestionTypeMismatch) {
            return new Response(Lang::t('error.not_found'), 404);
        }
        if ($touched === []) return new Response(Lang::t('error.not_found'), 404);
        \App\ActivityLog::by($this->db, $this->session, 'tag_suggestion.approve', "type {$typeId}: {$name}");
        $this->notify($touched, 'tag_suggestion_approved', $me, $name);
        $this->purge(); // approve changes what public story pages and listings show
        return \App\Saved::to('/tagqueue', \App\CachePurge::savedCode($this->app, 'added'));
    }

    #[Auth]
    #[Post]
    public function merge(): Response|string
    {
        if (($g = $this->gate()) !== null) return $g;
        [$typeId, $name] = $this->keyInput();
        $targetId = (int) ($this->request->post['target_id'] ?? 0);
        $me = (int) $this->session->get('user_id');
        $touched = [];
        try {
            $this->atomically(function () use (&$touched, $typeId, $name, $targetId, $me): void {
                $touched = (new \App\Repositories\TagSuggestionRepository($this->db))->mergeInto($typeId, $name, $targetId, $me);
            });
        } catch (\App\SuggestionTypeMismatch) {
            return new Response(Lang::t('tagqueue.err_type'), 422);
        }
        if ($touched === []) return new Response(Lang::t('error.not_found'), 404);
        \App\ActivityLog::by($this->db, $this->session, 'tag_suggestion.merge', "type {$typeId}: {$name} -> tag {$targetId}");
        $this->notify($touched, 'tag_suggestion_approved', $me, $name);
        $this->purge();
        return \App\Saved::to('/tagqueue', \App\CachePurge::savedCode($this->app, 'moved'));
    }

    #[Auth]
    #[Post]
    public function reject(): Response|string
    {
        if (($g = $this->gate()) !== null) return $g;
        [$typeId, $name] = $this->keyInput();
        $me = (int) $this->session->get('user_id');
        $touched = [];
        $this->atomically(function () use (&$touched, $typeId, $name, $me): void {
            $touched = (new \App\Repositories\TagSuggestionRepository($this->db))->reject($typeId, $name, $me);
        });
        if ($touched === []) return new Response(Lang::t('error.not_found'), 404);
        \App\ActivityLog::by($this->db, $this->session, 'tag_suggestion.reject', "type {$typeId}: {$name}");
        $this->notify($touched, 'tag_suggestion_rejected', $me, $name);
        // reject changes nothing public: no purge (the PurgeSeams rule)
        return \App\Saved::to('/tagqueue');
    }
}
