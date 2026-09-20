<?php // app/src/Controllers/AccountController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, Storage, View};
use Kip\Routing\{Auth as AuthAttr, Post};

final class AccountController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Database $db,
        private Session $session,
        private App $app,
        private Storage $storage,
    ) {}

    #[AuthAttr]
    public function index(): string
    {
        $userId = (int) $this->session->get('user_id');
        $rows = $this->db->all(
            "SELECT '0me' AS k, u.penname AS a, u.email AS b, u.role AS c, u.avatar_path AS d, u.support_url AS e,
                    (SELECT p.notify_favorite_digest FROM user_prefs p WHERE p.user_id = u.id) AS f
             FROM users u WHERE u.id = ?
             UNION ALL
             SELECT '1follow', CAST(f.author_id AS TEXT), u2.penname, f.notify_mode,
                     CAST((SELECT COUNT(*) FROM stories s WHERE s.author_id = f.author_id AND s.deleted_at IS NULL AND s.validated = 1) AS TEXT), NULL, NULL
             FROM follows f JOIN users u2 ON u2.id = f.author_id WHERE f.follower_id = ?
             UNION ALL
             SELECT '2progress', s.slug, s.title, CAST(rh.last_position AS TEXT),
                     CAST((SELECT COUNT(*) FROM chapters c WHERE c.story_id = s.id AND c.validated = 1) AS TEXT), NULL, NULL
             FROM reading_history rh JOIN stories s ON s.id = rh.story_id
             WHERE rh.user_id = ? AND rh.marked_at IS NULL AND s.deleted_at IS NULL
             UNION ALL
             SELECT '3marked', s2.slug, s2.title, NULL, NULL, NULL, NULL
             FROM reading_history rh2 JOIN stories s2 ON s2.id = rh2.story_id
             WHERE rh2.user_id = ? AND rh2.marked_at IS NOT NULL AND s2.deleted_at IS NULL
             UNION ALL
             SELECT '4story', s.slug, s.title, CAST(s.validated AS TEXT), NULL, s.updated_at, NULL
             FROM stories s WHERE s.author_id = ? AND s.deleted_at IS NULL
             UNION ALL
             SELECT '5series' k, ser.slug a, ser.title b,
                    CAST((SELECT COUNT(*) FROM series_items si WHERE si.series_id = ser.id AND si.confirmed = 1) AS TEXT) c,
                    CAST((SELECT COUNT(*) FROM series_items si WHERE si.series_id = ser.id AND si.confirmed = 0) AS TEXT) d,
                    ser.membership e, NULL f
             FROM series ser WHERE ser.owner_id = ?
             ORDER BY k, e LIMIT 250", [$userId, $userId, $userId, $userId, $userId, $userId]);
        $me = null;
        $stories = [];
        $seriesList = [];
        $following = [];
        $progress = [];
        $marked = [];
        foreach ($rows as $r) {
            if ($r['k'] === '0me') { $me = $r; }
            elseif ($r['k'] === '1follow') { $following[] = $r; }
            elseif ($r['k'] === '2progress') { $progress[] = $r; }
            elseif ($r['k'] === '3marked') { $marked[] = $r; }
            elseif ($r['k'] === '5series') { $seriesList[] = $r; }
            else { $stories[] = $r; }
        }
        return $this->view->render('account/show', [
            'title' => 'Your account',
            'head' => $this->head()->withTitle('Your account')->withCanonical('/account')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'me' => $me,
            'stories' => $stories,
            'seriesList' => $seriesList,
            'following' => $following,
            'progress' => $progress,
            'marked' => $marked,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
        ]);
    }

    #[AuthAttr] #[Post]
    public function avatar(): Response
    {
        $file = $this->request->file('avatar');
        if ($file === null) {
            return new Response('Avatar rejected: choose a PNG, JPG, WEBP, or GIF under 2 MiB.', 422);
        }
        try {
            $path = $this->storage->put($file);
        } catch (\Kip\UploadException) {
            return new Response('Avatar rejected: choose a PNG, JPG, WEBP, or GIF under 2 MiB.', 422);
        }
        $userId = (int) $this->session->get('user_id');
        $old = $this->db->one('SELECT avatar_path FROM users WHERE id = ?', [$userId]);
        $this->db->query('UPDATE users SET avatar_path = ? WHERE id = ?', [$path, $userId]);
        if ($old !== null && $old['avatar_path'] !== null) {
            // basename() keeps the delete inside the configured uploads dir.
            $dir = rtrim((string) ($this->app->config('uploads')['dir'] ?? dirname(__DIR__, 3) . '/public/uploads'), '/');
            @unlink($dir . '/' . basename((string) $old['avatar_path']));
        }
        $this->purgeOwnProfile($userId);
        return Response::redirect('/account');
    }

    #[AuthAttr] #[Post]
    public function support(): Response
    {
        $url = trim($this->request->postStr('support_url'));
        // The form's maxlength=200 is client-side only; the server enforces both rules.
        if ($url !== '' && (strlen($url) > 200 || !preg_match('#^https?://#', $url))) {
            return new Response('Support link must start with http:// or https:// (max 200 characters).', 422);
        }
        $userId = (int) $this->session->get('user_id');
        $this->db->query('UPDATE users SET support_url = ? WHERE id = ?', [$url === '' ? null : $url, $userId]);
        $this->purgeOwnProfile($userId);
        return Response::redirect('/account');
    }

    #[AuthAttr] #[Post]
    public function prefs(): Response
    {
        // Upsert: seeder-era and user:create members carry no user_prefs row, and a
        // plain UPDATE would silently drop their choice (the form says saved either way).
        $on = isset($this->request->post['notify_favorite_digest']) ? 1 : 0;
        $userId = (int) $this->session->get('user_id');
        $this->db->query(
            'INSERT INTO user_prefs (user_id, notify_favorite_digest) VALUES (?, ?)
             ON CONFLICT(user_id) DO UPDATE SET notify_favorite_digest = excluded.notify_favorite_digest',
            [$userId, $on]);
        $this->purgeOwnProfile($userId);
        return Response::redirect('/account');
    }

    /** Any account-side change (avatar, support link, prefs) refreshes the
     *  member's three public profile pages. */
    private function purgeOwnProfile(int $userId): void
    {
        $slug = $this->db->one('SELECT profile_slug FROM users WHERE id = ?', [$userId]);
        if ($slug === null) return;
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeUser((string) $slug['profile_slug']);
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
