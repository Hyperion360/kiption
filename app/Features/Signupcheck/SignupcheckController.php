<?php // app/Features/Signupcheck/SignupcheckController.php
namespace App\Features\Signupcheck;
use App\Adminness;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\Post;

/** Admin screen for the optional signup question (App\SignupCheck). Same
 *  guest-is-403 admin gate as the invites screen. */
final class SignupcheckController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    public function index(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        return $this->page(null, !empty($this->request->get['saved']));
    }

    #[Post]
    public function save(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $q = $this->request->postStr('question');
        $a = $this->request->postStr('answer');
        $err = $this->check()->save($this->request->postStr('enabled') === '1', $q, $a);
        if ($err !== null) return new Response($this->page($err, false, ['enabled' => (int) ($this->request->postStr('enabled') === '1'), 'question' => $q, 'answer' => $a]), 422);
        \App\ActivityLog::record($this->db, (int) $gate['id'], 'signupcheck.save', $this->request->postStr('enabled') === '1' ? 'on' : 'off');
        return Response::redirect('/signupcheck?saved=1');
    }

    private function check(): \App\SignupCheck
    {
        return new \App\SignupCheck($this->db, $this->session, 0);
    }

    private function page(?string $error, bool $saved, ?array $values = null): string
    {
        $title = \App\Lang::t('signupcheck.heading');
        return $this->view->render('signupcheck/index', ['title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical('/signupcheck')->withNoindex(),
            'csrf' => $this->session->csrfToken(), 'loggedIn' => true, 'isAdmin' => true,
            'error' => $error, 'saved' => $saved, 'values' => $values ?? $this->check()->settings()]);
    }
}
