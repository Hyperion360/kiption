<?php // app/Features/Ratings/RatingsController.php
namespace App\Features\Ratings;
use App\{AdminScreen, Lang};
use Kip\Http\Response;
use Kip\Routing\{Auth, Get, Post};

/** The owner's rating manager (admin only; #[Auth] makes the CSRF token
 *  mandatory on every write). Every story must keep a rating, so a rating
 *  that any story uses (soft-deleted ones included, the foreign key sees
 *  them) is never deleted silently: the owner is offered to move those
 *  stories to another rating first. The adult flag drives the age warning
 *  page (AgeGate), with warning_text as the message shown there. */
final class RatingsController extends AdminScreen
{
    #[Auth]
    public function index(): Response|string
    {
        return $this->deny() ?? $this->list(null);
    }

    #[Auth]
    public function edit(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        return $row === null ? new Response(\App\Lang::t('error.not_found'), 404) : $this->editForm($row, null);
    }

    #[Auth] #[Post]
    public function create(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $label = $this->label();
        if ($label === '') return new Response($this->list(Lang::t('ratings.err_label')), 422);
        $position = (int) $this->db->one('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM ratings')['p'];
        $this->db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, ?, ?, ?)',
            [$label, $this->adult(), $this->warning(), $position]);
        $this->purge();
        return Response::redirect('/ratings');
    }

    #[Auth] #[Post]
    public function update(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        if ($row === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $label = $this->label();
        if ($label === '') return new Response($this->editForm($row, Lang::t('ratings.err_label')), 422);
        $this->db->query('UPDATE ratings SET label = ?, is_adult = ?, warning_text = ? WHERE id = ?',
            [$label, $this->adult(), $this->warning(), (int) $row['id']]);
        $this->purge();
        return Response::redirect('/ratings');
    }

    /** Swap with the neighbour above or below. Positions are renumbered 1..n first. */
    #[Auth] #[Post]
    public function move(string $id, string $dir): Response
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        if ($row === null || !in_array($dir, ['up', 'down'], true)) return new Response(\App\Lang::t('error.not_found'), 404);
        $ids = array_map('intval', array_column($this->db->all('SELECT id FROM ratings ORDER BY position, id'), 'id'));
        $at = array_search((int) $row['id'], $ids, true);
        $to = $dir === 'up' ? $at - 1 : $at + 1;
        if (isset($ids[$to])) [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->db->begin();
        try {
            foreach ($ids as $i => $rid) $this->db->query('UPDATE ratings SET position = ? WHERE id = ?', [$i + 1, $rid]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->purge();
        return Response::redirect('/ratings');
    }

    /** GET shows the confirm step; POST deletes, or refuses with the reason. */
    #[Auth] #[Get] #[Post]
    public function delete(string $id): Response|string
    {
        if ($r = $this->deny()) return $r;
        $row = $this->find($id);
        if ($row === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $stories = $this->storyCount((int) $row['id']);
        if ($this->request->method !== 'POST') return $this->confirm($row, $stories, null);

        $moveTo = $this->post('move_to');
        $target = null;
        if ($moveTo !== '') {
            $target = $this->find($moveTo);
            if ($target === null || (int) $target['id'] === (int) $row['id']) {
                return new Response($this->confirm($row, $stories, Lang::t('ratings.err_target')), 422);
            }
        }
        if ($target === null && $stories > 0) {
            return new Response($this->confirm($row, $stories, Lang::t('ratings.err_in_use', ['n' => $stories])), 422);
        }
        $this->db->begin();
        try {
            if ($target !== null) $this->db->query('UPDATE stories SET rating_id = ? WHERE rating_id = ?', [(int) $target['id'], (int) $row['id']]);
            $this->db->query('DELETE FROM ratings WHERE id = ?', [(int) $row['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->purge();
        return Response::redirect('/ratings');
    }

    private function label(): string
    {
        return mb_substr($this->post('label'), 0, 40);
    }

    private function warning(): string
    {
        return mb_substr($this->post('warning_text'), 0, 300);
    }

    private function adult(): int
    {
        return ($this->request->post['is_adult'] ?? '') === '1' ? 1 : 0;
    }

    private function find(string $id): ?array
    {
        return ctype_digit($id) ? $this->db->one('SELECT * FROM ratings WHERE id = ?', [(int) $id]) : null;
    }

    private function storyCount(int $id): int
    {
        return (int) $this->db->one('SELECT COUNT(*) AS c FROM stories WHERE rating_id = ?', [$id])['c'];
    }

    private function list(?string $error): string
    {
        $rows = $this->db->all(
            'SELECT r.*, (SELECT COUNT(*) FROM stories s WHERE s.rating_id = r.id AND s.deleted_at IS NULL) AS story_count
             FROM ratings r ORDER BY r.position, r.id');
        return $this->page('ratings/index', 'ratings.heading', '/ratings', ['rows' => $rows, 'error' => $error]);
    }

    private function editForm(array $row, ?string $error): string
    {
        return $this->page('ratings/edit', 'ratings.edit_heading', '/ratings/edit/' . (int) $row['id'], ['row' => $row, 'error' => $error]);
    }

    private function confirm(array $row, int $stories, ?string $error): string
    {
        $others = $this->db->all('SELECT id, label FROM ratings WHERE id != ? ORDER BY position, id', [(int) $row['id']]);
        return $this->page('ratings/delete', 'ratings.delete_heading', '/ratings/delete/' . (int) $row['id'],
            ['row' => $row, 'stories' => $stories, 'others' => $others, 'error' => $error]);
    }
}
