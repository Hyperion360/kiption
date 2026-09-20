<?php // app/src/Controllers/ReportController.php
namespace App\Controllers;
use Kip\{Database, Http\Request, Http\Response, Session};
use Kip\Routing\{Auth as AuthAttr, Post}; // the aliased Auth import ships FROM BIRTH: without it #[AuthAttr] resolves to a nonexistent class, PHP never validates attributes, and the member gate silently disables
use App\Adminness;
use App\Repositories\ReportRepository;

final class ReportController
{
    public function __construct(private Database $db, private Request $request, private Session $session) {}

    #[AuthAttr] #[Post]
    public function story(string $slug): Response
    {
        $userId = $this->session->get('user_id');
        [$result] = (new ReportRepository($this->db))->reportStory(
            $slug, is_int($userId) ? $userId : null, $this->request->postStr('reason'), $this->request->ip);
        return $this->outcome($result, $slug);
    }

    #[AuthAttr] #[Post]
    public function review(string $id): Response
    {
        $userId = $this->session->get('user_id');
        [$result] = (new ReportRepository($this->db))->reportReview(
            (int) $id, is_int($userId) ? $userId : null, $this->request->postStr('reason'));
        if ($result !== true) return $this->outcome($result, '');
        $slug = (string) ($this->db->one(
            'SELECT s.slug FROM reviews r JOIN stories s ON s.id = r.story_id WHERE r.id = ?', [(int) $id])['slug'] ?? '');
        return $this->outcome($result, $slug);
    }

    #[AuthAttr] #[Post]
    public function resolve(string $id, string $op): Response
    {
        if (Adminness::requireModerator($this->db, $this->session) instanceof Response) {
            return new Response('Forbidden', 403);
        }
        if ($op === 'dismiss') {
            (new ReportRepository($this->db))->resolve((int) $id);
        }
        return Response::redirect('/queue');
    }

    private function outcome(bool|string $result, string $slug): Response
    {
        if ($result === 'notfound') return new Response('Page not found', 404);
        if ($result === 'empty') return new Response('Reason is required (max 500 characters).', 422);
        if ($result === 'duplicate') return new Response('You already have an open report for this.', 409);
        return Response::redirect('/story/view/' . $slug);
    }
}
