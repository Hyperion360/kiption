<?php // app/src/Controllers/FavoritesController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Notifications;
use App\Repositories\EngagementRepository;

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
            'title' => 'Your favorites',
            'head' => $this->head()->withTitle('Your favorites')->withCanonical('/favorites')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
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
        if ($added && $authorId !== $userId) {
            (new Notifications($this->db))->create($authorId, 'favorite',
                (int) $this->db->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'], $userId, $title);
        }
        // the anonymous page shows the favorite count: refresh it on either direction
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeStory($slug, []);
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
