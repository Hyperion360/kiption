<?php // app/Features/Look/LookController.php
namespace App\Features\Look;
use App\{Adminness, CachePurge, Lang};
use App\Look\{Boot, Color, Compiler, Palette, Presets, Rules, Store};
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** The colors and layout editor (/look): the archive owner picks three colors
 *  and four layout choices, previews them, saves, and resets. Admin only, CSRF
 *  and POST for every write. Nothing the owner posts reaches the stylesheet
 *  except through App\Look\Rules, and the stylesheet is built from literals. */
final class LookController
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
        return $this->page(Store::get() ?? Compiler::defaults(), []);
    }

    /** The two panes for the values as posted, saved or not. */
    #[AuthAttr] #[Post]
    public function preview(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $r = Rules::check($this->typed());
        $fragment = ($this->request->post['fragment'] ?? '') === '1';
        if ($fragment) {
            $body = $r['errors'] === []
                ? ['ok' => true, 'vars' => $this->vars($r['values']), 'presets' => $this->presetsOf($r['values'])]
                : ['ok' => false, 'errors' => $this->messages($r['errors'])];
            return new Response(
                (string) json_encode($body, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES),
                $r['errors'] === [] ? 200 : 422,
                ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store'],
            );
        }
        return $r['errors'] === [] ? $this->page($r['values'], []) : $this->page($this->form(), $r['errors'], 422);
    }

    #[AuthAttr] #[Post]
    public function save(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $r = Rules::check($this->typed());
        if ($r['errors'] !== []) return $this->page($this->form(), $r['errors'], 422);
        $before = Store::get();
        Store::put($r['values']);
        if (!Compiler::write($r['values'], $this->dir())) {
            $before === null ? Store::clear() : Store::put($before);
            return $this->page($this->form(), ['look' => ['look.err_write', []]], 503);
        }
        \App\ActivityLog::by($this->db, $this->session, 'look.save', Compiler::revision($r['values']));
        CachePurge::run($this->app, $this->db);
        return \App\Saved::to('/look', CachePurge::savedCode($this->app));
    }

    #[AuthAttr] #[Post]
    public function reset(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        if (!\App\Confirm::given($this->request)) {
            return new Response(\App\Confirm::page($this->view, $this->request, $this->session, $this->app, 'look.confirm_reset', 'look.reset', '/look', true));
        }
        Store::clear();
        @unlink($this->dir() . '/' . Compiler::FILE);
        \App\ActivityLog::by($this->db, $this->session, 'look.reset');
        CachePurge::run($this->app, $this->db);
        return \App\Saved::to('/look', CachePurge::savedCode($this->app, 'removed'));
    }

    /** The stylesheet. Public, no session. Rebuilt from the saved row when the
     *  file is missing or stale, so a restore or a new server needs no step. */
    public function css(): Response
    {
        $values = Store::get();
        if ($values === null) return new Response('', 404, ['Cache-Control' => 'private, no-store']);
        $rev = Compiler::revision($values);
        $file = $this->dir() . '/' . Compiler::FILE;
        $css = is_file($file) ? (string) @file_get_contents($file) : '';
        if (!str_starts_with($css, '/* look ' . $rev . ' */')) {
            $css = Compiler::css($values);
            Compiler::write($values, $this->dir()); // best effort: an unwritable folder still serves the page
        }
        $fresh = (string) ($this->request->get['v'] ?? '') === $rev;
        return new Response($css, 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => $fresh ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
        ]);
    }

    private function dir(): string
    {
        return Boot::dir(['site_look_dir' => $this->app->config('site_look_dir'), 'data_dir' => $this->app->config('data_dir')]);
    }

    /** The posted fields that matter, as strings only (anything else fails Rules). */
    private function typed(): array
    {
        $out = [];
        foreach (array_merge(Rules::COLORS, array_keys(Presets::GROUPS)) as $field) {
            if (array_key_exists($field, $this->request->post)) $out[$field] = $this->request->post[$field];
        }
        return $out;
    }

    /** The form as the owner left it, so an error keeps what they typed. */
    private function form(): array
    {
        $d = Compiler::defaults();
        $out = [];
        foreach ($d as $field => $default) {
            $v = $this->request->post[$field] ?? $default;
            $out[$field] = is_string($v) ? mb_substr($v, 0, 20) : $default;
        }
        return $out;
    }

    /** @return array{light: array<string,string>, night: array<string,string>} */
    private function vars(array $values): array
    {
        return Palette::derive(Color::parse($values['accent']), Color::parse($values['bg']), Color::parse($values['ink']));
    }

    private function presetsOf(array $values): array
    {
        $out = [];
        foreach (array_keys(Presets::GROUPS) as $g) $out[$g] = $values[$g];
        return $out;
    }

    /** @param array<string, array{0:string, 1:array<string,string>}> $errors @return array<string,string> */
    private function messages(array $errors): array
    {
        $out = [];
        foreach ($errors as $field => [$key, $params]) $out[$field] = Lang::t($key, $params);
        return $out;
    }

    /** @param array<string,string> $values @param array<string, array{0:string, 1:array<string,string>}> $errors */
    private function page(array $values, array $errors, int $status = 200): Response|string
    {
        // A form that fails Rules (posted garbage) still needs panes: fall back to the built-in look.
        $paint = Rules::check($values)['errors'] === [] ? $values : Compiler::defaults();
        $title = Lang::t('look.heading');
        $html = $this->view->render('look/index', [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical('/look')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true,
            'values' => $values,
            'errors' => $this->messages($errors),
            'panes' => $this->vars($paint),
            'presets' => $this->presetsOf($paint),
            'previewUrl' => '/look/preview',
        ]);
        return $status === 200 ? $html : new Response($html, $status);
    }
}
