<?php // app/src/Controllers/KudosController.php
namespace App\Controllers;
use Kip\{Database, Http\Request, Http\Response, Session};
use Kip\Routing\Post;
use App\Notifications;
use App\Repositories\EngagementRepository;

final class KudosController
{
    public function __construct(
        private Database $db, private Request $request, private Session $session,
    ) {}

    #[Post]
    public function add(string $slug): Response
    {
        $userId = $this->session->get('user_id');
        [$inserted, $authorId, $title] = (new EngagementRepository($this->db))
            ->addKudos($slug, is_int($userId) ? $userId : null, $this->request->ip);
        if ($authorId === 0) return new Response('Page not found', 404);
        if ($inserted && (!is_int($userId) || $userId !== $authorId)) { // no self-congratulation
            (new Notifications($this->db))->create($authorId, 'kudos',
                (int) $this->db->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'],
                is_int($userId) ? $userId : null, $title);
        }
        if ($inserted) { // the anonymous page shows the count: refresh it
            (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeStory($slug, []);
        }
        return Response::redirect('/story/view/' . $slug);
    }
}
