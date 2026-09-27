<?php // app/src/Controllers/WranglingController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Get, Post};

/** The admin tag-wrangling surface: the taxonomy control point. Every action
 *  is guard-first on the 'wrangling' flag, then behind the SQL admin gate
 *  (moderators and members draw the 403; there is no #[Auth] so a guest
 *  meets the same 403, never a login redirect for a surface it can never
 *  use). The merge is the guarded transactional quadruple (plan review
 *  finding 6): both reject guards run BEFORE the transaction opens, because
 *  the unguarded self-merge would DELETE every story_tags row of the
 *  canonical and call it a success. Merges are idempotent by construction:
 *  INSERT OR IGNORE re-points what exists, the DELETE finds nothing left,
 *  and the chain re-point moves synonyms of a canonical that itself merges
 *  later. Unmerge clears the retirement only: story_tags rows already moved
 *  to the canonical stay there (the documented data-loss boundary). */
final class WranglingController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    public function index(): Response|string
    {
        if (($r = \App\Features::guard('wrangling')) !== null) return $r;
        if (!$this->admin()) return new Response('Forbidden', 403);
        $groups = [];
        foreach ($this->rows() as $row) {
            $groups[$row['type_name']][] = $row;
        }
        ksort($groups);
        $title = \App\Lang::t('wrangling.heading');
        return $this->view->render('wrangling/index', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/wrangling')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'groups' => $groups,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
        ]);
    }

    /** Both verbs share the /wrangling/merge action name (the contact idiom):
     *  GET renders the two-step form, POST runs the merge. */
    #[Get] #[Post]
    public function merge(): Response|string
    {
        if (($r = \App\Features::guard('wrangling')) !== null) return $r;
        if (!$this->admin()) return new Response('Forbidden', 403);
        $synonymId = (int) ($this->request->post['synonym_id'] ?? $this->request->get['synonym'] ?? 0);
        $rows = $this->rows();
        $synonym = null;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $synonymId) { $synonym = $row; break; }
        }
        if ($this->request->method === 'POST') {
            if ($synonym === null) return new Response('Page not found', 404); // missing, forged, or stale synonym id
            $canonicalId = (int) ($this->request->post['canonical_id'] ?? 0);
            $canonical = null;
            foreach ($rows as $row) {
                if ((int) $row['id'] === $canonicalId) { $canonical = $row; break; }
            }
            if ($canonical === null) return new Response('Page not found', 404);
            // The reject guards, probed BEFORE the transaction opens.
            if ($synonymId === $canonicalId) {
                return new Response($this->renderMergeForm($rows, $synonym, \App\Lang::t('wrangling.err_self')), 422);
            }
            if ((int) $synonym['type_id'] !== (int) $canonical['type_id']) {
                return new Response($this->renderMergeForm($rows, $synonym, \App\Lang::t('wrangling.err_type')), 422);
            }
            if ($canonical['canonical_id'] !== null) {
                return new Response($this->renderMergeForm($rows, $synonym, \App\Lang::t('wrangling.err_retired')), 422);
            }
            $this->db->begin();
            try {
                // The quadruple: re-point, drop the synonym rows, retire, and
                // re-point the synonym's own synonyms (the chain move).
                $this->db->query(
                    'INSERT OR IGNORE INTO story_tags (story_id, tag_id) SELECT story_id, ? FROM story_tags WHERE tag_id = ?',
                    [$canonicalId, $synonymId]);
                $this->db->query('DELETE FROM story_tags WHERE tag_id = ?', [$synonymId]);
                $this->db->query('UPDATE tags SET canonical_id = ? WHERE id = ?', [$canonicalId, $synonymId]);
                $this->db->query('UPDATE tags SET canonical_id = ? WHERE canonical_id = ?', [$canonicalId, $synonymId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            return Response::redirect('/wrangling');
        }
        if ($synonymId !== 0 && $synonym === null) return new Response('Page not found', 404);
        return $this->renderMergeForm($rows, $synonym, null);
    }

    #[Post]
    public function unmerge(string $id): Response
    {
        if (($r = \App\Features::guard('wrangling')) !== null) return $r;
        if (!$this->admin()) return new Response('Forbidden', 403);
        $tag = $this->db->one('SELECT id, canonical_id FROM tags WHERE id = ?', [(int) $id]);
        if ($tag === null) return new Response('Page not found', 404);
        $this->db->query('UPDATE tags SET canonical_id = NULL WHERE id = ?', [(int) $id]);
        return Response::redirect('/wrangling');
    }

    /** ONE query: every tag with its type, its canonical (retirement state)
     *  and its live story count. Param-free; the admin index and both merge
     *  form steps share it. */
    private function rows(): array
    {
        return $this->db->all(
            'SELECT t.id, t.name, t.canonical_id, tt.id AS type_id, tt.name AS type_name,
                    (SELECT name FROM tags c WHERE c.id = t.canonical_id) AS canonical_name,
                    (SELECT COUNT(*) FROM story_tags st WHERE st.tag_id = t.id) AS story_count
             FROM tags t JOIN tag_types tt ON tt.id = t.tag_type_id
             ORDER BY tt.name, t.name');
    }

    /** The two-step merge form: without a synonym it renders the choose-form
     *  (a GET select, no scripting); with one it renders the canonical select
     *  constrained to the synonym's own type (the SQL-side constraint is the
     *  real gate, the select is the affordance). 422s re-render here with the
     *  error slot. */
    private function renderMergeForm(array $rows, ?array $synonym, ?string $error): string
    {
        $data = [
            'title' => \App\Lang::t('wrangling.merge_heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('wrangling.merge_heading'))->withCanonical('/wrangling/merge')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'allGroups' => [],
            'synonym' => $synonym,
            'candidates' => [],
            'error' => $error,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
        ];
        foreach ($rows as $row) {
            $data['allGroups'][$row['type_name']][] = $row;
            if ($synonym !== null
                && (int) $row['type_id'] === (int) $synonym['type_id']
                && $row['canonical_id'] === null
                && (int) $row['id'] !== (int) $synonym['id']) {
                $data['candidates'][] = $row;
            }
        }
        ksort($data['allGroups']);
        return $this->view->render('wrangling/merge', $data);
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
