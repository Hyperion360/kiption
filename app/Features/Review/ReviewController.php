<?php // app/Features/Review/ReviewController.php
namespace App\Features\Review;
use Kip\{Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post}; // the aliased Auth import ships FROM BIRTH: without it #[AuthAttr] resolves to a nonexistent class, PHP never validates attributes, and the member gate silently disables (OV finding 4)
use App\Notifications;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;

final class ReviewController
{
    public function __construct(private Database $db, private Request $request, private Session $session, private \Kip\App $app, private View $view) {}

    #[Post]
    public function add(string $slug): Response
    {
        // the review is attributed to the validated viewer: a session revoked
        // by a password change writes as a guest (App\Viewer, the read-side
        // doctrine's write twin), never as the victim
        $me = \App\Viewer::id($this->request, $this->session, $this->db);
        $userId = $me !== 0 ? $me : null;
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
            (\App\StaticCache\Cache::configured($this->app))->purgeStory($slug, []);
        }
        return Response::redirect('/story/view/' . $slug . '#reviews');
    }

    /** The chapter comment thread (GET): a separate surface from the story
     *  review feed, scoped to one validated chapter. The restricted gate
     *  rides the story lookup itself (the CAST pattern every read surface
     *  uses), so a guest 404 here is byte-identical to the unknown-slug
     *  404: no chapter-position oracle (R1, binding decision 3). */
    public function chapter(string $slug, string $position): Response|string
    {
        $pos = (int) $position;
        $me = \App\Viewer::id($this->request, $this->session, $this->db);
        $story = $this->db->one(
            'SELECT s.id, s.title, s.author_id FROM stories s
             WHERE s.slug = ? AND s.deleted_at IS NULL AND s.validated = 1
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)', [$slug, $me]);
        if ($story === null || $pos < 1
            || $this->db->one('SELECT id FROM chapters WHERE story_id = ? AND position = ? AND validated = 1', [(int) $story['id'], $pos]) === null) {
            return new Response('Page not found', 404);
        }
        $rows = (new ReviewRepository($this->db))->chapterReviews((int) $story['id'], $pos);
        $title = \App\Lang::t('review.chapter_heading', ['n' => $pos]);
        return $this->view->render('review/chapter', ['title' => $title,
            'head' => $this->head()->withTitle($title)->withNoindex(), 'theme' => \App\Theme::current($this->request),
            'request' => $this->request, 'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path, 'story' => $story, 'slug' => $slug, 'position' => $pos,
            'rows' => $rows, 'me' => $me, 'csrf' => $me !== 0 ? $this->session->csrfToken() : '', 'loggedIn' => $me !== 0]);
    }

    /** The chapter comment write path (POST): error codes mirror add()'s
     *  (404 unknown, 429 daily cap, 422 validation), and the notification
     *  carries the story title exactly as add()'s does (R9). */
    #[Post]
    public function addchapter(string $slug, string $position): Response
    {
        $me = \App\Viewer::id($this->request, $this->session, $this->db);
        [$added, $authorId, $error] = (new ReviewRepository($this->db))->addChapterReview(
            $slug, (int) $position, $me !== 0 ? $me : null,
            $me === 0 ? $this->request->postStr('guest_name') : null,
            $this->request->postStr('body'), $this->request->ip);
        if ($error === 'not found') return new Response('Page not found', 404);
        if ($error !== null) {
            return new Response($error, str_starts_with($error, 'You already reviewed') ? 429 : 422);
        }
        if ($added) {
            $row = $this->db->one('SELECT id, title FROM stories WHERE slug = ?', [$slug]);
            foreach ((new UserRepository($this->db))->notifyRecipients((int) $row['id'], 'notify_review', $me) as $recipientId) {
                (new Notifications($this->db))->create($recipientId, 'review', (int) $row['id'], $me !== 0 ? $me : null, (string) $row['title']);
            }
            (\App\StaticCache\Cache::configured($this->app))->purgeStory($slug, []);
        }
        return Response::redirect('/review/chapter/' . $slug . '/' . (int) $position);
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
        (\App\StaticCache\Cache::configured($this->app))->purgeStory($slug, []);
        return Response::redirect('/story/view/' . $slug . '#reviews');
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
