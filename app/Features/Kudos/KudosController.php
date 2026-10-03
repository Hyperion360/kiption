<?php // app/Features/Kudos/KudosController.php
namespace App\Features\Kudos;
use Kip\{Database, Http\Request, Http\Response, Session};
use Kip\Routing\Post;
use App\Notifications;
use App\Repositories\EngagementRepository;
use App\Repositories\UserRepository;

final class KudosController
{
    public function __construct(
        private Database $db, private Request $request, private Session $session,
        private \Kip\App $app, // the configured static cache for the count purge
    ) {}

    #[Post]
    public function add(string $slug): Response
    {
        $userId = $this->session->get('user_id');
        [$inserted, $authorId, $title] = (new EngagementRepository($this->db))
            ->addKudos($slug, is_int($userId) ? $userId : null, $this->request->ip);
        if ($authorId === 0) return new Response('Page not found', 404);
        if ($inserted) {
            $storyId = (int) $this->db->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'];
            $notifications = new Notifications($this->db);
            // kudos has no pref column: the gate is null, the actor is still excluded
            foreach ((new UserRepository($this->db))->notifyRecipients($storyId, null, is_int($userId) ? $userId : 0) as $recipientId) {
                $notifications->create($recipientId, 'kudos', $storyId, is_int($userId) ? $userId : null, $title);
            }
            // the anonymous page shows the count: refresh it
            (\App\StaticCache\Cache::configured($this->app))->purgeStory($slug, []);
        }
        return Response::redirect('/story/view/' . $slug);
    }
}
