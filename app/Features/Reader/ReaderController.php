<?php // app/Features/Reader/ReaderController.php
namespace App\Features\Reader;
use Kip\{Database, Session};
use Kip\Http\{Request, Response};
use Kip\Routing\{Auth as AuthAttr, Post};

final class ReaderController
{
    /** Bookmark notes cap; README/DBMAP document it as 500 characters. */
    private const NOTE_MAX = 500;

    public function __construct(
        private Request $request,
        private Database $db,
        private Session $session,
    ) {}

    #[Post]
    public function settings(): Response
    {
        // Reset (the D1 desktop panel's link): everything back to defaults in
        // one post; the default-equal path below then clears both cookies.
        $reset = $this->request->postStr('reset') === '1';
        $theme = $reset ? 'auto' : $this->request->postStr('theme');
        $theme = in_array($theme, \App\Theme::VALUES, true) ? $theme : 'auto';
        $prefs = $reset ? new Prefs() : new Prefs(
            self::pick((int) $this->request->postStr('size'), Prefs::SIZES, 19),
            self::pick($this->request->postStr('typeface'), Prefs::TYPEFACES, 'serif'),
            self::pick($this->request->postStr('spacing'), Prefs::SPACINGS, 'regular'),
            self::pick($this->request->postStr('paragraphs'), Prefs::PARAGRAPHS, 'indented'),
            self::pick($this->request->postStr('width'), Prefs::WIDTHS, 'medium'),
            self::pick($this->request->postStr('mode'), Prefs::MODES, 'scroll'),
        );
        $to = \App\Redirects::safeReturn($this->request->postStr('return_to'));

        // Write-through for members (the ThemeController idiom, phase 12d): the
        // row re-syncs the cookie at the next login. Guests stay cookie-only.
        // Auto stores NULL (029): the login sync then sets no theme cookie, so
        // the member's devices follow their own OS setting.
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        if ($me !== 0 && \App\Features::on('perusertheme')) {
            $this->db->query(
                'INSERT INTO user_prefs (user_id, theme) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET theme = excluded.theme',
                [$me, $theme === 'auto' ? null : $theme]);
        }

        // Cache-bypass economy (codex finding): a cookie-bearing request never
        // hits the static cache, so cookies are only set when they carry real
        // information. theme=auto and all-default typography CLEAR their
        // cookies - a visitor who saves defaults keeps full cache hits.
        // Cookie::pref (no HttpOnly): the enhancement layer rewrites both on
        // every control move, and browsers refuse to overwrite an HttpOnly
        // cookie from document.cookie.
        $r = Response::redirect($to);
        $r = $r->withAddedHeader('Set-Cookie', \App\Cookie::pref(\App\Theme::COOKIE, $theme === 'auto' ? '' : $theme));
        $isDefault = $prefs->cookieValue() === (new Prefs())->cookieValue();
        return $r->withAddedHeader('Set-Cookie', \App\Cookie::pref(Prefs::COOKIE, $isDefault ? '' : $prefs->cookieValue()));
    }

    /** Whitelist or default; the cookie is only ever built from these. */
    private static function pick(mixed $raw, array $allowed, int|string $default): int|string
    {
        return in_array((string) $raw, array_map('strval', $allowed), true) ? $raw : $default;
    }

    /** (story, chapter) by slug + validated position in ONE query, behind
     *  StoryController::read()'s exact restricted gate (CAST is load-bearing:
     *  a bare ? != 0 would compare across storage classes and fail the gate
     *  open for guests). Members bookmark what they may read; 404 otherwise.
     *  @return array{sid: int, cid: int}|null */
    private function bookmarkTarget(string $slug, string $position, int $me): ?array
    {
        $row = $this->db->one(
            'SELECT s.id AS sid, c.id AS cid, r.is_adult FROM stories s
             JOIN chapters c ON c.story_id = s.id AND c.position = ? AND c.validated = 1
             JOIN ratings r ON r.id = s.rating_id
             WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)',
            [(int) $position, $slug, $me]);
        if ($row === null) return null;
        // The adult gate read() enforces (review): members bookmark what they
        // may read, so an unacknowledged adult story refuses the bookmark.
        if ((int) $row['is_adult'] === 1 && ($this->request->cookies['age_ok'] ?? null) === null) return null;
        return ['sid' => (int) $row['sid'], 'cid' => (int) $row['cid']];
    }

    #[AuthAttr] #[Post]
    public function bookmarkAdd(string $slug, string $position): Response
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        $row = $this->bookmarkTarget($slug, $position, $me);
        // The $me === 0 half is defense-in-depth: the kernel's Auth gate
        // already rejects logged-out callers, and user ids start at 1.
        if ($row === null || $me === 0) { return new Response('Page not found', 404); }
        $note = mb_substr(trim($this->request->postStr('note')), 0, self::NOTE_MAX);
        // SQLite's json_object returns NULL for invalid UTF-8, which would
        // blank the member's whole bookmarks blob (the sheet renders "none
        // yet" while rows exist). Scrub garbage bytes to U+FFFD.
        $note = mb_scrub($note, 'UTF-8');
        // Only the note form sends the field. The ribbon button does not, so a
        // repeat bookmark (double click, resubmit, stale tab) keeps the note.
        $hasNote = array_key_exists('note', $this->request->post);
        $this->db->begin();
        $this->db->query(
            'INSERT INTO bookmarks (user_id, story_id, chapter_id, note)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(user_id, story_id, chapter_id) DO UPDATE SET note = '
             . ($hasNote ? 'excluded.note' : 'bookmarks.note'),
            [$me, $row['sid'], $row['cid'], $note]);
        $this->db->commit();
        return Response::redirect('/story/read/' . $slug . '/' . (int) $position);
    }

    #[AuthAttr] #[Post]
    public function bookmarkRemove(string $slug, string $position): Response
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        $row = $this->bookmarkTarget($slug, $position, $me);
        if ($row === null || $me === 0) { return new Response('Page not found', 404); }
        $this->db->begin();
        $this->db->query(
            'DELETE FROM bookmarks WHERE user_id = ? AND story_id = ? AND chapter_id = ?',
            [$me, $row['sid'], $row['cid']]);
        $this->db->commit();
        return Response::redirect('/story/read/' . $slug . '/' . (int) $position);
    }
}
