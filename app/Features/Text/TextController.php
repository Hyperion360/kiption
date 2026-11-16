<?php // app/Features/Text/TextController.php
namespace App\Features\Text;
use App\{Adminness, CachePurge, Lang};
use App\SiteText\{Boot, Compiler, Rules, Store};
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** The site text editor (/text): the archive owner changes interface words,
 *  adds languages, exports and imports JSON. Admin only, CSRF and POST for
 *  every write. All text goes through App\SiteText\Rules before it is stored
 *  and again when compiled, because the views print interface text raw. */
final class TextController
{
    private const IMPORT_MAX_BYTES = 1048576;
    private const IMPORT_MAX_ENTRIES = 5000;
    private const SEARCH_LIMIT = 100;
    /** Where each group sits on the index, by who meets its words. The label
     *  an owner reads is the lang key text.group.<code>; the code stays in the
     *  URL. A group missing here lands under "Other" and shows its code. */
    private const SECTIONS = [
        'visitors' => ['home', 'browse', 'search', 'story', 'reader', 'series', 'top', 'feed', 'news', 'challenges', 'lists', 'user', 'review', 'report', 'contact', 'auth', 'nav', 'foot', 'theme', 'msg', 'maintenance', 'error', 'site'],
        'members' => ['account', 'chapter', 'writer', 'posting', 'messages', 'notifications', 'favorites', 'stats', 'mute', 'uploads'],
        'admins' => ['manage', 'saved', 'adminmembers', 'queue', 'activity', 'analytics', 'settings', 'features', 'text', 'look', 'categories', 'ratings', 'characters', 'tagsadmin', 'tagqueue', 'wrangling', 'taxonomy', 'pages', 'page', 'navlinks', 'images', 'invites', 'signupcheck', 'profilefields', 'templates', 'mailusers', 'wordfilter', 'notice', 'admin'],
        'shared' => ['common', 'form'],
    ];

    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        $ctx = $this->context();
        if ($ctx instanceof Response) return $ctx;
        [$store, $lang] = $ctx;
        $base = Lang::base();
        $rows = $store->rows($lang);
        $groups = [];
        foreach ($base as $key => $text) {
            if (!is_string($text) || $key[0] === '_') continue;
            $g = self::groupOfKey($key);
            $groups[$g]['total'] = ($groups[$g]['total'] ?? 0) + 1;
            $groups[$g]['changed'] = ($groups[$g]['changed'] ?? 0) + (isset($rows[$key]) ? 1 : 0);
        }
        $sections = [];
        foreach (self::SECTIONS as $section => $codes) {
            foreach ($codes as $code) {
                if (!isset($groups[$code])) continue;
                $sections[$section][$code] = $groups[$code] + ['label' => self::groupLabel($code)];
                unset($groups[$code]);
            }
        }
        ksort($groups);
        foreach ($groups as $code => $g) {
            $sections['other'][$code] = $g + ['label' => self::groupLabel($code)];
        }
        return $this->page('text/index', Lang::t('text.heading'), '/text', [
            'sections' => $sections, 'lang' => $lang, 'languages' => $store->languages(),
        ]);
    }

    #[AuthAttr]
    public function group(string $prefix = ''): Response|string
    {
        $ctx = $this->context();
        if ($ctx instanceof Response) return $ctx;
        [$store, $lang] = $ctx;
        $entries = $this->entries($lang, static fn (string $key): bool => $key !== '' && self::groupOfKey($key) === $prefix);
        if ($entries === []) return new Response(Lang::t('error.not_found'), 404);
        $label = self::groupLabel($prefix);
        return $this->page('text/group', Lang::t('text.heading') . ': ' . $label, '/text/group/' . $prefix, [
            'entries' => $entries, 'lang' => $lang, 'languages' => $store->languages(), 'prefix' => $prefix, 'label' => $label, 'q' => '',
        ]);
    }

    #[AuthAttr]
    public function search(): Response|string
    {
        $ctx = $this->context();
        if ($ctx instanceof Response) return $ctx;
        [$store, $lang] = $ctx;
        $q = trim((string) ($this->request->get['q'] ?? ''));
        if ($q === '' || mb_strlen($q) > 100 || !mb_check_encoding($q, 'UTF-8')) {
            return new Response($this->esc(Lang::t('text.err_search')), 422);
        }
        $needle = mb_strtolower($q);
        $effective = $this->effective($lang);
        $base = Lang::base();
        $entries = $this->entries($lang, static function (string $key) use ($needle, $effective, $base): bool {
            return str_contains(mb_strtolower($key), $needle)
                || str_contains(mb_strtolower((string) ($effective[$key] ?? '')), $needle)
                || str_contains(mb_strtolower((string) ($base[$key] ?? '')), $needle);
        });
        return $this->page('text/group', Lang::t('text.heading'), '/text/search', [
            'entries' => array_slice($entries, 0, self::SEARCH_LIMIT), 'lang' => $lang,
            'languages' => $store->languages(), 'prefix' => '', 'q' => $q,
        ]);
    }

    /** Save edited strings: either `values[key]` for a group form, or one `key` and `value`. */
    #[AuthAttr] #[Post]
    public function save(): Response
    {
        $ctx = $this->context(post: true);
        if ($ctx instanceof Response) return $ctx;
        [$store, $lang] = $ctx;
        $values = $this->request->post['values'] ?? null;
        if (!is_array($values)) {
            $values = [(string) ($this->request->post['key'] ?? '') => $this->request->post['value'] ?? ''];
        }
        foreach ($values as $k => $v) {
            if (!is_string($v)) return new Response($this->esc(Lang::t('text.err_key')), 422);
        }
        $result = $store->import($lang, array_map('strval', $values));
        if ($result['error'] !== null) return new Response($this->esc($result['error']), 422);
        $this->publish($store);
        \App\ActivityLog::by($this->db, $this->session, 'text.save', $lang . ': ' . count($values) . ' strings');
        $first = (string) array_key_first($values);
        $to = '/text/group/' . ($first === '' ? '' : self::groupOfKey($first)) . '?lang=' . $lang . '&saved=1';
        return Response::redirect($first === '' ? '/text?lang=' . $lang : $to);
    }

    #[AuthAttr]
    public function languages(): Response|string
    {
        $ctx = $this->context();
        if ($ctx instanceof Response) return $ctx;
        [$store, $lang] = $ctx;
        return $this->page('text/languages', Lang::t('text.manage_languages'), '/text/languages', [
            'languages' => $store->languages(), 'lang' => $lang, 'default' => $this->defaultLang(),
        ]);
    }

    #[AuthAttr] #[Post]
    public function addlanguage(): Response
    {
        $ctx = $this->context(post: true, needLang: false);
        if ($ctx instanceof Response) return $ctx;
        [$store] = $ctx;
        $err = $store->addLanguage(
            (string) ($this->request->post['code'] ?? ''),
            trim((string) ($this->request->post['name'] ?? '')),
            (string) ($this->request->post['dir'] ?? 'ltr'),
        );
        if ($err !== null) return new Response($this->esc($err), 422);
        $this->publish($store);
        \App\ActivityLog::by($this->db, $this->session, 'text.addlanguage', (string) ($this->request->post['code'] ?? ''));
        return \App\Saved::to('/text/languages', 'added');
    }

    #[AuthAttr] #[Post]
    public function removelanguage(): Response
    {
        $ctx = $this->context(post: true, needLang: false);
        if ($ctx instanceof Response) return $ctx;
        [$store] = $ctx;
        $code = (string) ($this->request->post['code'] ?? '');
        if ($code === $this->defaultLang()) return new Response($this->esc(Lang::t('text.err_remove_default')), 422);
        if (!\App\Confirm::given($this->request)) {
            return new Response(\App\Confirm::page($this->view, $this->request, $this->session, $this->app, 'text.confirm_remove_language', 'text.remove', '/text/languages', true, ['code' => mb_substr($code, 0, 20)]));
        }
        $err = $store->removeLanguage($code);
        if ($err !== null) return new Response($this->esc($err), 422);
        $this->publish($store);
        \App\ActivityLog::by($this->db, $this->session, 'text.removelanguage', $code);
        return \App\Saved::to('/text/languages', 'removed');
    }

    /** Every string for one language (built-in plus the owner's changes) as JSON. */
    #[AuthAttr]
    public function export(): Response
    {
        $ctx = $this->context();
        if ($ctx instanceof Response) return $ctx;
        [, $lang] = $ctx;
        $out = [];
        foreach ($this->effective($lang) as $key => $text) {
            if (is_string($text) && $key[0] !== '_') $out[$key] = $text;
        }
        return new Response(
            (string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG),
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="kiption-text-' . $lang . '.json"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    #[AuthAttr] #[Post]
    public function import(): Response
    {
        $ctx = $this->context(post: true);
        if ($ctx instanceof Response) return $ctx;
        [$store, $lang] = $ctx;
        $file = $this->request->file('file');
        if ($file === null) return new Response($this->esc(Lang::t('text.err_import_none')), 422);
        if ((int) ($file['size'] ?? 0) > self::IMPORT_MAX_BYTES || (int) ($file['size'] ?? 0) < 2) {
            return new Response($this->esc(Lang::t('text.err_import_size')), 422);
        }
        $raw = file_get_contents((string) $file['tmp_name'], false, null, 0, self::IMPORT_MAX_BYTES + 1);
        if ($raw === false || strlen($raw) > self::IMPORT_MAX_BYTES) {
            return new Response($this->esc(Lang::t('text.err_import_size')), 422);
        }
        $data = json_decode($raw, true, 4);
        $shapeError = new Response($this->esc(Lang::t('text.err_import_shape')), 422);
        if (!is_array($data) || $data === [] || array_is_list($data) || count($data) > self::IMPORT_MAX_ENTRIES) return $shapeError;
        foreach ($data as $k => $v) {
            if (!is_string($k) || !is_string($v)) return $shapeError;
        }
        $result = $store->import($lang, $data);
        if ($result['error'] !== null) return new Response($this->esc($result['error']), 422);
        $this->publish($store);
        \App\ActivityLog::by($this->db, $this->session, 'text.import', $lang . ': ' . count($data) . ' strings');
        return \App\Saved::to('/text?lang=' . $lang);
    }

    /** Admin gate, then CSRF for writes, then the language in play. @return array{0: Store, 1: string}|Response */
    private function context(bool $post = false, bool $needLang = true): array|Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $store = new Store($this->db);
        if (!$needLang) return [$store, ''];
        $lang = (string) ($post ? ($this->request->post['lang'] ?? '') : ($this->request->get['lang'] ?? ''));
        if ($lang === '') $lang = $this->defaultLang();
        if (!$store->has($lang)) return new Response($this->esc(Lang::t('text.err_language')), $post ? 422 : 404);
        return [$store, $lang];
    }

    private function defaultLang(): string
    {
        $lang = (string) $this->app->config('ui_lang', 'en');
        return (new Store($this->db))->has($lang) ? $lang : 'en';
    }

    /** The map the site shows for a language right now (built-in plus the owner's rows). */
    private function effective(string $lang): array
    {
        return array_replace(Lang::builtin($lang), (new Store($this->db))->rows($lang));
    }

    /** @param callable(string): bool $keep @return list<array{key: string, original: string, value: string, changed: bool, names: list<string>, multiline: bool}> */
    private function entries(string $lang, callable $keep): array
    {
        $rows = (new Store($this->db))->rows($lang);
        $builtin = Lang::builtin($lang);
        $out = [];
        foreach (Lang::base() as $key => $en) {
            if (!is_string($en) || $key[0] === '_' || !$keep((string) $key)) continue;
            $out[] = [
                'key' => (string) $key,
                'original' => (string) ($builtin[$key] ?? $en),
                'value' => $rows[$key] ?? (string) ($builtin[$key] ?? $en),
                'changed' => isset($rows[$key]),
                'names' => Rules::placeholders($en),
                'multiline' => str_contains($en, "\n") || mb_strlen($en) > 80 || \App\Notices::isMessage((string) $key),
            ];
        }
        return $out;
    }

    /** The name an owner reads for a group; its code when no name is set. */
    public static function groupLabel(string $code): string
    {
        $key = 'text.group.' . $code;
        $label = Lang::t($key);
        return $label === $key || $label === '' ? $code : $label;
    }

    public static function groupOfKey(string $key): string
    {
        $dot = strpos($key, '.');
        return $dot === false ? $key : substr($key, 0, $dot);
    }

    /** Rebuild the compiled files and clear cached pages after any write. */
    private function publish(Store $store): void
    {
        $cfg = array_filter([
            'site_text_dir' => $this->app->config('site_text_dir'),
            'data_dir' => $this->app->config('data_dir'),
        ], static fn ($v): bool => is_string($v) && $v !== '');
        // A save that cannot reach the live site must not look like a success.
        if (Compiler::compile($this->db, Boot::dir($cfg)) === false) {
            throw new \RuntimeException("Site text saved but the compiled folder could not be written");
        }
        CachePurge::run($this->app, $this->db);
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function page(string $template, string $title, string $canonical, array $vars): string
    {
        return $this->view->render($template, $vars + [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical($canonical)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true,
        ]);
    }
}
