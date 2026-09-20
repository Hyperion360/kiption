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
                    (SELECT p.notify_favorite_digest FROM user_prefs p WHERE p.user_id = u.id) AS f,
                    u.bio AS g, CAST(u.is_beta AS TEXT) AS h,
                    (SELECT p2.default_sort FROM user_prefs p2 WHERE p2.user_id = u.id) AS i,
                    COALESCE((SELECT p3.notify_review FROM user_prefs p3 WHERE p3.user_id = u.id), 1) AS j,
                    COALESCE((SELECT p4.notify_response FROM user_prefs p4 WHERE p4.user_id = u.id), 1) AS l,
                    COALESCE((SELECT p5.notify_favorites FROM user_prefs p5 WHERE p5.user_id = u.id), 1) AS m
             FROM users u WHERE u.id = ?
             UNION ALL
             SELECT '1follow', CAST(f.author_id AS TEXT), u2.penname, f.notify_mode,
                     CAST((SELECT COUNT(*) FROM stories s WHERE s.author_id = f.author_id AND s.deleted_at IS NULL AND s.validated = 1) AS TEXT), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL
             FROM follows f JOIN users u2 ON u2.id = f.author_id WHERE f.follower_id = ?
             UNION ALL
             SELECT '2progress', s.slug, s.title, CAST(rh.last_position AS TEXT),
                     CAST((SELECT COUNT(*) FROM chapters c WHERE c.story_id = s.id AND c.validated = 1) AS TEXT), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL
             FROM reading_history rh JOIN stories s ON s.id = rh.story_id
             WHERE rh.user_id = ? AND rh.marked_at IS NULL AND s.deleted_at IS NULL
             UNION ALL
             SELECT '3marked', s2.slug, s2.title, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL
             FROM reading_history rh2 JOIN stories s2 ON s2.id = rh2.story_id
             WHERE rh2.user_id = ? AND rh2.marked_at IS NOT NULL AND s2.deleted_at IS NULL
             UNION ALL
             SELECT '4story', s.slug, s.title, CAST(s.validated AS TEXT), NULL, s.updated_at, NULL, NULL, NULL, NULL, NULL, NULL, NULL
             FROM stories s WHERE s.author_id = ? AND s.deleted_at IS NULL
             UNION ALL
             SELECT '5series' k, ser.slug a, ser.title b,
                    CAST((SELECT COUNT(*) FROM series_items si JOIN stories st ON st.id = si.story_id
                          WHERE si.series_id = ser.id AND si.confirmed = 1 AND st.deleted_at IS NULL) AS TEXT) c,
                    CAST((SELECT COUNT(*) FROM series_items si JOIN stories st ON st.id = si.story_id
                          WHERE si.series_id = ser.id AND si.confirmed = 0 AND st.deleted_at IS NULL) AS TEXT) d,
                    ser.membership e, NULL f, NULL g, NULL h, NULL i, NULL j, NULL l, NULL m
             FROM series ser WHERE ser.owner_id = ?
             ORDER BY k, e LIMIT 250", [$userId, $userId, $userId, $userId, $userId, $userId]);
        // New alias note: the notify toggles are j/l/m because k is already the
        // branch discriminator; all three COALESCE to 1 so a prefs-less member
        // fails safe ON, the same contract notifyRecipients enforces at send time.
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
        if (($me['i'] ?? null) === 'alpha') {
            // default_sort is a PHP-side concern here: the fold's shared ORDER BY
            // cannot vary per branch, so the member's own list re-sorts in place.
            usort($stories, static fn(array $x, array $y): int => strcasecmp((string) $x['b'], (string) $y['b']));
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
            'tocOn' => ($this->request->cookies['toc'] ?? '') === '1',
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
        $me = (int) $this->session->get('user_id');
        $bio = substr(trim($this->request->postStr('bio')), 0, 2000);
        $isBeta = isset($this->request->post['is_beta']) ? 1 : 0;
        $sort = $this->request->postStr('default_sort') === 'alpha' ? 'alpha' : 'recent';
        $toc = isset($this->request->post['toc_first']) ? '1' : '0';
        // Value-based, not isset-based: the form's checkboxes submit no field when
        // unchecked, and the value is the truth ('0' stays off, only '1' is on).
        $on = fn (string $k): int => (int) ($this->request->post[$k] ?? 0) === 1 ? 1 : 0;
        $this->db->begin();
        try {
            $this->db->query('UPDATE users SET bio = ?, is_beta = ? WHERE id = ?', [$bio, $isBeta, $me]);
            // Upsert, NOT a bare UPDATE (plan review finding 5): seeder-era and
            // user:create members carry no user_prefs row; a plain UPDATE would
            // silently drop their choice (the exact bug the 6b QA pass fixed once).
            $this->db->query('INSERT INTO user_prefs (user_id, default_sort, toc_first, notify_review, notify_response, notify_favorites, notify_favorite_digest)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(user_id) DO UPDATE SET default_sort = excluded.default_sort, toc_first = excluded.toc_first,
                    notify_review = excluded.notify_review, notify_response = excluded.notify_response,
                    notify_favorites = excluded.notify_favorites, notify_favorite_digest = excluded.notify_favorite_digest',
                [$me, $sort, (int) $toc, $on('notify_review'), $on('notify_response'), $on('notify_favorites'), $on('notify_favorite_digest')]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        $this->purgeOwnProfile($me);
        // the beta badge flips directory membership too, not just the profile card
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeAuthors();
        // Response has no cookie helper (finding 11); mirror ThemeController's
        // Set-Cookie bytes exactly.
        return Response::redirect('/account')->withHeader('Set-Cookie', $toc === '1'
            ? 'toc=1; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax'
            : 'toc=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax');
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
