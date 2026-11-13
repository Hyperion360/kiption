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
        if ($r = $this->deny()) return $r;
        $groups = [];
        foreach (self::LINKS as $link) {
            if (isset($link[3]) && !\App\Features::on($link[3])) continue;
            $groups[$link[0]][] = ['url' => $link[1], 'label' => $link[2]];
        }
        // The raw table editor: hidden when the archive turns it off (config admin.enabled).
        $database = (bool) (((array) $this->app->config('admin', []))['enabled'] ?? true);
        return $this->page('manage/index', 'manage.heading', '/manage', [
            'groups' => $groups,
            'groupNames' => self::GROUPS,
            'database' => $database,
            'adminDatabase' => $database, // the sidebar offers the editor on the same terms
        ]);
    }
}
