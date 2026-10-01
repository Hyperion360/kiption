<?php // app/Features/Reader/ReaderController.php
namespace App\Features\Reader;
use Kip\{Database, Session};
use Kip\Http\{Request, Response};
use Kip\Routing\{Auth as AuthAttr, Post};

final class ReaderController
{
    private const YEAR = 31536000;

    public function __construct(
        private Request $request,
        private Database $db,
        private Session $session,
    ) {}

    #[Post]
    public function settings(): Response
    {
        $theme = $this->request->postStr('theme');
        $theme = in_array($theme, \App\Theme::VALUES, true) ? $theme : 'auto';
        $prefs = new Prefs(
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
        // auto stores paper: the column CHECK admits paper/sepia/night only.
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        if ($me !== 0 && \App\Features::on('perusertheme')) {
            $this->db->query(
                'INSERT INTO user_prefs (user_id, theme) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET theme = excluded.theme',
                [$me, $theme === 'auto' ? 'paper' : $theme]);
        }

        // Cache-bypass economy (codex finding): a cookie-bearing request never
        // hits the static cache, so cookies are only set when they carry real
        // information. theme=auto and all-default typography CLEAR their
        // cookies — a visitor who saves defaults keeps full cache hits.
        $r = Response::redirect($to);
        $themeCookie = $theme === 'auto'
            ? \App\Theme::COOKIE . '=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax'
            : \App\Theme::COOKIE . '=' . $theme . '; Max-Age=' . self::YEAR . '; Path=/; HttpOnly; SameSite=Lax';
        $r = $r->withAddedHeader('Set-Cookie', $themeCookie);
        $isDefault = $prefs->cookieValue() === (new Prefs())->cookieValue();
        $readerCookie = $isDefault
            ? Prefs::COOKIE . '=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax'
            : Prefs::COOKIE . '=' . $prefs->cookieValue() . '; Max-Age=' . self::YEAR . '; Path=/; HttpOnly; SameSite=Lax';
        return $r->withAddedHeader('Set-Cookie', $readerCookie);
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
            'SELECT s.id AS sid, c.id AS cid FROM stories s
             JOIN chapters c ON c.story_id = s.id AND c.position = ? AND c.validated = 1
             WHERE s.slug = ? AND s.validated = 1 AND s.deleted_at IS NULL
               AND (s.is_restricted = 0 OR CAST(? AS INTEGER) != 0)',
            [(int) $position, $slug, $me]);
        return $row === null ? null : ['sid' => (int) $row['sid'], 'cid' => (int) $row['cid']];
    }

    #[AuthAttr] #[Post]
    public function bookmarkAdd(string $slug, string $position): Response
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        $row = $this->bookmarkTarget($slug, $position, $me);
        if ($row === null || $me === 0) { return new Response('Page not found', 404); }
        $note = mb_substr(trim($this->request->postStr('note')), 0, 500);
        $this->db->begin();
        $this->db->query(
            'INSERT INTO bookmarks (user_id, story_id, chapter_id, note)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(user_id, story_id, chapter_id) DO UPDATE SET note = excluded.note',
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
