<?php // app/Features/Review/ReviewController.php
namespace App\Features\Review;
use Kip\{Database, Http\Request, Http\Response, Session};
use Kip\Routing\{Auth as AuthAttr, Post}; // the aliased Auth import ships FROM BIRTH: without it #[AuthAttr] resolves to a nonexistent class, PHP never validates attributes, and the member gate silently disables (OV finding 4)
use App\Notifications;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;

final class ReviewController
{
    public function __construct(private Database $db, private Request $request, private Session $session) {}

    #[Post]
    public function add(string $slug): Response
    {
        $userId = $this->session->get('user_id');
        $raw = $this->request->postStr('rating');
        $rating = $raw === '' ? null : max(0, min(10, (int) $raw));
        [$added, $authorId, $title, $error] = (new ReviewRepository($this->db))->addReview(
            $slug, is_int($userId) ? $userId : null,
            $userId === null ? $this->request->postStr('guest_name') : null,
            $this->request->postStr('body'), $rating, $this->request->ip);
        if ($authorId === 0 && $error === null) return new Response('Page not found', 404);
        if ($error !== null) {
            $status = str_starts_with($error, 'You already reviewed') ? 429 : 422;
            return new Response($error, $status);
        }
        if ($added) {
            $storyId = (int) $this->db->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'];
            $notifications = new Notifications($this->db);
            foreach ((new UserRepository($this->db))->notifyRecipients($storyId, 'notify_review', is_int($userId) ? $userId : 0) as $recipientId) {
                $notifications->create($recipientId, 'review', $storyId, is_int($userId) ? $userId : null, $title);
            }
            (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeStory($slug, []);
        }
        return Response::redirect('/story/view/' . $slug . '#reviews');
    }

    #[AuthAttr] #[Post]
    public function reply(string $id): Response
    {
        $userId = (int) $this->session->get('user_id');
        [$added, $notifyUserId, $storyId, $slug, $error] = (new ReviewRepository($this->db))
            ->addReply((int) $id, $userId, $this->request->postStr('body'));
        if ($error === 'not found') return new Response('Page not found', 404);
        if ($error !== null) return new Response($error, 422);
        if ($notifyUserId !== 0 && $notifyUserId !== $userId) {
            // the reply path keeps its own one-query recipient lookup: the parent
            // review's author, gated by their notify_response pref (missing row = ON)
            $pref = $this->db->one(
                'SELECT ru.id, COALESCE(p.notify_response, 1) on_ FROM reviews r
                 JOIN users ru ON ru.id = r.user_id
                 LEFT JOIN user_prefs p ON p.user_id = ru.id WHERE r.id = ?', [(int) $id]);
            if ($pref === null || (int) $pref['on_'] === 1) {
                (new Notifications($this->db))->create($notifyUserId, 'reply', $storyId, $userId, null);
            }
        }
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeStory($slug, []);
        return Response::redirect('/story/view/' . $slug . '#reviews');
    }
}
