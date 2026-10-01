<?php // app/Features/Reader/ReaderController.php
namespace App\Features\Reader;
use Kip\{Database, Session};
use Kip\Http\{Request, Response};
use Kip\Routing\Post;

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
}
