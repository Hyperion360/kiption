<?php // app/Features/Settings/SettingsController.php
namespace App\Features\Settings;
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
        if (!$this->admin()) return new Response('Forbidden', 403);
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
        if (!$this->admin()) return new Response('Forbidden', 403);
        $submitted = [
            'site_name' => (string) ($this->request->post['site_name'] ?? ''),
            'registration_mode' => (string) ($this->request->post['registration_mode'] ?? ''),
            'validation_required' => (string) ($this->request->post['validation_required'] ?? '0'),
            'items_per_page' => (string) ($this->request->post['items_per_page'] ?? '20'),
            'powered_by' => (string) ($this->request->post['powered_by'] ?? '0'),
            'skin' => (string) ($this->request->post['skin'] ?? \App\Skins::DEFAULT),
        ];
        $invalid = [];
        foreach ($submitted as $k => $v) {
            try { \App\Settings::put($k, $v); } catch (\InvalidArgumentException) { $invalid[] = $k; }
        }
        if ($invalid !== []) return new Response('Invalid settings: ' . implode(', ', $invalid), 422);
        $this->purge();
        return Response::redirect('/settings');
    }

    /** What each field shows: the DB row when one exists (the operator's
     *  choice), the effective config value otherwise. Bool keys normalize to
     *  '1'/'0' so the checkbox state never depends on the config's type. */
    private function current(): array
    {
        $all = \App\Settings::all();
        $bool = static fn (mixed $v): string => ($v === true || $v === '1' || $v === 1) ? '1' : '0';
        return [
            'site_name' => (string) ($all['site_name'] ?? $this->app->config('site_name', 'Kiption')),
            'registration_mode' => (string) ($all['registration_mode'] ?? $this->app->config('registration_mode', 'verify')),
            'validation_required' => (string) ($all['validation_required'] ?? $bool($this->app->config('validation_required'))),
            'items_per_page' => (string) ($all['items_per_page'] ?? $this->app->config('items_per_page', 20)),
            'powered_by' => (string) ($all['powered_by'] ?? $bool($this->app->config('powered_by'))),
            'skin' => (string) ($all['skin'] ?? $this->app->config('skin', \App\Skins::DEFAULT)),
        ];
    }

    private function admin(): bool
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$me])['c'] === 1;
    }

    /** The full purge set (the FeaturesController::purge precedent, verbatim):
     *  Builder::prune (the whole static layer, maintenance marker preserved,
     *  plus the framework page cache file), then the sitemap refreshed under
     *  the same public_dir + base_url guard as Builder's own rebuild pass. */
    private function purge(): void
    {
        $cacheDir = \App\StaticCache\Cache::configuredDir($this->app);
        \App\StaticCache\Builder::prune($cacheDir, (string) $this->app->config('app_dir', dirname(__DIR__, 2)));
        $publicDir = $this->app->config('public_dir');
        $baseUrl = rtrim((string) $this->app->config('base_url', ''), '/');
        if ($publicDir !== null && $baseUrl !== '') {
            \App\Seo\Sitemap::writeAll($this->db, (string) $publicDir, $baseUrl, []);
        }
    }
}
