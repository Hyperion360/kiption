<?php // app/src/Controllers/AccountController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, Storage, View};
use Kip\Routing\{Auth as AuthAttr, Post};

final class AccountController
{
    public function __construct(
        private View $view,
        private Request $request,
        private Database $db,
        private Session $session,
        private App $app,
        private Storage $storage,
    ) {}

    #[AuthAttr]
    public function index(): string
    {
        $userId = (int) $this->session->get('user_id');
        $rows = $this->db->all(
            "SELECT '0me' AS k, u.penname AS a, u.email AS b, u.role AS c, u.avatar_path AS d, NULL AS e
             FROM users u WHERE u.id = ?
             UNION ALL
             SELECT '1follow', CAST(f.author_id AS TEXT), u2.penname, f.notify_mode,
                     CAST((SELECT COUNT(*) FROM stories s WHERE s.author_id = f.author_id AND s.deleted_at IS NULL AND s.validated = 1) AS TEXT), NULL
             FROM follows f JOIN users u2 ON u2.id = f.author_id WHERE f.follower_id = ?
             UNION ALL
             SELECT '4story', s.slug, s.title, CAST(s.validated AS TEXT), NULL, s.updated_at
             FROM stories s WHERE s.author_id = ? AND s.deleted_at IS NULL
             ORDER BY k, e LIMIT 250", [$userId, $userId, $userId]);
        $me = null;
        $stories = [];
        $following = [];
        foreach ($rows as $r) {
            if ($r['k'] === '0me') { $me = $r; }
            elseif ($r['k'] === '1follow') { $following[] = $r; }
            else { $stories[] = $r; }
        }
        return $this->view->render('account/show', [
            'title' => 'Your account',
            'head' => $this->head()->withTitle('Your account')->withCanonical('/account')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'me' => $me,
            'stories' => $stories,
            'following' => $following,
            'csrf' => $this->session->csrfToken(),
        ]);
    }

    #[AuthAttr] #[Post]
    public function avatar(): Response
    {
        $file = $this->request->file('avatar');
        if ($file === null) {
            return new Response('Avatar rejected: choose a PNG, JPG, WEBP, or GIF under 2 MiB.', 422);
        }
        try {
            $path = $this->storage->put($file);
        } catch (\Kip\UploadException) {
            return new Response('Avatar rejected: choose a PNG, JPG, WEBP, or GIF under 2 MiB.', 422);
        }
        $userId = (int) $this->session->get('user_id');
        $old = $this->db->one('SELECT avatar_path FROM users WHERE id = ?', [$userId]);
        $this->db->query('UPDATE users SET avatar_path = ? WHERE id = ?', [$path, $userId]);
        if ($old !== null && $old['avatar_path'] !== null) {
            // basename() keeps the delete inside the configured uploads dir.
            $dir = rtrim((string) ($this->app->config('uploads')['dir'] ?? dirname(__DIR__, 3) . '/public/uploads'), '/');
            @unlink($dir . '/' . basename((string) $old['avatar_path']));
        }
        return Response::redirect('/account');
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
