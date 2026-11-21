<?php // app/Features/Images/ImagesController.php
namespace App\Features\Images;
use App\Adminness;
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
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
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
        // The storage line renders only when the site administrator set a cap; an
        // uncapped archive renders the page exactly as before.
        $quota = \App\StorageQuota::forApp($this->app);
        $storage = null;
        if ($quota->cap() > 0) {
            // The library already measured every stored file above. Show that
            // actual total without a second content query; writes still use
            // StorageQuota's atomic counter to enforce the cap.
            $used = $total;
            $storage = ['used' => \App\StorageQuota::human($used), 'total' => \App\StorageQuota::human($quota->cap()),
                'warn' => 10 * $used >= 9 * $quota->cap(), 'full' => $used >= $quota->cap()];
        }
        return $this->view->render('images/index', [
            'title' => \App\Lang::t('images.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('images.heading'))->withCanonical('/images')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'files' => $files,
            'totalHuman' => self::humanSize($total),
            'count' => count($files),
            'storage' => $storage,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true, // the gate above already proved it
        ]);
    }

    /** The avatar idiom: Storage's whole check chain (size twice, DENY_EXT,
     *  finfo MIME) renders rejections as a plain 422. At the storage cap the
     *  upload is refused before anything is stored. */
    #[AuthAttr] #[Post]
    public function upload(): Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        if (!\App\Settings::on('uploads_on')) return new Response(\App\Lang::t('uploads.off'), 403);
        $file = $this->request->file('file');
        if ($file === null) {
            return new Response(\App\Lang::t('images.err_upload'), 422);
        }
        try {
            $path = \App\StorageQuota::forApp($this->app)->putUnderCap($file, true, $this->storage);
        } catch (UploadException) {
            return new Response(\App\Lang::t('images.err_upload'), 422);
        }
        if ($path === null) return new Response(\App\Lang::t('images.storage_full'), 422);
        \App\ActivityLog::by($this->db, $this->session, 'images.upload', (string) ($file['name'] ?? ''));
        return \App\Saved::to('/images', 'added');
    }

    /** Basename-confined unlink: any path separator (or empty name) is a 422,
     *  an in-use file (an avatar or cover still points at it) is a 409, and
     *  only then does the unlink run. A name is NEVER treated as a path. */
    #[AuthAttr] #[Post]
    public function delete(): Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $name = (string) ($this->request->post['name'] ?? '');
        if ($name === '' || strpbrk($name, "/\\") !== false || $name !== basename($name)) {
            return new Response(\App\Lang::t('images.err_name'), 422);
        }
        if (isset($this->inUseNames()[$name])) {
            return new Response(\App\Lang::t('images.err_in_use'), 409);
        }
        if (\App\ChapterImageUse::chaptersUsing($this->db, '/uploads/' . $name) !== []) {
            return new Response(\App\Lang::t('images.err_in_chapter'), 409);
        }
        if (!\App\Confirm::given($this->request)) {
            return new Response(\App\Confirm::page($this->view, $this->request, $this->session, $this->app, 'images.confirm_delete', 'common.delete', '/images', true, ['name' => $name]));
        }
        $target = $this->uploadsDir() . '/' . $name;
        if (!is_file($target)) return new Response(\App\Lang::t('error.not_found'), 404);
        $bytes = (int) @filesize($target);
        if (!@unlink($target)) return new Response(\App\Lang::t('error.not_found'), 404);
        \App\StorageQuota::forApp($this->app)->release($bytes); // the counter drops with the file
        \App\ActivityLog::by($this->db, $this->session, 'images.delete', $name);
        return \App\Saved::to('/images', 'removed');
    }

    /** The shared reference check (App\UploadRefs), so the library and the
     *  storage quota can never disagree about what "in use" means.
     *  @return array<string, true> basenames of every referenced file */
    private function inUseNames(): array
    {
        return \App\UploadRefs::names($this->db);
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

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }
}
