<?php // app/Features/Theme/ThemeController.php
namespace App\Features\Theme;
use Kip\{Database, Session};
use Kip\Http\{Request, Response};

final class ThemeController
{
    public function __construct(
        private Request $request,
        private Database $db,
        private Session $session,
    ) {}

    /** The legacy footer routes stay live until C6 drops their links; they map
     *  onto the value set at the door (light->paper, dark->night) so no legacy
     *  value lands in a user_prefs row or a cookie after migration 026. */
    public function light(): Response
    {
        return $this->switch('paper');
    }

    public function dark(): Response
    {
        return $this->switch('night');
    }

    private function switch(string $theme): Response
    {
        $to = \App\Redirects::safeReturn($this->request->get['return_to'] ?? '');
        // The cookie-gated $me (the StoryController idiom): cookieless requests
        // never open the lazy session, so the guest fast path stays exactly the
        // pre-12d bytes.
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        // The write-through (phase 12d ruling 5): a member's toggle persists the
        // pref beside the cookie, so the next login re-syncs a new device from
        // the row. The upsert, not a bare UPDATE, covers prefs-less members
        // (plan review finding 5). Guests and a flag-off archive stay
        // cookie-only: the stored pref governs nothing without its sync points.
        if ($me !== 0 && \App\Features::on('perusertheme')) {
            $this->db->query(
                'INSERT INTO user_prefs (user_id, theme) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET theme = excluded.theme',
                [$me, $theme]);
        }
        return Response::redirect($to)->withHeader(
            'Set-Cookie',
            \App\Theme::COOKIE . '=' . $theme . '; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax'
        );
    }
}
