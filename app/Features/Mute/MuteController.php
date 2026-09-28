<?php // app/Features/Mute/MuteController.php
namespace App\Features\Mute;
use Kip\{Database, Http\Response, Session};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\MuteRepository;
use App\Repositories\UserRepository;

/** The author-mute toggles. Resolution is by PROFILE_SLUG through
 *  findByProfileSlug, the UserController::contact idiom: it already enforces
 *  is_locked = 0 and penname IS NOT NULL, and the router's segment
 *  whitelist matches its slug regex. A typed-penname COLLATE NOCASE lookup
 *  is the wrong tool here (plan review finding 1): pennames carry spaces
 *  and case, so it would 404 the archive's own seeded slugs. Muting is
 *  silent by contract: no notification kind, no indicator, ever. */
final class MuteController
{
    public function __construct(private Database $db, private Session $session) {}

    #[AuthAttr] #[Post]
    public function add(string $slug): Response
    {
        if (($r = \App\Features::guard('mute')) !== null) return $r;
        return $this->toggle($slug, true);
    }

    #[AuthAttr] #[Post]
    public function remove(string $slug): Response
    {
        if (($r = \App\Features::guard('mute')) !== null) return $r;
        return $this->toggle($slug, false);
    }

    /** Shared shape: unknown or locked member 404s exactly like the profile
     *  page would, self-mute 404s (the listing filter is for OTHER
     *  authors), and the redirect lands back on the profile the button
     *  rode, so the member keeps their place. */
    private function toggle(string $slug, bool $on): Response
    {
        $me = (int) $this->session->get('user_id');
        $target = (new UserRepository($this->db))->findByProfileSlug($slug);
        if ($target === null || (int) $target['id'] === $me) {
            return new Response('Page not found', 404);
        }
        (new MuteRepository($this->db))->toggle($me, (int) $target['id'], $on);
        return Response::redirect('/user/view/' . $slug);
    }
}
