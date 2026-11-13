<?php // app/Features/Follow/FollowController.php
namespace App\Features\Follow;
use Kip\{Database, Http\Response, Session};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Notifications;
use App\Repositories\EngagementRepository;

final class FollowController
{
    public function __construct(private Database $db, private Session $session) {}

    #[AuthAttr] #[Post]
    public function author(string $id): Response
    {
        $follower = (int) $this->session->get('user_id');
        $authorId = (int) $id;
        [$inserted, $authorId] = (new EngagementRepository($this->db))->addFollow($follower, $authorId);
        if ($authorId === 0) return new Response(\App\Lang::t('error.not_found'), 404);
        if ($inserted) \App\ActivityLog::by($this->db, $this->session, 'follows.add', 'member ' . $authorId);
        if ($inserted && $this->db->one(
                "SELECT 1 AS x FROM notifications WHERE user_id = ? AND actor_id = ? AND kind = 'follow'",
                [$authorId, $follower]) === null) {
            (new Notifications($this->db))->create($authorId, 'follow', null, $follower, null);
        }
        return Response::redirect('/account');
    }

    #[AuthAttr] #[Post]
    public function mode(string $id): Response
    {
        (new EngagementRepository($this->db))->cycleNotifyMode((int) $this->session->get('user_id'), (int) $id);
        \App\ActivityLog::by($this->db, $this->session, 'follows.mode', 'member ' . (int) $id);
        return Response::redirect('/account');
    }

    #[AuthAttr] #[Post]
    public function stop(string $id): Response
    {
        (new EngagementRepository($this->db))->unfollow((int) $this->session->get('user_id'), (int) $id);
        \App\ActivityLog::by($this->db, $this->session, 'follows.remove', 'member ' . (int) $id);
        return Response::redirect('/account');
    }
}
