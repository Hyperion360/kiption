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
        $waiting = ['stories' => 0, 'chapters' => 0, 'members' => 0, 'reports' => 0, 'new' => 0];
        $recent = [];
        $admin = false;
        foreach ($this->homeRows($uid) as $row) {
            if ($row['k'] === '0gate') $admin = true;
            elseif ($row['k'] === '2log') $recent[] = $row;
            else $waiting[substr($row['k'], 1)] = (int) $row['n'];
        }
        if (!$admin) return new Response(\App\Lang::t('error.forbidden'), 403);
        $groups = [];
        foreach (self::LINKS as $link) {
            if (isset($link[3]) && !\App\Features::on($link[3])) continue;
            $groups[$link[0]][] = ['url' => $link[1], 'label' => $link[2]];
        }
        // The raw table editor: hidden when the archive turns it off (config admin.enabled).
        $database = (bool) (((array) $this->app->config('admin', []))['enabled'] ?? true);
        return $this->page('manage/index', 'manage.heading', '/manage', [
            'waiting' => $waiting,
            'recent' => $recent,
            'groups' => $groups,
            'groupNames' => self::GROUPS,
            'database' => $database,
            'adminDatabase' => $database, // the sidebar offers the editor on the same terms
        ]);
    }

    /** One statement for the whole home screen: the admin gate row, the
     *  counts of what is waiting (the same conditions the review queue lists
     *  by), new members in the last seven days, and the newest log lines.
     *  @return list<array<string,mixed>> */
    private function homeRows(int $userId): array
    {
        return $this->db->all(
            "SELECT '0gate' AS k, u.id AS n, '' AS a, '' AS b, '' AS c, '' AS d FROM users u WHERE u.id = ? AND u.role = 'admin'
             UNION ALL
             SELECT '1stories', COUNT(*), '', '', '', '' FROM stories s WHERE " . \App\Repositories\AuthoringRepository::WAITING_STORY . "
             UNION ALL
             SELECT '1chapters', COUNT(*), '', '', '', '' FROM chapters ch JOIN stories s ON s.id = ch.story_id
             WHERE " . \App\Repositories\AuthoringRepository::WAITING_CHAPTER . "
             UNION ALL
             SELECT '1members', COUNT(*), '', '', '', '' FROM users us WHERE " . \App\Repositories\AuthoringRepository::WAITING_MEMBER . "
             UNION ALL
             SELECT '1reports', COUNT(*), '', '', '', '' FROM reports WHERE resolved_at IS NULL
             UNION ALL
             SELECT '1new', COUNT(*), '', '', '', '' FROM users WHERE created_at >= strftime('%Y-%m-%dT%H:%M:%S', 'now', '-7 days')
             UNION ALL
             SELECT * FROM (SELECT '2log', id, actor_name, action, detail, created_at FROM activity_log ORDER BY id DESC LIMIT 6)",
            [$userId]);
    }
}
