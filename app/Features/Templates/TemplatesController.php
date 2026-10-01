<?php // app/Features/Templates/TemplatesController.php
namespace App\Features\Templates;
use App\Templates;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** Admin editor for the mail templates the call sites resolve through
 *  Templates::get. Opening a list or edit page seeds the default rows
 *  (INSERT OR IGNORE, idempotent) so the surface always has something to
 *  edit; a saved override swaps subject and body at every later send while
 *  the fallthrough keeps unedited names byte-identical. Admin-only by the
 *  SQL role check (the NavController idiom): these mails are the archive's
 *  site-wide voice, not moderator territory. */
final class TemplatesController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        Templates::seedDefaults($this->db);
        return $this->view->render('templates/index', [
            'title' => \App\Lang::t('templates.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('templates.heading'))->withCanonical('/templates')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'rows' => $this->db->all('SELECT name, subject FROM mail_templates ORDER BY name'),
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
        ]);
    }

    #[AuthAttr]
    public function edit(string $name): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        Templates::seedDefaults($this->db); // deep links to a known name work on a fresh database
        $row = $this->find($name);
        if ($row === null) return new Response('Page not found', 404);
        return $this->form($row['name'], $row['subject'], $row['body'], null);
    }

    #[AuthAttr] #[Post]
    public function update(string $name): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $row = $this->find($name);
        if ($row === null) return new Response('Page not found', 404);
        $subject = trim($this->request->postStr('subject'));
        $body = $this->request->postStr('body');
        if ($subject === '') {
            return new Response($this->form($row['name'], $subject, $body, 'Subject must not be empty.'), 422);
        }
        $this->db->query('UPDATE mail_templates SET subject = ?, body = ? WHERE name = ?',
            [$subject, $body, $row['name']]);
        return Response::redirect('/templates');
    }

    private function find(string $name): ?array
    {
        if (!preg_match('#^[a-z0-9_]+$#', $name)) return null;
        $row = $this->db->one('SELECT name, subject, body FROM mail_templates WHERE name = ?', [$name]);
        return $row === null ? null : [
            'name' => (string) $row['name'], 'subject' => (string) $row['subject'], 'body' => (string) $row['body'],
        ];
    }

    private function admin(): bool
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$me])['c'] === 1;
    }

    private function form(string $name, string $subject, string $body, ?string $error): string
    {
        return $this->view->render('templates/form', [
            'title' => \App\Lang::t('templates.edit_heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('templates.edit_heading'))->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'name' => $name, 'subject' => $subject, 'body' => $body, 'error' => $error,
            'vocabulary' => Templates::VOCABULARY[$name] ?? [],
            'csrf' => $this->session->csrfToken(), 'loggedIn' => true,
        ]);
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
