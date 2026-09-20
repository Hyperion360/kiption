<?php // app/src/Controllers/ReviewController.php
namespace App\Controllers;
use Kip\{Database, Http\Request, Http\Response, Session};
use Kip\Routing\{Auth as AuthAttr, Post}; // the aliased Auth import ships FROM BIRTH: without it #[AuthAttr] resolves to a nonexistent class, PHP never validates attributes, and the member gate silently disables (OV finding 4)
use App\Notifications;
use App\Repositories\ReviewRepository;

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
        if ($added && (!is_int($userId) || $userId !== $authorId)) {
            (new Notifications($this->db))->create($authorId, 'review',
                (int) $this->db->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'],
                is_int($userId) ? $userId : null, $title);
        }
        if ($added) {
            (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeStory($slug, []);
        }
        return Response::redirect('/story/view/' . $slug . '#reviews');
    }
}
