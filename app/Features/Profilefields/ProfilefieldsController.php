<?php // app/Features/Profilefields/ProfilefieldsController.php
namespace App\Features\Profilefields;
use App\AdminScreen;
use Kip\Http\Response;
use Kip\Routing\{Auth as AuthAttr, Post};

/** Admin screen for the author profile fields: add, rename, reorder, delete
 *  (deleting asks first, on its own page). Same epoch-checked admin gate as
 *  the invites screen, so a guest or a member gets a 403. Public profile
 *  pages are purged after every change. */
final class ProfilefieldsController extends AdminScreen
{
    public function index(): Response|string
    {
        if ($r = $this->deny()) return $r;
        return $this->form(null, 200);
    }

    #[AuthAttr] #[Post]
    public function add(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $err = $this->fields()->add($this->request->postStr('label'), $this->request->postStr('type'));
        return $this->done($err, 'add', $this->request->postStr('label'));
    }

    #[AuthAttr] #[Post]
    public function rename(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        return $this->done($this->fields()->rename((int) $id, $this->request->postStr('label')), 'rename', $this->request->postStr('label'));
    }

    #[AuthAttr] #[Post]
    public function move(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        return $this->done($this->fields()->move((int) $id, $this->request->postStr('dir')), 'move', 'field ' . (int) $id . ' ' . $this->request->postStr('dir'));
    }

    /** The confirm step: nothing is deleted by opening it. */
    public function delete(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $field = $this->fields()->find((int) $id);
        if ($field === null) return new Response(\App\Lang::t('error.not_found'), 404);
        return $this->page('profilefields/delete', 'profilefields.delete_heading', '/profilefields/delete/' . (int) $id, ['field' => $field]);
    }

    #[AuthAttr] #[Post]
    public function destroy(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $label = (string) ($this->fields()->find((int) $id)['label'] ?? '');
        return $this->done($this->fields()->delete((int) $id) ? null : 'profilefields.err_missing', 'delete', $label);
    }

    private function done(?string $err, string $action, string $detail): Response|string
    {
        if ($err !== null) return $this->form($err, 422);
        \App\ActivityLog::record($this->db, (int) $this->session->get('user_id'), 'profilefields.' . $action, $detail);
        $this->purge();
        return \App\Saved::to('/profilefields', \App\CachePurge::savedCode($this->app));
    }

    private function fields(): \App\ProfileFields
    {
        return new \App\ProfileFields($this->db);
    }

    private function form(?string $error, int $status): Response
    {
        return new Response($this->page('profilefields/index', 'profilefields.heading', '/profilefields',
            ['fields' => $this->fields()->all(), 'error' => $error]), $status);
    }
}
