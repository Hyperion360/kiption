<?php // app/Features/Profilefields/ProfilefieldsController.php
namespace App\Features\Profilefields;
use App\Adminness;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\Post;

/** Admin screen for the author profile fields: add, rename, reorder, delete
 *  (deleting asks first, on its own page). Same epoch-checked admin gate as
 *  the invites screen, so a guest or a member gets a 403. Public profile
 *  pages are purged after every change. */
final class ProfilefieldsController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    public function index(): Response|string
    {
        if (($gate = $this->gate()) !== null) return $gate;
        return $this->page(null, 200);
    }

    #[Post]
    public function add(): Response|string
    {
        if (($gate = $this->gate()) !== null) return $gate;
        $err = $this->fields()->add($this->request->postStr('label'), $this->request->postStr('type'));
        return $this->done($err, 'add', $this->request->postStr('label'));
    }

    #[Post]
    public function rename(string $id): Response|string
    {
        if (($gate = $this->gate()) !== null) return $gate;
        return $this->done($this->fields()->rename((int) $id, $this->request->postStr('label')), 'rename', $this->request->postStr('label'));
    }

    #[Post]
    public function move(string $id): Response|string
    {
        if (($gate = $this->gate()) !== null) return $gate;
        return $this->done($this->fields()->move((int) $id, $this->request->postStr('dir')), 'move', 'field ' . (int) $id . ' ' . $this->request->postStr('dir'));
    }

    /** The confirm step: nothing is deleted by opening it. */
    public function delete(string $id): Response|string
    {
        if (($gate = $this->gate()) !== null) return $gate;
        $field = $this->fields()->find((int) $id);
        if ($field === null) return new Response('Page not found', 404);
        $title = \App\Lang::t('profilefields.delete_heading');
        return $this->view->render('profilefields/delete', ['title' => $title, 'head' => $this->head($title, '/profilefields/delete/' . (int) $id),
            'csrf' => $this->session->csrfToken(), 'loggedIn' => true, 'isAdmin' => true, 'field' => $field]);
    }

    #[Post]
    public function destroy(string $id): Response|string
    {
        if (($gate = $this->gate()) !== null) return $gate;
        $label = (string) ($this->fields()->find((int) $id)['label'] ?? '');
        return $this->done($this->fields()->delete((int) $id) ? null : 'profilefields.err_missing', 'delete', $label);
    }

    private function done(?string $err, string $action, string $detail): Response|string
    {
        if ($err !== null) return $this->page($err, 422);
        \App\ActivityLog::record($this->db, (int) $this->session->get('user_id'), 'profilefields.' . $action, $detail);
        \App\StaticCache\Cache::configured($this->app)->purgeAll();
        return Response::redirect('/profilefields');
    }

    private function gate(): ?Response
    {
        $g = Adminness::requireAdmin($this->db, $this->session);
        return $g instanceof Response ? $g : null;
    }

    private function fields(): \App\ProfileFields
    {
        return new \App\ProfileFields($this->db);
    }

    private function page(?string $error, int $status): Response
    {
        $title = \App\Lang::t('profilefields.heading');
        $html = $this->view->render('profilefields/index', ['title' => $title, 'head' => $this->head($title, '/profilefields'),
            'csrf' => $this->session->csrfToken(), 'loggedIn' => true, 'isAdmin' => true,
            'fields' => $this->fields()->all(), 'error' => $error]);
        return new Response($html, $status);
    }

    private function head(string $title, string $canonical): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        )->withTitle($title)->withCanonical($canonical)->withNoindex();
    }
}
