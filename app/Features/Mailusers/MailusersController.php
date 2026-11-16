<?php // app/Features/Mailusers/MailusersController.php
namespace App\Features\Mailusers;
use App\{AdminScreen, MassMail};
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** Owner broadcast: write a message, preview who gets it, then send. Admin
 *  only. The recipient rule and the sending loop live in App\MassMail, the
 *  same code the mail:users command runs. One broadcast per ten minutes. */
final class MailusersController extends AdminScreen
{
    public function __construct(
        View $view, Request $request, Database $db, Session $session, App $app,
        private \App\MailGuard $mailer,
    ) {
        parent::__construct($view, $request, $db, $session, $app);
    }

    #[AuthAttr]
    public function index(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $now = MassMail::summary($this->db);
        return $this->form('mailusers/index', '', '', null, $now['n'], $now);
    }

    /** Show the message as it will go, with the recipient count and the fit
     *  verdict. Sends nothing; the verdict is advisory (the POST re-checks). */
    #[AuthAttr] #[Post]
    public function preview(): Response|string
    {
        if ($r = $this->deny()) return $r;
        [$subject, $body, $error] = $this->input();
        $count = count(MassMail::recipients($this->db));
        if ($error !== null) return new Response($this->form('mailusers/index', $subject, $body, $error, $count), 422);
        $fit = MassMail::fits($this->mailer, $count);
        return $this->form('mailusers/preview', $subject, $body, null, $count, null, $fit);
    }

    #[AuthAttr] #[Post]
    public function send(): Response|string
    {
        if ($r = $this->deny()) return $r;
        [$subject, $body, $error] = $this->input();
        $recipients = MassMail::recipients($this->db);
        if ($error !== null) return new Response($this->form('mailusers/index', $subject, $body, $error, count($recipients)), 422);
        if ($recipients === []) {
            return new Response($this->form('mailusers/index', $subject, $body, \App\Lang::t('mailusers.err_nobody'), 0), 422);
        }
        // Refuse up front, before the ten-minute slot is claimed: a broadcast
        // that cannot fit sends nothing, burns nothing, and names the limit
        // that was hit (the send's own admission stays the last word).
        $fit = MassMail::fits($this->mailer, count($recipients));
        if (!$fit['fits']) {
            $key = $fit['binding'] === 'month' ? 'mailusers.err_cap_month' : 'mailusers.err_cap_day';
            return new Response($this->form('mailusers/index', $subject, $body,
                \App\Lang::t($key, ['n' => number_format(count($recipients)), 'room' => number_format($fit['room'])]), count($recipients)), 422);
        }
        if (!MassMail::claim($this->db, (int) $this->session->get('user_id'), $subject, count($recipients))) {
            return new Response($this->form('mailusers/index', $subject, $body, \App\Lang::t('mailusers.err_wait'), count($recipients)), 429);
        }
        @set_time_limit(0);
        $mailed = MassMail::send($this->mailer, $recipients, $subject, $body);
        \App\ActivityLog::by($this->db, $this->session, 'mailusers.send', $mailed . ' of ' . count($recipients) . ' sent');
        return $this->page('mailusers/sent', 'mailusers.sent_heading', '', ['mailed' => $mailed, 'total' => count($recipients)]);
    }

    /** @return array{0: string, 1: string, 2: ?string} subject (one line), body, error */
    private function input(): array
    {
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $this->request->postStr('subject')) ?? '');
        $body = trim($this->request->postStr('body'));
        if ($subject === '' || mb_strlen($subject) > 200) return [$subject, $body, \App\Lang::t('mailusers.err_subject')];
        if ($body === '' || mb_strlen($body) > 20000) return [$subject, $body, \App\Lang::t('mailusers.err_body')];
        return [$subject, $body, null];
    }

    private function form(string $view, string $subject, string $body, ?string $error, int $count, ?array $now = null, ?array $fit = null): string
    {
        // usage() pays its two counter reads only when a daily cap is set; an
        // uncapped archive gets null and the pages render exactly as before.
        $usage = $this->mailer->usage();
        return $this->page($view, 'mailusers.heading', '', ['subject' => $subject, 'body' => $body, 'error' => $error,
            'count' => $count, 'now' => $now, 'fit' => $fit,
            'usage' => $usage['dailyCap'] > 0 ? $usage : null]);
    }
}
