<?php // app/Features/Manage/ManageController.php
namespace App\Features\Manage;
use App\Adminness;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\Auth as AuthAttr;

/** /manage, "Archive admin": the one page that lists every owner screen in
 *  plain groups. Adding a screen is one line in LINKS. */
final class ManageController
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
        ['look', '/text/group/msg', 'manage.messages'],
        ['look', '/text', 'manage.site_text'],
        ['look', '/nav', 'manage.nav'],
        ['look', '/templates', 'manage.templates'],
        ['settings', '/settings', 'manage.settings'],
        ['settings', '/features', 'manage.features'],
        ['settings', '/wordfilter', 'manage.wordfilter'],
    ];

    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $groups = [];
        foreach (self::LINKS as $link) {
            if (isset($link[3]) && !\App\Features::on($link[3])) continue;
            $groups[$link[0]][] = ['url' => $link[1], 'label' => $link[2]];
        }
        $title = \App\Lang::t('manage.heading');
        return $this->view->render('manage/index', [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical('/manage')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'groups' => $groups,
            'groupNames' => self::GROUPS,
            // The raw table editor: hidden when the archive turns it off (config admin.enabled).
            'database' => (bool) (((array) $this->app->config('admin', []))['enabled'] ?? true),
            'loggedIn' => true,
            'isAdmin' => true, // the gate above already proved it
        ]);
    }
}
