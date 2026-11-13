<?php // app/Features/Signupcheck/SignupcheckController.php
namespace App\Features\Signupcheck;
use App\AdminScreen;
use Kip\Http\Response;
use Kip\Routing\{Auth as AuthAttr, Post};

/** Admin screen for the optional signup question (App\SignupCheck). Same
 *  guest-is-403 admin gate as the invites screen. */
final class SignupcheckController extends AdminScreen
{
    #[AuthAttr]
    public function index(): Response|string
    {
        if ($r = $this->deny()) return $r;
        return $this->form(null, !empty($this->request->get['saved']));
    }

    #[AuthAttr] #[Post]
    public function save(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $q = $this->request->postStr('question');
        $a = $this->request->postStr('answer');
        $err = $this->check()->save($this->request->postStr('enabled') === '1', $q, $a);
        if ($err !== null) return new Response($this->form($err, false, ['enabled' => (int) ($this->request->postStr('enabled') === '1'), 'question' => $q, 'answer' => $a]), 422);
        \App\ActivityLog::record($this->db, (int) $this->session->get('user_id'), 'signupcheck.save', $this->request->postStr('enabled') === '1' ? 'on' : 'off');
        return \App\Saved::to('/signupcheck');
    }

    private function check(): \App\SignupCheck
    {
        return new \App\SignupCheck($this->db, $this->session, 0);
    }

    private function form(?string $error, bool $saved, ?array $values = null): string
    {
        return $this->page('signupcheck/index', 'signupcheck.heading', '/signupcheck',
            ['error' => $error, 'saved' => $saved, 'values' => $values ?? $this->check()->settings()]);
    }
}
