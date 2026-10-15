<?php // app/Features/Invites/InvitesController.php
namespace App\Features\Invites;
use App\Adminness;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Get, Post};

/** The admin invite-code surface: list, mint, revoke-unused. The gate is the
 *  plain SQL admin check PLUS Auth::sessionValid: the epoch check revokes a
 *  session the admin has rotated away, which the raw user_id read would miss
 *  (the /adminmembers idiom carries #[Auth] for the same reason; this surface
 *  keeps the guest-is-403 doctrine instead of the login redirect, so the
 *  epoch check lives in the gate). No feature flag - invite administration is
 *  not optional once registration_mode is invite). Codes are 16 hex chars,
 *  minted with random_bytes and retried on the UNIQUE collision. Consumption
 *  stays where it already lives: UserRepository::register. */
final class InvitesController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    public function index(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $title = \App\Lang::t('invites.heading');
        return $this->view->render('invites/index', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withCanonical('/invites')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'rows' => $this->rows(),
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true, // the gate above already proved it (the settings-board idiom)
        ]);
    }

    #[Post]
    public function create(): Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        for ($i = 0; $i < 3; $i++) {
            $code = bin2hex(random_bytes(8));
            // The UNIQUE-safe idiom this codebase already uses (the wrangling
            // quadruple): INSERT OR IGNORE + rowCount, never an exception
            // catch. Kip\Database may wrap driver exceptions, so catching
            // \PDOException would miss the collision and 500.
            $inserted = $this->db->query(
                'INSERT OR IGNORE INTO invites (code, created_by) VALUES (?, ?)',
                [$code, (int) $this->session->get('user_id')]);
            if ($inserted->rowCount() === 1) return Response::redirect('/invites');
        }
        return new Response('Could not mint a unique code', 500);
    }

    #[Post]
    public function revoke(string $id): Response
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        // The delete only reaches unused codes: a consumed code is part of
        // the audit trail (who invited whom), so revoke is a no-op for it.
        $this->db->query('DELETE FROM invites WHERE id = ? AND used_by IS NULL', [(int) $id]);
        return Response::redirect('/invites');
    }

    private function rows(): array
    {
        return $this->db->all(
            'SELECT i.id, i.code, i.created_at, i.used_at, i.used_by,
                    c.penname AS created_by_name, u.penname AS used_by_name
             FROM invites i
             JOIN users c ON c.id = i.created_by
             LEFT JOIN users u ON u.id = i.used_by
             ORDER BY i.created_at DESC LIMIT 200');
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
