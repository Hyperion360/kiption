<?php // app/Features/Images/ImagesController.php
namespace App\Features\Images;
use Kip\{App, Database, Http\Request, Http\Response, Session, Storage, UploadException, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** Admin-only image library over the uploads dir. The dir IS the catalog
 *  (Storage's server-generated hex names; no DB rows anywhere), so the
 *  listing merges a glob with ONE UNION query of avatar + cover references
 *  for the in-use set, and a delete refuses (409) anything still referenced.
 *  No DB writes at all: uploads land through Storage, deletes unlink, and
 *  the reference tables belong to the avatar/cover owners. Storage resolves
 *  from the container (never constructed inline) so the configured caps and
 *  the test mover seam both apply. */
final class ImagesController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app, private Storage $storage,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $inUse = $this->inUseNames();
        $files = [];
        $total = 0;
        foreach (glob($this->uploadsDir() . '/*', GLOB_MARK) ?: [] as $f) {
            if (substr($f, -1) === '/') continue; // GLOB_MARK flags directories with a trailing slash
            $bytes = (int) @filesize($f);
            $total += $bytes;
            $name = basename($f);
            $files[] = ['name' => $name, 'human' => self::humanSize($bytes), 'inUse' => isset($inUse[$name])];
        }
        return $this->view->render('images/index', [
            'title' => \App\Lang::t('images.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('images.heading'))->withCanonical('/images')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'files' => $files,
            'totalHuman' => self::humanSize($total),
            'count' => count($files),
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true, // the gate above already proved it
        ]);
    }

    /** The avatar idiom: Storage's whole check chain (size twice, DENY_EXT,
     *  finfo MIME) renders rejections as a plain 422. */
    #[AuthAttr] #[Post]
    public function upload(): Response
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $file = $this->request->file('file');
        if ($file === null) {
            return new Response('Upload rejected: choose a PNG, JPG, WEBP, or GIF under 2 MiB.', 422);
        }
        try {
            $this->storage->put($file);
        } catch (UploadException) {
            return new Response('Upload rejected: choose a PNG, JPG, WEBP, or GIF under 2 MiB.', 422);
        }
        return Response::redirect('/images');
    }

    /** Basename-confined unlink: any path separator (or empty name) is a 422,
     *  an in-use file (an avatar or cover still points at it) is a 409, and
     *  only then does the unlink run. A name is NEVER treated as a path. */
    #[AuthAttr] #[Post]
    public function delete(): Response
    {
        if (!$this->admin()) return new Response('Forbidden', 403);
        $name = (string) ($this->request->post['name'] ?? '');
        if ($name === '' || strpbrk($name, "/\\") !== false || $name !== basename($name)) {
            return new Response('Invalid file name.', 422);
        }
        if (isset($this->inUseNames()[$name])) {
            return new Response('File is in use by an avatar or story cover.', 409);
        }
        $target = $this->uploadsDir() . '/' . $name;
        if (!is_file($target) || !@unlink($target)) return new Response('Page not found', 404);
        return Response::redirect('/images');
    }

    /** The whole in-use set from ONE statement: both reference arms (avatar,
     *  cover) fold through the UNION so the listing pays a single query.
     *  @return array<string, true> basenames of every referenced file */
    private function inUseNames(): array
    {
        $set = [];
        $rows = $this->db->all('SELECT avatar_path FROM users WHERE avatar_path IS NOT NULL UNION SELECT cover_path FROM stories WHERE cover_path IS NOT NULL');
        foreach ($rows as $r) {
            $p = (string) (array_values($r)[0] ?? '');
            if ($p !== '') $set[basename($p)] = true;
        }
        return $set;
    }

    private function uploadsDir(): string
    {
        // basename()/this confinement keeps every read and unlink inside the
        // configured dir (the AccountController avatar-unlink idiom).
        return rtrim((string) ($this->app->config('uploads')['dir'] ?? dirname(__DIR__, 3) . '/public/uploads'), '/');
    }

    /** The config cap is 2 MiB, so three tiers cover every storable file. */
    private static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KiB';
        return round($bytes / 1048576, 1) . ' MiB';
    }

    /** The SQL admin gate (role = 'admin' iff is_admin = 1 makes one check enough). */
    private function admin(): bool
    {
        $me = (int) ($this->session->get('user_id') ?? 0);
        return (int) $this->db->one("SELECT COUNT(*) c FROM users WHERE id = ? AND role = 'admin'", [$me])['c'] === 1;
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
