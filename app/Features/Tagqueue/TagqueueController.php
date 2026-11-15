<?php // app/Features/Tagqueue/TagqueueController.php
namespace App\Features\Tagqueue;
use App\{AdminScreen, Lang};
use Kip\Http\Response;
use Kip\Routing\Auth;

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
        $rows = $this->db->all(
            "SELECT 'q' AS k, s.tag_type_id AS a, tt.name AS b, MIN(s.name) AS c, COUNT(*) AS d,
                    GROUP_CONCAT(st.title, '|') AS e, tt.position AS f
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
                    'count' => (int) $r['d'], 'titles' => explode('|', (string) $r['e']), 'tags' => []];
            }
        }
        // The merge picker's options: the live tags of the row's own type,
        // retired synonyms excluded (a retired target would resolve to its
        // canonical, which the select already offers directly).
        foreach ($rows as $r) {
            if ($r['k'] !== 't') continue;
            $typeId = (int) $r['b'];
            foreach ($groups as &$g) {
                if ($g['typeId'] === $typeId) { $g['tags'][] = ['id' => (int) $r['a'], 'name' => (string) $r['c']]; break; }
            }
            unset($g);
        }
        foreach ($groups as &$g) {
            usort($g['tags'], fn (array $x, array $y): int => strcasecmp($x['name'], $y['name']));
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
}
