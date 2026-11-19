<?php // app/Features/Manage/ManageController.php
namespace App\Features\Manage;
use App\AdminScreen;
use Kip\Http\Response;
use Kip\Routing\Auth as AuthAttr;

/** /manage, "Archive admin": the one page that lists every owner screen in
 *  plain groups. Adding a screen is one line in LINKS. */
final class ManageController extends AdminScreen
{
    /** The groups, in the order they print. */
    public const GROUPS = [
        'stories' => 'manage.group_stories',
        'pages' => 'manage.group_pages',
        'members' => 'manage.group_members',
        'look' => 'manage.group_look',
        'settings' => 'manage.group_settings',
    ];

    /** Every owner screen: [group, address, language key for the label, optional feature flag that must be on].
     *  The one-line description under each label is the label's key plus "_what". */
    public const LINKS = [
        ['stories', '/queue', 'manage.queue'],
        ['stories', '/categories', 'manage.categories'],
        ['stories', '/ratings', 'manage.ratings'],
        ['stories', '/tags', 'manage.tags'],
        ['stories', '/tagqueue', 'manage.tagqueue'],
        ['stories', '/characters', 'manage.characters'],
        ['stories', '/wrangling', 'manage.wrangling', 'wrangling'],
        ['pages', '/news', 'manage.news', 'news'],
        ['pages', '/news/new', 'manage.news_post', 'news'],
        ['pages', '/pages/manage', 'manage.pages'],
        ['pages', '/page/new', 'manage.page_new'],
        ['pages', '/images', 'manage.images'],
        ['members', '/adminmembers', 'manage.members'],
        ['members', '/mailusers', 'manage.mailusers'],
        ['members', '/invites', 'manage.invites'],
        ['members', '/profilefields', 'manage.profilefields'],
        ['members', '/signupcheck', 'manage.signupcheck'],
        ['look', '/text/group/msg', 'manage.messages'],
        ['look', '/text', 'manage.site_text'],
        ['look', '/nav', 'manage.nav'],
        ['look', '/look', 'manage.look'],
        ['look', '/templates', 'manage.templates'],
        ['settings', '/settings', 'manage.settings'],
        ['settings', '/features', 'manage.features'],
        ['settings', '/wordfilter', 'manage.wordfilter'],
        ['settings', '/activity', 'manage.activity'],
        ['settings', '/analytics', 'manage.analytics', 'analytics'],
    ];

    /** The way back shown above every owner screen: the admin menu, then the
     *  list a sub-screen belongs to (an edit or delete page under Tags, say).
     *  Empty for the menu itself and for pages that are not owner screens.
     *  @return list<array{url:string,label:string}> */
    public static function trail(string $path): array
    {
        $path = '/' . trim($path, '/');
        $seg = explode('/', ltrim($path, '/'));
        // News and pages share their first segment with public reading pages.
        if (in_array($seg[0], ['news', 'page'], true) && !in_array($seg[1] ?? '', ['new', 'edit', 'remove'], true)) return [];
        // Removing reported content is a queue step; filing a report (/report/story, /report/review) is a reader's.
        if ($seg[0] === 'report') { if (($seg[1] ?? '') !== 'remove') return []; $seg[0] = 'queue'; }
        $parent = null;
        foreach (self::LINKS as $link) {
            if ($link[1] === $path) { $parent = null; break; }
            $first = explode('/', ltrim($link[1], '/'))[0];
            if ($first === ($seg[0] === 'page' ? 'pages' : $seg[0])) {
                // the list screen is the shortest address in its family (/text over /text/group/msg)
                if ($parent === null || strlen($link[1]) < strlen($parent[1])) $parent = $link;
            }
        }
        $known = $parent !== null || in_array($path, array_column(self::LINKS, 1), true);
        if (!$known) return [];
        $trail = [['url' => '/manage', 'label' => 'manage.heading']];
        if ($parent !== null) $trail[] = ['url' => $parent[1], 'label' => $parent[2]];
        return $trail;
    }

    #[AuthAttr]
    public function index(): Response|string
    {
        // The gate and everything the page shows come back in one statement
        // (the queue's own shape): no gate row means not an admin. The live
        // session check is the same one deny() makes.
        $uid = $this->session->get('user_id');
        if (!is_int($uid) || !\App\SessionGuard::valid($this->db, $this->session)) {
            return $this->deny() ?? new Response(\App\Lang::t('error.forbidden'), 403);
        }
        // valid() has just read the viewer's role, so a member or moderator is
        // refused here, before the dashboard's count query runs at all.
        if (\App\SessionGuard::role() !== 'admin') return new Response(\App\Lang::t('error.forbidden'), 403);
        $waiting = ['stories' => 0, 'chapters' => 0, 'members' => 0, 'reports' => 0, 'tags' => 0, 'new' => 0];
        $recent = [];
        $info = [];
        $admin = false;
        $stillOwner = false;
        foreach ($this->homeRows($uid) as $row) {
            if ($row['k'] === '0gate') { $admin = true; $stillOwner = (string) $row['a'] === 'Owner'; }
            elseif ($row['k'] === '2log') $recent[] = $row;
            else { $waiting[substr($row['k'], 1)] = (int) $row['n']; $info[substr($row['k'], 1)] = $row; }
        }
        // The oldest thing that has waited, across everything that needs a decision.
        $oldest = null;
        foreach (['stories' => 'a', 'chapters' => 'a', 'members' => 'b', 'reports' => 'a'] as $k => $col) {
            $stamp = (string) ($info[$k][$col] ?? '');
            if ($waiting[$k] > 0 && $stamp !== '' && ($oldest === null || strtotime($stamp) < strtotime($oldest))) $oldest = $stamp;
        }
        if (!$admin) return new Response(\App\Lang::t('error.forbidden'), 403);
        $groups = [];
        foreach (self::LINKS as $link) {
            if (isset($link[3]) && !\App\Features::on($link[3])) continue;
            $groups[$link[0]][] = ['url' => $link[1], 'label' => $link[2]];
        }
        // The sidebar and the phone Queue tab carry the same numbers the home
        // already holds, so no other query is spent on them.
        \App\AdminNav::waiting(['/queue' => $waiting['stories'] + $waiting['chapters'] + $waiting['members'] + $waiting['reports'], '/tagqueue' => $waiting['tags']]);
        // The raw table editor: hidden when the archive turns it off (config admin.enabled).
        $database = (bool) (((array) $this->app->config('admin', []))['enabled'] ?? true);
        return $this->page('manage/index', 'manage.heading', '/manage', [
            'waiting' => $waiting,
            'info' => $info,
            'oldest' => $oldest,
            'recent' => $recent,
            'groups' => $groups,
            'groupNames' => self::GROUPS,
            'database' => $database,
            'stillOwner' => $stillOwner,
        ]);
    }

    /** One statement for the whole home screen: the admin gate row, the
     *  counts of what is waiting (the same conditions the review queue lists
     *  by), new members in the last seven days, and the newest log lines.
     *  @return list<array<string,mixed>> */
    private function homeRows(int $userId): array
    {
        return $this->db->all(
            "SELECT '0gate' AS k, u.id AS n, u.penname AS a, '' AS b, '' AS c, '' AS d FROM users u WHERE u.id = ? AND u.role = 'admin'
             UNION ALL
             SELECT '1stories', COUNT(*), MIN(s.updated_at), '', (SELECT GROUP_CONCAT(title, char(31)) FROM (SELECT s2.title FROM stories s2 WHERE " . str_replace('s.', 's2.', \App\Repositories\AuthoringRepository::WAITING_STORY) . " ORDER BY s2.created_at, s2.id LIMIT 3)), '' FROM stories s WHERE " . \App\Repositories\AuthoringRepository::WAITING_STORY . "
             UNION ALL
             SELECT '1chapters', COUNT(*), MIN(ch.updated_at), '', '', '' FROM chapters ch JOIN stories s ON s.id = ch.story_id
             WHERE " . \App\Repositories\AuthoringRepository::WAITING_CHAPTER . "
             UNION ALL
             SELECT '1members', COUNT(*), COALESCE((SELECT us2.penname FROM users us2 WHERE " . str_replace('us.', 'us2.', \App\Repositories\AuthoringRepository::WAITING_MEMBER) . " ORDER BY us2.created_at DESC, us2.id DESC LIMIT 1), ''), COALESCE(MIN(us.created_at), ''), COALESCE((SELECT 1 FROM signup_check sc WHERE sc.id = 1 AND sc.enabled = 1 AND sc.question <> '' AND sc.answer <> ''), 0), COALESCE(MAX(us.created_at), '') FROM users us WHERE " . \App\Repositories\AuthoringRepository::WAITING_MEMBER . "
             UNION ALL
             SELECT '1reports', COUNT(*), COALESCE(MIN(created_at), ''), '',
                    (SELECT GROUP_CONCAT(x, char(30)) FROM (SELECT CASE WHEN r.story_id IS NOT NULL THEN 's' ELSE 'r' END || char(31) || COALESCE(st.title, rs.title, '') || char(31) || substr(r.reason, 1, 60) AS x
                        FROM reports r LEFT JOIN stories st ON st.id = r.story_id LEFT JOIN reviews rv ON rv.id = r.review_id LEFT JOIN stories rs ON rs.id = rv.story_id
                        WHERE r.resolved_at IS NULL ORDER BY r.created_at, r.id LIMIT 2)), '' FROM reports WHERE resolved_at IS NULL
             UNION ALL
             SELECT '1tags', COUNT(DISTINCT s.tag_type_id || ':' || lower(s.name)), '', '',
                    (SELECT GROUP_CONCAT(nm, char(31)) FROM (SELECT MIN(s3.name) AS nm FROM tag_suggestions s3 WHERE s3.status = 'pending' GROUP BY s3.tag_type_id, lower(s3.name) ORDER BY COUNT(*) DESC, lower(s3.name) LIMIT 3)), '' FROM tag_suggestions s WHERE s.status = 'pending'
             UNION ALL
             SELECT '1new', COUNT(*), COALESCE(SUM(EXISTS (SELECT 1 FROM stories sn WHERE sn.author_id = users.id AND sn.deleted_at IS NULL)), 0), '', '', '' FROM users WHERE created_at >= strftime('%Y-%m-%dT%H:%M:%S', 'now', '-7 days')
             UNION ALL
             SELECT * FROM (SELECT '2log', actor_id, actor_name, action, detail, created_at FROM activity_log ORDER BY id DESC LIMIT 6)",
            [$userId]);
    }
}
