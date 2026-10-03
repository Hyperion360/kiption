<?php // app/Features/Favorites/FavoritesController.php
namespace App\Features\Favorites;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Notifications;
use App\Repositories\EngagementRepository;
use App\Repositories\UserRepository;

final class FavoritesController
{
    public function __construct(
        private View $view, private Database $db, private Request $request,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): string
    {
        $rows = (new EngagementRepository($this->db))->favoritesRows((int) $this->session->get('user_id'));
        return $this->view->render('favorites/index', [
            'title' => \App\Lang::t('favorites.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('favorites.heading'))->withCanonical('/favorites')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $rows,
            'loggedIn' => true,
        ]);
    }

    #[AuthAttr] #[Post]
    public function toggle(string $slug): Response
    {
        $userId = (int) $this->session->get('user_id');
        [$added, $authorId, $title] = (new EngagementRepository($this->db))->toggleFavorite($slug, $userId);
        if ($authorId === 0) return new Response('Page not found', 404);
        if ($added) {
            $storyId = (int) $this->db->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'];
            $notifications = new Notifications($this->db);
            foreach ((new UserRepository($this->db))->notifyRecipients($storyId, 'notify_favorites', $userId) as $recipientId) {
                $notifications->create($recipientId, 'favorite', $storyId, $userId, $title);
            }
        }
        // the anonymous page shows the favorite count: refresh it on either direction;
        // the favoriter's public shelf tab changed too
        $cache = \App\StaticCache\Cache::configured($this->app);
        $cache->purgeStory($slug, []);
        $favoriter = $this->db->one('SELECT profile_slug FROM users WHERE id = ?', [$userId]);
        if ($favoriter !== null) $cache->purgeUser((string) $favoriter['profile_slug']);
        return Response::redirect('/story/view/' . $slug);
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }
}
