<?php // app/Features/Account/AccountController.php
namespace App\Features\Account;
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
        // The muted-authors block folds into the compound as a seventh branch
        // (the 12b precedent for adding branches), so the page keeps its
        // one-content-query budget with the mute flag on instead of growing
        // the contract's first read-side exception row. The branch rides ONLY
        // while the flag is on: a mute-off archive runs the exact pre-mute
        // six-branch statement. The branch key is '0mu', right after '0me',
        // so under the shared LIMIT 250 the block outranks the big lists: the
        // unmute affordance lives nowhere else, while follows, stories, and
        // series all keep their own direct pages. Position e repeats the
        // penname because the compound's ORDER BY sorts bare output aliases
        // only; that gives the branch a deterministic penname order.
        $mutedBranch = \App\Features::on('mute') ? "
             UNION ALL
             SELECT '0mu' AS k, u3.profile_slug AS a, u3.penname AS b, NULL AS c, NULL AS d, u3.penname AS e,
                    NULL AS f, NULL AS g, NULL AS h, NULL AS i, NULL AS j, NULL AS l, NULL AS m, NULL AS n, NULL AS o
             FROM muted mu2 JOIN users u3 ON u3.id = mu2.author_id WHERE mu2.user_id = ?" : '';
        $binds = [$userId, $userId, $userId, $userId, $userId, $userId];
        if ($mutedBranch !== '') $binds[] = $userId;
        $rows = $this->db->all(
            "SELECT '0me' AS k, u.penname AS a, u.email AS b, u.role AS c, u.avatar_path AS d, u.support_url AS e,
                    (SELECT p.notify_favorite_digest FROM user_prefs p WHERE p.user_id = u.id) AS f,
                    u.bio AS g, CAST(u.is_beta AS TEXT) AS h,
                    (SELECT p2.default_sort FROM user_prefs p2 WHERE p2.user_id = u.id) AS i,
                    COALESCE((SELECT p3.notify_review FROM user_prefs p3 WHERE p3.user_id = u.id), 1) AS j,
                    COALESCE((SELECT p4.notify_response FROM user_prefs p4 WHERE p4.user_id = u.id), 1) AS l,
                    COALESCE((SELECT p5.notify_favorites FROM user_prefs p5 WHERE p5.user_id = u.id), 1) AS m,
                    (SELECT p6.lang FROM user_prefs p6 WHERE p6.user_id = u.id) AS n,
                    (SELECT p7.theme FROM user_prefs p7 WHERE p7.user_id = u.id) AS o
             FROM users u WHERE u.id = ?
             UNION ALL
             SELECT '1follow', CAST(f.author_id AS TEXT), u2.penname, f.notify_mode,
                     CAST((SELECT COUNT(*) FROM stories s WHERE s.author_id = f.author_id AND s.deleted_at IS NULL AND s.validated = 1) AS TEXT), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL
             FROM follows f JOIN users u2 ON u2.id = f.author_id WHERE f.follower_id = ?
             UNION ALL
             SELECT '2progress', s.slug, s.title, CAST(rh.last_position AS TEXT),
                     CAST((SELECT COUNT(*) FROM chapters c WHERE c.story_id = s.id AND c.validated = 1) AS TEXT), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL
             FROM reading_history rh JOIN stories s ON s.id = rh.story_id
             WHERE rh.user_id = ? AND rh.marked_at IS NULL AND s.deleted_at IS NULL
             UNION ALL
             SELECT '3marked', s2.slug, s2.title, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL
             FROM reading_history rh2 JOIN stories s2 ON s2.id = rh2.story_id
             WHERE rh2.user_id = ? AND rh2.marked_at IS NOT NULL AND s2.deleted_at IS NULL
             UNION ALL
             SELECT '4story', s.slug, s.title, CAST(s.validated AS TEXT), NULL, s.updated_at, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL
             FROM stories s WHERE s.author_id = ? AND s.deleted_at IS NULL
             UNION ALL
             SELECT '5series' k, ser.slug a, ser.title b,
                    CAST((SELECT COUNT(*) FROM series_items si JOIN stories st ON st.id = si.story_id
                          WHERE si.series_id = ser.id AND si.confirmed = 1 AND st.deleted_at IS NULL) AS TEXT) c,
                    CAST((SELECT COUNT(*) FROM series_items si JOIN stories st ON st.id = si.story_id
                          WHERE si.series_id = ser.id AND si.confirmed = 0 AND st.deleted_at IS NULL) AS TEXT) d,
                    ser.membership e, NULL f, NULL g, NULL h, NULL i, NULL j, NULL l, NULL m, NULL n, NULL o
             FROM series ser WHERE ser.owner_id = ?" . $mutedBranch .
            ' ORDER BY k, e LIMIT 250', $binds);
        // New alias note: the notify toggles are j/l/m because k is already the
        // branch discriminator; all three COALESCE to 1 so a prefs-less member
        // fails safe ON, the same contract notifyRecipients enforces at send
        // time. The 12d pair is n/o (lang, theme): NULL reads as '' / unchecked
        // for a prefs-less member, and the prefs-form fields preselect from them
        // without costing the page its second query.
        $me = null;
        $stories = [];
        $seriesList = [];
        $following = [];
        $progress = [];
        $marked = [];
        $muted = [];
        foreach ($rows as $r) {
            if ($r['k'] === '0me') { $me = $r; }
            elseif ($r['k'] === '0mu') { $muted[] = ['profile_slug' => (string) $r['a'], 'penname' => (string) $r['b']]; }
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
            'title' => \App\Lang::t('account.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('account.heading'))->withCanonical('/account')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'me' => $me,
            'stories' => $stories,
            'seriesList' => $seriesList,
            'following' => $following,
            'muted' => $muted,
            'progress' => $progress,
            'marked' => $marked,
            'tocOn' => ($this->request->cookies['toc'] ?? '') === '1',
            'langPref' => (string) ($me['n'] ?? ''),
            'themePref' => (string) ($me['o'] ?? ''),
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
        // Per-user lang + theme (phase 12d): both validate BEFORE any write, so
        // junk never reaches the upsert (the schema CHECK is only the backstop).
        // A null value leaves the stored pref untouched: the field groups hide
        // when their flag is off, and an absent field (a partial post) must not
        // clobber the stored choice (the Security section's never-deleted,
        // toggle-on-restore contract). Presence, not emptiness, is the signal:
        // lang '' is a real choice (follow the archive default).
        $lang = null;
        if (\App\Features::on('peruserlang') && \array_key_exists('lang', $this->request->post)) {
            $lang = $this->request->postStr('lang');
            if (!\in_array($lang, ['', ...\App\Lang::installed()], true)) {
                return new Response(\App\Lang::t('account.lang_invalid'), 422);
            }
        }
        $theme = null;
        if (\App\Features::on('perusertheme') && \array_key_exists('theme', $this->request->post)) {
            $theme = $this->request->postStr('theme');
            if (!\in_array($theme, \App\Theme::VALUES, true)) {
                return new Response(\App\Lang::t('account.theme_invalid'), 422);
            }
        }
        // Value-based, not isset-based: the form's checkboxes submit no field when
        // unchecked, and the value is the truth ('0' stays off, only '1' is on).
        $on = fn (string $k): int => (int) ($this->request->post[$k] ?? 0) === 1 ? 1 : 0;
        $this->db->begin();
        try {
            $this->db->query('UPDATE users SET bio = ?, is_beta = ? WHERE id = ?', [$bio, $isBeta, $me]);
            // Upsert, NOT a bare UPDATE (plan review finding 5): seeder-era and
            // user:create members carry no user_prefs row; a plain UPDATE would
            // silently drop their choice (the exact bug the 6b QA pass fixed once).
            // The lang/theme columns ride only when their flags are on and the
            // fields posted, so a save under a disabled flag keeps the row intact.
            $cols = ['user_id', 'default_sort', 'toc_first', 'notify_review', 'notify_response', 'notify_favorites', 'notify_favorite_digest'];
            $binds = [$me, $sort, (int) $toc, $on('notify_review'), $on('notify_response'), $on('notify_favorites'), $on('notify_favorite_digest')];
            $updates = ['default_sort = excluded.default_sort', 'toc_first = excluded.toc_first',
                'notify_review = excluded.notify_review', 'notify_response = excluded.notify_response',
                'notify_favorites = excluded.notify_favorites', 'notify_favorite_digest = excluded.notify_favorite_digest'];
            if ($lang !== null) { $cols[] = 'lang'; $binds[] = $lang; $updates[] = 'lang = excluded.lang'; }
            // auto stores paper (the post-026 CHECK admits paper/sepia/night
            // only): OS-default is the absence of a cookie, never a stored one.
            if ($theme !== null) { $cols[] = 'theme'; $binds[] = $theme === 'auto' ? 'paper' : $theme; $updates[] = 'theme = excluded.theme'; }
            $this->db->query(
                'INSERT INTO user_prefs (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, \count($cols), '?')) . ')
                ON CONFLICT(user_id) DO UPDATE SET ' . implode(', ', $updates),
                $binds);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        $this->purgeOwnProfile($me);
        // the beta badge flips directory membership too, not just the profile card
        (new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache'))->purgeAuthors();
        // The cookie sync (ruling 5): the save is a write point, so the pref and
        // the runtime cookie land together. Every directive is one
        // withAddedHeader leaf, the same chain shape whatever the count
        // (Response::send() emits list leaves with append semantics, so the
        // session cookie survives beside them). A lang switch back to ''
        // CLEARS the cookie: the browser must not keep rendering the old pack
        // until logout.
        $cookies = [$toc === '1'
            ? 'toc=1; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax'
            : 'toc=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax'];
        if ($lang !== null) {
            $cookies[] = $lang === ''
                ? 'lang=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax'
                : 'lang=' . $lang . '; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax';
        }
        if ($theme !== null) {
            // auto CLEARS the cookie (the reader settings route's economy): a
            // cookie bearing theme=auto carries no information but still makes
            // every future request bypass the static cache.
            $cookies[] = $theme === 'auto'
                ? \App\Theme::COOKIE . '=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax'
                : \App\Theme::COOKIE . '=' . $theme . '; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax';
        }
        $redirect = Response::redirect('/account');
        foreach ($cookies as $cookie) {
            $redirect = $redirect->withAddedHeader('Set-Cookie', $cookie);
        }
        return $redirect;
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
