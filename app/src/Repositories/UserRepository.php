<?php // app/src/Repositories/UserRepository.php
namespace App\Repositories;

use Kip\Database;

final class UserRepository
{
    public function __construct(private Database $db) {}

    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public function pennameTaken(string $penname): bool
    {
        return $this->db->one('SELECT 1 AS x FROM users WHERE penname = ? COLLATE NOCASE', [$penname]) !== null;
    }

    /** @return string raw verify token (hex), stored hashed */
    public function createVerification(string $email): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->db->query('DELETE FROM email_verifications WHERE email = ?', [$email]);
        $this->db->query('INSERT INTO email_verifications (email, token_hash, expires_at) VALUES (?, ?, ?)',
            [$email, hash('sha256', $raw), date('c', time() + 86400)]);
        return $raw;
    }

    /** Consume a verification token. True when the account became verified.
     *  An EXPIRED token deletes the never-activated account so the email can re-register. */
    public function consumeVerification(string $rawToken): bool
    {
        $row = $this->db->one('SELECT * FROM email_verifications WHERE token_hash = ?', [hash('sha256', $rawToken)]);
        if ($row === null) return false;
        $this->db->begin();
        try {
            if (strtotime((string) $row['expires_at']) < time()) {
                $this->db->query('DELETE FROM users WHERE email = ? AND email_verified_at IS NULL', [$row['email']]);
                $this->db->query('DELETE FROM email_verifications WHERE email = ?', [$row['email']]);
                $this->db->commit();
                return false;
            }
            $this->db->query('DELETE FROM email_verifications WHERE email = ?', [$row['email']]);
            $this->db->query('UPDATE users SET email_verified_at = ? WHERE email = ? AND email_verified_at IS NULL',
                [date('c'), $row['email']]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Register under a mode. Returns [ok, error]. */
    public function register(string $mode, string $penname, string $email, string $password, string $inviteCode): array
    {
        // A taken penname belongs to someone regardless of charset: NOCASE-collision wins
        // over format validation (grandfathered and future-imported pennames can be exotic).
        if ($this->pennameTaken($penname)) {
            return [false, 'Penname is already taken.'];
        }
        if (!preg_match('/^[a-z0-9_-]{3,30}$/', $penname)) {
            return [false, 'Penname must be 3-30 characters: lowercase letters, digits, dash, underscore.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'That email address is not valid.'];
        }
        if (strlen($password) < 8) {
            return [false, 'Password must be at least 8 characters.'];
        }
        if ($this->findByEmail($email) !== null) {
            return [false, 'Email is already registered.'];
        }
        $invite = null;
        if ($mode === 'invite') {
            $invite = $this->db->one('SELECT * FROM invites WHERE code = ? AND used_by IS NULL', [$inviteCode]);
            if ($invite === null) return [false, 'Invalid invite code.'];
        }
        $now = date('c');
        $verified = $mode === 'verify' ? null : $now;
        $approved = $mode === 'approval' ? null : $now;
        $this->db->begin();
        try {
            $this->db->query(
                'INSERT INTO users (email, password_hash, penname, role, email_verified_at, approved_at) VALUES (?, ?, ?, \'member\', ?, ?)',
                [$email, password_hash($password, PASSWORD_DEFAULT), $penname, $verified, $approved]);
            $userId = (int) $this->db->lastInsertId();
            $this->backfillProfileSlug($userId);
            $this->db->query('INSERT INTO user_prefs (user_id) VALUES (?)', [$userId]);
            if ($invite !== null) {
                $this->db->query('UPDATE invites SET used_by = ?, used_at = ? WHERE id = ? AND used_by IS NULL',
                    [$userId, $now, $invite['id']]);
                $check = $this->db->one('SELECT used_by FROM invites WHERE id = ?', [$invite['id']]);
                if ($check === null || (int) $check['used_by'] !== $userId) {
                    throw new \RuntimeException('invite consumed concurrently');
                }
            }
            $this->db->commit();
            return [true, ''];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Deterministic URL key for a penname; suffixed on collision. Safe for the
     *  router and cache whitelists ([a-z0-9_-]+): pennames themselves are not
     *  (the seeder ships 'Demo Author', imports can be exotic). */
    public function backfillProfileSlug(int $userId): string
    {
        $row = $this->db->one('SELECT penname FROM users WHERE id = ?', [$userId]);
        $base = \App\Slug::make((string) ($row['penname'] ?? ''), 'member');
        $slug = \App\Slug::unique(
            fn (string $s): bool => $this->db->one('SELECT id FROM users WHERE profile_slug = ?', [$s]) !== null,
            $base
        );
        $this->db->query('UPDATE users SET profile_slug = ? WHERE id = ?', [$slug, $userId]);
        return $slug;
    }

    /** The profile card: any unlocked member with a penname is linkable by
     *  direct URL; the directory (Task 6) lists only approved+verified members
     *  and counts authored works only. The gating difference is intentional
     *  (plan review finding 17). */
    public function findByProfileSlug(string $slug): ?array
    {
        if (!preg_match('#^[a-z0-9_-]+$#', $slug)) return null;
        return $this->db->one(
            'SELECT u.id, u.penname, u.bio, u.avatar_path, u.support_url, u.is_beta, u.role, u.created_at,
                    (SELECT COUNT(*) FROM stories st WHERE st.deleted_at IS NULL AND st.validated = 1
                       AND (st.author_id = u.id OR EXISTS (SELECT 1 FROM coauthors ca WHERE ca.story_id = st.id AND ca.user_id = u.id))) story_count,
                    (SELECT COUNT(*) FROM series ser WHERE ser.owner_id = u.id) series_count
             FROM users u WHERE u.profile_slug = ? AND u.is_locked = 0 AND u.penname IS NOT NULL', [$slug]
        );
    }

    /** The member directory page: approved+verified unlocked members with a
     *  penname, authored works only (the directory's count excludes coauthored
     *  works by design, plan review finding 17). $letter: null = all members,
     *  a single [a-z] char = exact first-char bucket, '0' = the non-letter
     *  bucket (digits/underscore, i.e. NOT GLOB '[a-z]*'). Placeholder count:
     *  the dynamic letter WHERE adds ONE ? only in the single-letter branch,
     *  and LIMIT ? OFFSET ? always add two, so params grow in lockstep with
     *  the branches that append them (max three: letter, perPage, offset). */
    public function authorsDirectory(?string $letter, bool $betaOnly, int $perPage, int $offset): array
    {
        $sql = 'SELECT u.profile_slug, u.penname, u.is_beta, u.created_at,
                       (SELECT COUNT(*) FROM stories st WHERE st.author_id = u.id AND st.validated = 1 AND st.deleted_at IS NULL) story_count
                FROM users u
                WHERE u.penname IS NOT NULL AND u.is_locked = 0 AND u.approved_at IS NOT NULL AND u.email_verified_at IS NOT NULL';
        $params = [];
        if ($letter !== null) {
            if ($letter === '0') { $sql .= " AND u.profile_slug NOT GLOB '[a-z]*'"; }
            else { $sql .= ' AND substr(u.profile_slug, 1, 1) = ?'; $params[] = $letter; }
        }
        if ($betaOnly) $sql .= ' AND u.is_beta = 1';
        $sql .= ' ORDER BY u.penname COLLATE NOCASE LIMIT ? OFFSET ?';
        $params[] = $perPage; $params[] = $offset;
        return $this->db->all($sql, $params);
    }

    /** Author + coauthors, deduped, with the recipient's notify pref resolved
     *  and the acting member excluded (a coauthor kudo-ing their own story
     *  must not notify themselves, plan review finding 10; the solo-author call
     *  sites already suppress self-notification today).
     *  $prefColumn is one of notify_review|notify_response|notify_favorites
     *  (whitelisted here, never interpolated unvalidated) or null for no gate.
     *  A missing prefs row means ON (fail-safe for senders). */
    public function notifyRecipients(int $storyId, ?string $prefColumn, int $exceptActorId = 0): array
    {
        if ($prefColumn !== null && !in_array($prefColumn, ['notify_review', 'notify_response', 'notify_favorites'], true)) {
            throw new \InvalidArgumentException('pref');
        }
        $gate = $prefColumn === null ? '' : ' AND COALESCE(p.' . $prefColumn . ', 1) = 1';
        $rows = $this->db->all(
            "SELECT u.id FROM stories s JOIN users u ON u.id = s.author_id
               LEFT JOIN user_prefs p ON p.user_id = u.id WHERE s.id = ? AND u.id != ? {$gate}
             UNION
             SELECT u.id FROM coauthors ca JOIN users u ON u.id = ca.user_id
               LEFT JOIN user_prefs p ON p.user_id = u.id WHERE ca.story_id = ? AND u.id != ? {$gate}",
            [$storyId, $exceptActorId, $storyId, $exceptActorId]
        );
        return array_map(fn (array $r): int => (int) $r['id'], $rows);
    }

    /** The stories tab as ONE compound query (plan review finding 6): k='0' is
     *  the profile row, k='1' the validated stories (own + coauthored), paged
     *  inside a parenthesized subselect WITH its ORDER BY (paging without one
     *  is nondeterministic) and id tiebreakers (the 6a same-ms lesson).
     *  j numbers the story rows so the compound can ORDER BY k, j (bare output
     *  aliases only). browse/recent.php renders penname and rating_label per
     *  row (finding 8), so both are selected. Like recentStories, this is a
     *  guest listing surface: restricted works never appear (the 6b fix; the
     *  vestigial is_restricted output column keeps the fold's column count).
     *  Null when the slug is unknown or the member is locked. */
    public function storiesTab(string $slug, string $sort, int $perPage, int $offset): ?array
    {
        if (!preg_match('#^[a-z0-9_-]+$#', $slug)) return null;
        $inner = $sort === 'alpha' ? 's.title COLLATE NOCASE ASC, s.id DESC' : 's.updated_at DESC, s.id DESC';
        $outer = str_replace('s.', 'st.', $inner);
        $rows = $this->db->all(
            "SELECT '0' k, CAST(u.id AS TEXT) a, u.penname b, u.bio c, CAST(u.is_beta AS TEXT) d,
                    u.support_url e, u.avatar_path f, u.created_at g, NULL h, NULL i, 0 j
             FROM users u WHERE u.profile_slug = ? AND u.is_locked = 0 AND u.penname IS NOT NULL
             UNION ALL
             SELECT '1' k, st.slug a, st.title b, st.summary c, CAST(st.completed AS TEXT) d,
                    CAST(st.word_count AS TEXT) e, st.updated_at f, u2.penname g, r.label h,
                    CAST(st.is_restricted AS TEXT) i, ROW_NUMBER() OVER (ORDER BY {$outer}) j
             FROM (SELECT s.* FROM stories s
                   WHERE s.deleted_at IS NULL AND s.validated = 1 AND s.is_restricted = 0
                     AND (s.author_id = (SELECT id FROM users WHERE profile_slug = ?)
                          OR EXISTS (SELECT 1 FROM coauthors ca WHERE ca.story_id = s.id
                                     AND ca.user_id = (SELECT id FROM users WHERE profile_slug = ?)))
                   ORDER BY {$inner} LIMIT ? OFFSET ?) st
             JOIN users u2 ON u2.id = st.author_id
             JOIN ratings r ON r.id = st.rating_id
             ORDER BY k, j",
            // FIVE binds, in order of appearance: profile slug (k='0' branch),
            // the two profile-slug subselects in the paged story WHERE, then
            // LIMIT (perPage + 1 fetch, sliced by partitionTab) and OFFSET.
            [$slug, $slug, $slug, $perPage + 1, $offset]
        );
        return $this->partitionTab($rows, $perPage);
    }

    /** The favorites tab, same one-query shape (k='0' profile, k='1' shelf).
     *  The shelf gates (deleted/validated/restricted) sit INSIDE the paged
     *  subselect so page windows are computed after filtering, never before
     *  (a post-JOIN gate would page over rows it then drops). */
    public function favoritesTab(string $slug, int $perPage, int $offset): ?array
    {
        if (!preg_match('#^[a-z0-9_-]+$#', $slug)) return null;
        $rows = $this->db->all(
            "SELECT '0' k, CAST(u.id AS TEXT) a, u.penname b, u.bio c, CAST(u.is_beta AS TEXT) d,
                    u.support_url e, u.avatar_path f, u.created_at g, NULL h, NULL i, 0 j
             FROM users u WHERE u.profile_slug = ? AND u.is_locked = 0 AND u.penname IS NOT NULL
             UNION ALL
             SELECT '1' k, s.slug a, s.title b, s.summary c, CAST(s.completed AS TEXT) d,
                    CAST(s.word_count AS TEXT) e, s.updated_at f, u2.penname g, r.label h,
                    CAST(s.is_restricted AS TEXT) i, ROW_NUMBER() OVER (ORDER BY page.created_at DESC, page.story_id DESC) j
             FROM (SELECT f2.story_id, f2.created_at FROM favorites f2
                   JOIN stories s0 ON s0.id = f2.story_id
                   WHERE f2.user_id = (SELECT id FROM users WHERE profile_slug = ?) AND f2.story_id IS NOT NULL
                     AND s0.deleted_at IS NULL AND s0.validated = 1 AND s0.is_restricted = 0
                   ORDER BY f2.created_at DESC, f2.story_id DESC LIMIT ? OFFSET ?) page
             JOIN stories s ON s.id = page.story_id
             JOIN users u2 ON u2.id = s.author_id
             JOIN ratings r ON r.id = s.rating_id
             ORDER BY k, j",
            // FOUR binds: profile slug (k='0' branch), the favorites subselect
            // user lookup, then LIMIT (perPage + 1) and OFFSET.
            [$slug, $slug, $perPage + 1, $offset]
        );
        return $this->partitionTab($rows, $perPage);
    }

    /** Shared partition for the tab folds. The repo fetches perPage + 1 story
     *  rows so callers get hasMore without a COUNT query; the slice trims the
     *  look-ahead row back to the page size. */
    private function partitionTab(array $rows, int $perPage): ?array
    {
        $profile = null;
        $stories = [];
        foreach ($rows as $r) {
            if ($r['k'] === '0') {
                $profile = ['id' => (int) $r['a'], 'penname' => $r['b'], 'bio' => $r['c'],
                    'is_beta' => (int) $r['d'], 'support_url' => $r['e'], 'avatar_path' => $r['f'],
                    'created_at' => $r['g']];
            } else {
                $stories[] = ['slug' => $r['a'], 'title' => $r['b'], 'summary' => $r['c'],
                    'completed' => (int) $r['d'], 'word_count' => (int) $r['e'], 'updated_at' => $r['f'],
                    'penname' => $r['g'], 'rating_label' => $r['h'], 'is_restricted' => (int) $r['i']];
            }
        }
        if ($profile === null) return null;
        $hasMore = count($stories) > $perPage;
        return ['profile' => $profile, 'stories' => array_slice($stories, 0, $perPage), 'hasMore' => $hasMore];
    }
}
