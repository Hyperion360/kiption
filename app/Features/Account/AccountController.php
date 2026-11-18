<?php // app/Features/Account/AccountController.php
namespace App\Features\Account;
use Kip\{App, Database, Http\Request, Http\Response, Session, Storage, View};
use Kip\Routing\{Auth as AuthAttr, Post};

final class AccountController
{
    /** The ?done= values settings() turns into a confirmation line. */
    private const DONE = ['password', 'emailsent', 'fields'];

    /** Pen-name changes per 15 minutes (AttemptLimiter's fixed window):
     *  this many per member and PENNAME_MAX_IP across all members sharing
     *  one address. Renames write public pages and a slug-redirect row, so
     *  they stay scarce. */
    private const PENNAME_MAX_PER_MEMBER = 3;
    private const PENNAME_MAX_PER_IP = 10;

    /** Values for the refusal line (already HTML-safe) and the answers a
     *  member typed, so a refused form comes back as they left it. */
    private array $errorParams = [];
    private ?string $pennameError = null;
    private ?string $pennameValue = null;
    private ?string $avatarError = null;
    private ?array $submittedFields = null;

    public function __construct(
        private View $view,
        private Request $request,
        private Database $db,
        private Session $session,
        private App $app,
        private Storage $storage,
    ) {}

    /** Resolved on use: only the email actions need mail, so a page that never
     *  sends does not require a configured transport. */
    private function mailer(): \App\MailGuard
    {
        return $this->app->container->make(\App\MailGuard::class);
    }

    /** Save the member's answers to the owner's profile fields. */
    #[AuthAttr] #[Post]
    public function fields(): Response
    {
        $me = (int) $this->session->get('user_id');
        if (($limited = $this->profileSaveThrottled($me)) !== null) return $limited;
        $given = $this->request->post['field'] ?? [];
        $input = [];
        foreach (\is_array($given) ? $given : [] as $id => $v) if (\is_scalar($v)) $input[(int) $id] = (string) $v;
        // Profile answers render on public profile pages like bio does, so
        // they ride the same word filter (the refusal never echoes the word).
        if (($blocked = \App\Features\Wordfilter\WordFilter::refusal($this->db, ...array_values($input))) !== null) {
            $this->submittedFields = $input;
            return new Response($blocked, 422);
        }
        $err = (new \App\ProfileFields($this->db))->save($me, $input);
        if ($err !== null) {
            $this->submittedFields = $input;
            return $this->refuse($err[0], ['label' => htmlspecialchars($err[1], ENT_QUOTES, 'UTF-8')]);
        }
        \App\ActivityLog::by($this->db, $this->session, 'account.fields', '');
        $this->purgeOwnProfile();
        return Response::redirect('/account/settings?done=fields');
    }

    /** Ask to change the email address: password re-checked, a confirm link
     *  goes to the NEW address, and nothing changes until it is used. */
    #[AuthAttr] #[Post]
    public function email(): Response
    {
        $me = (int) $this->session->get('user_id');
        $new = trim($this->request->postStr('new_email'));
        [$err, $token] = $this->security()->requestEmailChange($me, $new, $this->request->postStr('current_password'), $this->request->ip);
        if ($err !== null) return $this->refuse($err);
        $url = rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/') . '/account/emailconfirm?t=' . $token;
        [$subject, $body] = \App\Templates::get($this->db, 'email_change_confirm', ...\App\Templates::pair('email_change_confirm'));
        $sent = false;
        try {
            $sent = $this->mailer()->send($new, $subject, strtr($body, ['{url}' => $url]), \App\MailGuard::CRITICAL);
        } catch (\Throwable $e) {
            error_log("Email change mail failed: {$e->getMessage()}");
        }
        if (!$sent) return $this->refuse('account.err_email_mail'); // a ceiling refusal rides the existing refuse path
        \App\ActivityLog::record($this->db, $me, 'account.email_requested');
        return Response::redirect('/account/settings?done=emailsent');
    }

    /** The emailed link lands here. Opening it changes nothing (mail scanners
     *  prefetch links); the button POSTs to emailapply. No sign-in needed. */
    public function emailconfirm(): Response
    {
        $token = (string) ($this->request->get['t'] ?? '');
        if (!$this->security()->peekEmailChange($token)) return $this->emailView(null, 410);
        return $this->emailView($token, 200);
    }

    #[Post]
    public function emailapply(): Response
    {
        $res = $this->security()->applyEmailChange($this->request->postStr('t'));
        if (\is_string($res)) return $this->emailView(null, 410, $res);
        // The address stays out of the log; the member is found by the new address.
        \App\ActivityLog::record($this->db, (int) ($this->db->one('SELECT id FROM users WHERE email = ?', [$res['new']])['id'] ?? 0) ?: null, 'account.email_changed');
        [$subject, $body] = \App\Templates::get($this->db, 'email_changed_notice', ...\App\Templates::pair('email_changed_notice'));
        try {
            if (!$this->mailer()->send($res['old'], $subject, strtr($body, ['{new_email}' => $res['new']]), \App\MailGuard::CRITICAL)) {
                // The change is already applied, so a ceiling refusal on the
                // notice to the old address is only a log line (never the address).
                error_log('Email change notice skipped: the verification mail limit is reached');
            }
        } catch (\Throwable $e) {
            error_log("Email change notice failed: {$e->getMessage()}");
        }
        return $this->emailView(null, 200, 'account.email_link_done');
    }

    private function emailView(?string $token, int $status, ?string $message = null): Response
    {
        $title = \App\Lang::t('account.email_confirm_heading');
        $html = $this->view->render('account/emailconfirm', ['title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/account/emailconfirm')->withNoindex(),
            'csrf' => $this->session->csrfToken(), 'token' => $token, 'message' => $message]);
        return (new Response($html, $status))
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** The confirm step for deleting the account: password and what happens
     *  to the stories. Nothing is deleted by opening this page. */
    #[AuthAttr]
    public function delete(): string
    {
        $me = (int) $this->session->get('user_id');
        $title = \App\Lang::t('account.delete_heading');
        return $this->view->render('account/delete', ['title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/account/delete')->withNoindex(),
            'csrf' => $this->session->csrfToken(), 'error' => null, 'loggedIn' => true,
            'lastAdmin' => $this->security()->isLastAdmin($me)]);
    }

    #[AuthAttr] #[Post]
    public function destroy(): Response
    {
        $me = (int) $this->session->get('user_id');
        $stories = $this->request->postStr('stories');
        $mine = $this->db->one('SELECT avatar_path, penname FROM users WHERE id = ?', [$me]);
        $avatar = $mine['avatar_path'] ?? null;
        $images = array_map(static fn(array $r): string => basename((string) $r['path']),
            $this->db->all('SELECT path FROM author_images WHERE owner_id = ?', [$me]));
        $title = \App\Lang::t('account.delete_heading');
        $err = !\in_array($stories, ['keep', 'remove'], true) ? 'account.err_delete_choice'
            : $this->security()->deleteAccount($me, $this->request->postStr('current_password'), $stories === 'keep', $this->request->ip);
        if ($err !== null) {
            $html = $this->view->render('account/delete', ['title' => $title,
                'head' => $this->head()->withTitle($title)->withCanonical('/account/delete')->withNoindex(),
                'csrf' => $this->session->csrfToken(), 'error' => $err, 'loggedIn' => true,
                'lastAdmin' => $this->security()->isLastAdmin($me)]);
            return new Response($html, $err === \App\AccountSecurity::THROTTLED ? 429 : 422);
        }
        // The account is scrubbed, so the log keeps who it was in the detail.
        \App\ActivityLog::record($this->db, null, 'account.delete', 'member ' . $me . ' (' . (string) ($mine['penname'] ?? '') . ')');
        // Removing the stories removes their library rows; the files and their quota bytes go with them.
        // Keeping them hands the rows to Anonymous, which keeps the files in use, so removeFile skips them.
        if ($stories === 'remove') {
            \App\StorageQuota::forApp($this->app)->removeFiles($images);
        }
        $this->afterDelete($avatar === null ? null : (string) $avatar);
        return Response::redirect('/')
            ->withAddedHeader('Set-Cookie', \App\Cookie::long('lang', ''))
            ->withAddedHeader('Set-Cookie', \App\Cookie::pref(\App\Theme::COOKIE, ''));
    }

    /** The row is gone: end the session and refresh the public pages that
     *  showed this member or their stories. */
    private function afterDelete(?string $avatar): void
    {
        if ($avatar !== null && $avatar !== '') {
            // basename() keeps the delete inside the configured uploads dir.
            $dir = rtrim((string) ($this->app->config('uploads')['dir'] ?? dirname(__DIR__, 3) . '/public/uploads'), '/');
            $file = $dir . '/' . basename($avatar);
            \App\StorageQuota::forApp($this->app)->release((int) @filesize($file)); // the counter drops with the file
            @unlink($file);
        }
        (new \Kip\Auth($this->db, $this->session))->logout();
        \App\CachePurge::run($this->app, $this->db);
    }

    /** The Library: the reading shelf, the member's own work and follows. */
    #[AuthAttr]
    public function index(): string
    {
        return $this->view->render('account/show', $this->page('/account', \App\Lang::t('account.library_heading')));
    }

    /** Settings: profile, display, notification and muting preferences.
     *  Same one-query fold as the Library (the 0me row carries every field). */
    #[AuthAttr]
    public function settings(): string
    {
        $done = (string) ($this->request->get['done'] ?? '');
        $notice = \in_array($done, self::DONE, true) ? 'account.done_' . $done : null;
        if (\App\Saved::message($this->request) === 'saved.penname') $notice = 'saved.penname';
        return $this->view->render('account/settings', $this->page('/account/settings', \App\Lang::t('account.settings'), $notice));
    }

    /** The settings page again with a refusal message, for a failed security form. */
    private function refuse(string $errorKey, array $params = [], ?int $status = null): Response
    {
        $status ??= $errorKey === \App\AccountSecurity::THROTTLED ? 429 : 422;
        $this->errorParams = $params;
        $html = $this->view->render('account/settings', $this->page('/account/settings', \App\Lang::t('account.settings'), null, $errorKey));
        return new Response($html, $status);
    }

    private function security(): \App\AccountSecurity
    {
        return new \App\AccountSecurity($this->db, new \App\AttemptLimiter($this->db));
    }

    /** Change the password while signed in: current password, new one twice. */
    #[AuthAttr] #[Post]
    public function password(): Response
    {
        $me = (int) $this->session->get('user_id');
        $err = $this->security()->changePassword($me, $this->request->postStr('current_password'),
            $this->request->postStr('new_password'), $this->request->postStr('confirm_password'), $this->request->ip);
        if ($err !== null) return $this->refuse($err);
        \App\ActivityLog::record($this->db, $me, 'account.password');
        // The hash changed, so every older session is already void. This one
        // gets a fresh id (fixation defense) and the new epoch so it stays in.
        $hash = (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$me])['password_hash'];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $this->session->rotateCsrf();
        $this->session->set('pwd_epoch', substr($hash, 0, \Kip\Auth::EPOCH_LEN));
        return Response::redirect('/account/settings?done=password');
    }

    /** @return array<string, mixed> the shared envelope of both account pages */
    private function page(string $canonical, string $title, ?string $notice = null, ?string $error = null): array
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
        // The owner's profile fields with this member's answers: one more
        // branch, '0fl', so settings stays one content query. a=id, b=label,
        // c=type, d=answer; e carries the display order for the shared ORDER BY.
        $fieldsBranch = "
             UNION ALL
             SELECT '0fl' AS k, CAST(pf.id AS TEXT) AS a, pf.label AS b, pf.type AS c, pv.value AS d,
                    printf('%08d%08d', pf.position, pf.id) AS e,
                    NULL AS f, NULL AS g, NULL AS h, NULL AS i, NULL AS j, NULL AS l, NULL AS m, NULL AS n, NULL AS o
             FROM profile_fields pf LEFT JOIN profile_field_values pv ON pv.field_id = pf.id AND pv.user_id = ?";
        $binds = [$userId, $userId, $userId, $userId, $userId, $userId];
        if ($mutedBranch !== '') $binds[] = $userId;
        $binds[] = $userId;
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
             FROM series ser WHERE ser.owner_id = ?" . $mutedBranch . $fieldsBranch .
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
        $profileFields = [];
        foreach ($rows as $r) {
            if ($r['k'] === '0me') { $me = $r; }
            elseif ($r['k'] === '0fl') { $profileFields[] = ['id' => (int) $r['a'], 'label' => (string) $r['b'], 'type' => (string) $r['c'], 'value' => (string) ($r['d'] ?? '')]; }
            elseif ($r['k'] === '0mu') { $muted[] = ['profile_slug' => (string) $r['a'], 'penname' => (string) $r['b']]; }
            elseif ($r['k'] === '1follow') { $following[] = $r; }
            elseif ($r['k'] === '2progress') { $progress[] = $r; }
            elseif ($r['k'] === '3marked') { $marked[] = $r; }
            elseif ($r['k'] === '5series') { $seriesList[] = $r; }
            else { $stories[] = $r; }
        }
        // A member without a saved order gets the owner's default.
        if (($me['i'] ?? \App\Settings::get('default_sort')) === 'alpha') {
            // default_sort is a PHP-side concern here: the fold's shared ORDER BY
            // cannot vary per branch, so the member's own list re-sorts in place.
            usort($stories, static fn(array $x, array $y): int => strcasecmp((string) $x['b'], (string) $y['b']));
        }
        if ($this->submittedFields !== null) {
            foreach ($profileFields as &$pf) $pf['value'] = (string) ($this->submittedFields[$pf['id']] ?? '');
            unset($pf);
        }
        return [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical($canonical)->withNoindex(),
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
            'notice' => $notice,
            'liveSoon' => \App\CachePurge::liveSoon($this->app),
            'error' => $error,
            'errorParams' => $this->errorParams,
            'pennameError' => $this->pennameError,
            'pennameValue' => $this->pennameValue,
            'avatarError' => $this->avatarError,
            'profileFields' => $profileFields,
        ];
    }

    #[AuthAttr] #[Post]
    public function avatar(): Response
    {
        if (!\App\Settings::on('uploads_on')) return new Response(\App\Lang::t('uploads.off'), 403);
        if (($limited = $this->profileSaveThrottled((int) $this->session->get('user_id'))) !== null) return $limited;
        $file = $this->request->file('avatar');
        if ($file === null) {
            return new Response(\App\Lang::t('images.err_avatar'), 422);
        }
        try {
            $path = \App\StorageQuota::forApp($this->app)->putUnderCap($file, false, $this->storage);
        } catch (\Kip\UploadException) {
            return new Response(\App\Lang::t('images.err_avatar'), 422);
        }
        if ($path === null) {
            // The member limit (95 percent of the cap) is full: the refusal
            // renders beside the avatar field, where the attempt was made.
            return $this->refuseAvatar(\App\Lang::t('images.storage_full'));
        }
        $userId = (int) $this->session->get('user_id');
        $old = $this->db->one('SELECT avatar_path FROM users WHERE id = ?', [$userId]);
        $this->db->query('UPDATE users SET avatar_path = ? WHERE id = ?', [$path, $userId]);
        \App\ActivityLog::by($this->db, $this->session, 'account.avatar', '');
        // The purge first, then the retire: no cached page keeps pointing at
        // a file that is already gone. removeFile releases the counter and
        // unlinks only when nothing else references the file.
        $this->purgeOwnProfile();
        if ($old !== null && $old['avatar_path'] !== null) {
            \App\StorageQuota::forApp($this->app)->removeFile(basename((string) $old['avatar_path']));
        }
        return Response::redirect('/account/settings');
    }

    /** The settings page again with the pen-name form's refusal beside the field. */
    private function refusePenname(string $message, string $typed, int $status): Response
    {
        $this->pennameError = $message;
        $this->pennameValue = $typed;
        return new Response($this->view->render('account/settings', $this->page('/account/settings', \App\Lang::t('account.settings'))), $status);
    }

    /** The settings page again with the avatar form's refusal beside the
     *  field (the refusePenname pattern): a storage-cap refusal sits where
     *  the member attempted the upload, never as a bare body. */
    private function refuseAvatar(string $message): Response
    {
        $this->avatarError = $message;
        return new Response($this->view->render('account/settings', $this->page('/account/settings', \App\Lang::t('account.settings'))), 422);
    }

    /** Change your own pen name. The profile address follows and the old one
     *  redirects (profile_slug_history). Throttled for everyone, staff included. */
    #[AuthAttr] #[Post]
    public function penname(): Response
    {
        $me = (int) $this->session->get('user_id');
        $name = trim($this->request->postStr('penname'));
        if (!(new \App\AttemptLimiter($this->db))->claim('penname', (string) $me, $this->request->ip, self::PENNAME_MAX_PER_MEMBER, self::PENNAME_MAX_PER_IP)) {
            return $this->refusePenname(\App\Lang::t('account.too_many_changes'), $name, 429);
        }
        $users = new \App\Repositories\UserRepository($this->db);
        $error = $users->pennameError($name, $me);
        if ($error === null && \App\Features\Wordfilter\WordFilter::find($this->db, $name) !== null) {
            $error = 'account.penname_not_allowed'; // never echo the matched word
        }
        if ($error !== null) return $this->refusePenname(\App\Lang::t($error), $name, 422);
        $old = $users->rename($me, $name);
        if ($old === null) return $this->refusePenname(\App\Lang::t('auth.err_penname_taken'), $name, 422);
        \App\ActivityLog::by($this->db, $this->session, 'account.penname', $old[0] . ' -> ' . $name);
        // Story pages and lists show the author's name; the local prune also covers the old profile URL.
        \App\CachePurge::run($this->app, $this->db);
        return \App\Saved::to('/account/settings', 'penname');
    }

    #[AuthAttr] #[Post]
    public function support(): Response
    {
        if (($limited = $this->profileSaveThrottled((int) $this->session->get('user_id'))) !== null) return $limited;
        $url = trim($this->request->postStr('support_url'));
        // The form's maxlength=200 is client-side only; the server enforces both rules.
        if ($url !== '' && (strlen($url) > 200 || !preg_match('#^https?://#', $url))) {
            return new Response(\App\Lang::t('settings.err_support'), 422);
        }
        $userId = (int) $this->session->get('user_id');
        $this->db->query('UPDATE users SET support_url = ? WHERE id = ?', [$url === '' ? null : $url, $userId]);
        \App\ActivityLog::by($this->db, $this->session, 'account.support', '');
        $this->purgeOwnProfile();
        return Response::redirect('/account/settings');
    }

    #[AuthAttr] #[Post]
    public function prefs(): Response
    {
        $me = (int) $this->session->get('user_id');
        if (($limited = $this->profileSaveThrottled($me)) !== null) return $limited;
        $bio = substr(trim($this->request->postStr('bio')), 0, 2000);
        if (($blocked = \App\Features\Wordfilter\WordFilter::refusal($this->db, $bio)) !== null) return new Response($blocked, 422);
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
            // Auto stores NULL (029): the login sync sets no theme cookie for
            // it, so each device follows its own OS setting.
            if ($theme !== null) { $cols[] = 'theme'; $binds[] = $theme === 'auto' ? null : $theme; $updates[] = 'theme = excluded.theme'; }
            $this->db->query(
                'INSERT INTO user_prefs (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, \count($cols), '?')) . ')
                ON CONFLICT(user_id) DO UPDATE SET ' . implode(', ', $updates),
                $binds);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        \App\ActivityLog::by($this->db, $this->session, 'account.prefs', '');
        $this->purgeOwnProfile();
        // The cookie sync (ruling 5): the save is a write point, so the pref and
        // the runtime cookie land together. Every directive is one
        // withAddedHeader leaf, the same chain shape whatever the count
        // (Response::send() emits list leaves with append semantics, so the
        // session cookie survives beside them). A lang switch back to ''
        // CLEARS the cookie: the browser must not keep rendering the old pack
        // until logout.
        $cookies = [\App\Cookie::long('toc', $toc === '1' ? '1' : '')];
        if ($lang !== null) {
            $cookies[] = \App\Cookie::long('lang', $lang);
        }
        if ($theme !== null) {
            // auto CLEARS the cookie (the reader settings route's economy): a
            // cookie bearing theme=auto carries no information but still makes
            // every future request bypass the static cache.
            $cookies[] = \App\Cookie::pref(\App\Theme::COOKIE, $theme === 'auto' ? '' : $theme);
        }
        $redirect = Response::redirect('/account/settings');
        foreach ($cookies as $cookie) {
            $redirect = $redirect->withAddedHeader('Set-Cookie', $cookie);
        }
        return $redirect;
    }

    /** Any account-side change (avatar, support link, profile fields, prefs)
     *  refreshes the public pages, edge copy included. Profile edits are rare
     *  and the sitemap rewrite is cheap at small-archive scale, so the whole
     *  purge set runs rather than one member's three pages. */
    private function purgeOwnProfile(): void
    {
        \App\CachePurge::run($this->app, $this->db);
    }

    /** One member's profile saves share a throttle (10 per member and 30 per
     *  address in 15 minutes). Over the cap the settings page comes back at
     *  429 and nothing is saved or purged. Null when there is room. */
    private function profileSaveThrottled(int $me): ?Response
    {
        if ((new \App\AttemptLimiter($this->db))->claim('profile_save', (string) $me, $this->request->ip, 10, 30)) return null;
        return $this->refuse('account.too_many_changes', [], 429);
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
