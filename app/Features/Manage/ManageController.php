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
        'content' => 'manage.group_content',
        'members' => 'manage.group_members',
        'look' => 'manage.group_look',
        'settings' => 'manage.group_settings',
    ];

    /** Every owner screen: [group, address, language key for the label, optional feature flag that must be on]. */
    public const LINKS = [
        ['content', '/queue', 'manage.queue'],
        ['content', '/news', 'manage.news', 'news'],
        ['content', '/news/new', 'manage.news_post', 'news'],
        ['content', '/pages/manage', 'manage.pages'],
        ['content', '/page/new', 'manage.page_new'],
        ['content', '/images', 'manage.images'],
        ['content', '/categories', 'manage.categories'],
        ['content', '/ratings', 'manage.ratings'],
        ['content', '/tags', 'manage.tags'],
        ['content', '/characters', 'manage.characters'],
        ['content', '/wrangling', 'manage.wrangling', 'wrangling'],
        ['content', '/analytics', 'manage.analytics', 'analytics'],
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
    ];

    #[AuthAttr]
    public function index(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $groups = [];
        foreach (self::LINKS as $link) {
            if (isset($link[3]) && !\App\Features::on($link[3])) continue;
            $groups[$link[0]][] = ['url' => $link[1], 'label' => $link[2]];
        }
        return $this->page('manage/index', 'manage.heading', '/manage', [
            'groups' => $groups,
            'groupNames' => self::GROUPS,
            // The raw table editor: hidden when the archive turns it off (config admin.enabled).
            'database' => (bool) (((array) $this->app->config('admin', []))['enabled'] ?? true),
        ]);
    }
}
