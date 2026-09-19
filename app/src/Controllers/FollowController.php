<?php // app/src/Controllers/FollowController.php
namespace App\Controllers;
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
        [$inserted,] = (new EngagementRepository($this->db))->addFollow($follower, $authorId);
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
        return Response::redirect('/account');
    }

    #[AuthAttr] #[Post]
    public function stop(string $id): Response
    {
        (new EngagementRepository($this->db))->unfollow((int) $this->session->get('user_id'), (int) $id);
        return Response::redirect('/account');
    }
}
