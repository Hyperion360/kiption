<?php // app/Features/Settings/SettingsController.php
namespace App\Features\Settings;
use App\Adminness;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** The operator settings board (/settings): day-to-day values (site name,
 *  registration mode, validation, page size, attribution) editable in the
 *  browser instead of by hand in config.php. Settings are site-wide operator
 *  surface, so the gate, the token, the payload shape and the purge set are
 *  the flag board's (FeaturesController) verbatim; only the write differs,
 *  because a settings form carries values instead of a toggle. Every
 *  successful save purges the whole static layer: a renamed site must not
 *  survive in any cached page. */
final class SettingsController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $title = \App\Lang::t('settings.heading');
        return $this->view->render('settings/index', [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical('/settings')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'current' => $this->current(),
            'languages' => array_map(static fn (string $c): array => ['code' => $c, 'name' => \App\Lang::name($c)], \App\Lang::installed()),
            'dateExamples' => \App\Dates::examples(),
            'timeExamples' => \App\Dates::timeExamples(),
            'images' => $this->libraryImages(),
            'skins' => \App\Skins::installed(['app_dir' => (string) $this->app->config('app_dir', dirname(__DIR__, 2))]),
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true, // the gate above already proved it
        ]);
    }

    /** Bool checkboxes never POST when unchecked, so every bool key reads with an explicit '0'
     *  default and every text key with '': absent means OFF/empty, never 'leave unchanged'. */
    #[AuthAttr] #[Post]
    public function save(): Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $submitted = [
            'site_name' => (string) ($this->request->post['site_name'] ?? ''),
            'registration_mode' => (string) ($this->request->post['registration_mode'] ?? ''),
            'validation_required' => (string) ($this->request->post['validation_required'] ?? '0'),
            'items_per_page' => (string) ($this->request->post['items_per_page'] ?? '20'),
            'powered_by' => (string) ($this->request->post['powered_by'] ?? '0'),
            'skin' => (string) ($this->request->post['skin'] ?? \App\Skins::DEFAULT),
            'adult_rta' => (string) ($this->request->post['adult_rta'] ?? ''),
        ];
        // The default language is only written when the form sent it, so an
        // older form or a script that omits it leaves the saved choice alone.
        if (array_key_exists('ui_lang', $this->request->post)) {
            $submitted['ui_lang'] = (string) $this->request->post['ui_lang'];
        }
        // The newer keys: a checkbox that did not post is OFF, a text or choice that did not post
        // falls back to its default, so a stale or partial form can never write junk.
        foreach (\App\Settings::DEFAULTS as $k => $default) {
            $off = \App\Settings::KEYS[$k] === 'bool' ? '0' : $default;
            $submitted[$k] = trim((string) ($this->request->post[$k] ?? $off));
        }
        // The footer, picture and maintenance block only writes when the form sent it
        // (the checkbox is absent when off), so older forms and scripts leave them alone.
        if (array_key_exists('footer_present', $this->request->post)) {
            $post = $this->request->post;
            $lines = \App\FooterLegal::fromLines((string) ($post['footer_links'] ?? ''));
            if ($lines === null) return new Response(\App\Lang::t('settings.err_footer_links'), 422);
            $submitted += [
                'footer_copyright' => trim((string) ($post['footer_copyright'] ?? '')),
                'footer_copyright_url' => trim((string) ($post['footer_copyright_url'] ?? '')),
                'footer_links' => $lines,
                'og_image' => trim((string) ($post['og_image'] ?? '')),
                'maintenance_on' => (string) ($post['maintenance_on'] ?? '0'),
            ];
        }
        $invalid = [];
        $min = (int) $submitted['min_words'];
        $max = (int) $submitted['max_words'];
        if ($min > 0 && $max > 0 && $min > $max) $invalid[] = 'min_words';
        if ($invalid !== []) return new Response(\App\Lang::t('settings.err_invalid') . ' ' . implode(', ', $invalid), 422);
        $changed = [];
        foreach ($submitted as $k => $v) {
            $was = \App\Settings::get($k);
            try { \App\Settings::put($k, $v); } catch (\InvalidArgumentException) { $invalid[] = $k; continue; }
            if ($was !== \App\Settings::get($k)) $changed[] = $k; // names only, never values
        }
        if ($invalid !== []) return new Response(\App\Lang::t('settings.err_invalid') . ' ' . implode(', ', $invalid), 422);
        \App\ActivityLog::record($this->db, (int) $gate['id'], 'settings.save', implode(', ', $changed));
        \App\CachePurge::run($this->app, $this->db);
        return Response::redirect('/settings');
    }

    /** Public paths of the pictures in the image library, for the share-picture choice. @return list<string> */
    private function libraryImages(): array
    {
        $dir = (string) ($this->app->config('uploads', [])['dir'] ?? '');
        $out = [];
        foreach ($dir === '' ? [] : (glob($dir . '/*') ?: []) as $f) {
            if (is_file($f) && preg_match('/\.(png|jpe?g|webp|gif)$/i', $f) === 1) $out[] = '/uploads/' . basename($f);
        }
        sort($out);
        return $out;
    }

    /** What each field shows: the DB row when one exists (the operator's
     *  choice), the effective config value otherwise. Bool keys normalize to
     *  '1'/'0' so the checkbox state never depends on the config's type. */
    private function current(): array
    {
        $all = \App\Settings::all();
        $bool = static fn (mixed $v): string => ($v === true || $v === '1' || $v === 1) ? '1' : '0';
        $fresh = [];
        foreach (\App\Settings::DEFAULTS as $k => $_) $fresh[$k] = \App\Settings::get($k);
        return $fresh + [
            'site_name' => (string) ($all['site_name'] ?? $this->app->config('site_name', 'Kiption')),
            'registration_mode' => (string) ($all['registration_mode'] ?? $this->app->config('registration_mode', 'verify')),
            'validation_required' => (string) ($all['validation_required'] ?? $bool($this->app->config('validation_required'))),
            'items_per_page' => (string) ($all['items_per_page'] ?? $this->app->config('items_per_page', 20)),
            'powered_by' => (string) ($all['powered_by'] ?? $bool($this->app->config('powered_by'))),
            'skin' => (string) ($all['skin'] ?? $this->app->config('skin', \App\Skins::DEFAULT)),
            'adult_rta' => (string) ($all['adult_rta'] ?? $this->app->config('adult_rta', '')),
            'ui_lang' => (string) ($all['ui_lang'] ?? $this->app->config('ui_lang', 'en')),
            'footer_copyright' => (string) ($all['footer_copyright'] ?? $this->app->config('footer_copyright', '')),
            'footer_copyright_url' => (string) ($all['footer_copyright_url'] ?? $this->app->config('footer_copyright_url', '')),
            'footer_links' => \App\FooterLegal::toLines($all['footer_links'] ?? $this->app->config('footer_links', [])),
            'og_image' => (string) ($all['og_image'] ?? $this->app->config('og_image', '')),
            'maintenance_on' => (string) ($all['maintenance_on'] ?? '0'),
        ];
    }
}
