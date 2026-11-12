<?php // app/Features/Contact/ContactController.php
namespace App\Features\Contact;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\Post;

/** The public contact form: a visitor writes to the archive owners. Behind the
 *  `contact` feature flag. Spam defenses are a hidden honeypot (answered with
 *  the normal thank-you so a bot learns nothing) and a per-IP rate limit. The
 *  mail goes to every unlocked admin through the `site_contact` template. */
final class ContactController
{
    private const MAX_MESSAGE = 5000;
    private const PER_IP = 3;

    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    public function index(): Response|string
    {
        if (($off = \App\Features::guard('contact')) !== null) return $off;
        return $this->form(null, 200, [], !empty($this->request->get['sent']));
    }

    #[Post]
    public function send(): Response
    {
        if (($off = \App\Features::guard('contact')) !== null) return $off;
        // The hidden field is empty for people. A bot that fills it gets the
        // same thank-you page and nothing is sent or counted.
        if ($this->request->postStr('website') !== '') return Response::redirect('/contact?sent=1');
        $name = $this->oneLine($this->request->postStr('name'), 100);
        $email = $this->oneLine($this->request->postStr('email'), 254);
        $message = trim($this->request->postStr('message'));
        $old = ['name' => $name, 'email' => $email, 'message' => $message];
        if ($name === '' || $message === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->form('contact.err_fields', 422, $old);
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) return $this->form('contact.err_long', 422, $old);
        $ip = $this->request->ip;
        if (!(new \App\AttemptLimiter($this->db))->claim('contact', $ip, $ip, self::PER_IP, self::PER_IP)) {
            return $this->form('contact.err_throttled', 429, $old);
        }
        $admins = $this->db->all("SELECT email FROM users WHERE role = 'admin' AND is_locked = 0 ORDER BY id");
        if ($admins === []) return $this->form('contact.err_nobody', 503, $old);
        [$subject, $body] = \App\Templates::get($this->db, 'site_contact', 'New message from the contact form',
            "{name} ({email}) wrote through the contact form:\n\n{message}\n\n"
            . "Reply to this person at {email}. They are not signed in.");
        $text = strtr($body, ['{name}' => $name, '{email}' => $email, '{message}' => $message]);
        $mailer = $this->app->container->make(\App\MailGuard::class);
        $sent = 0;
        foreach ($admins as $a) {
            try {
                $mailer->send((string) $a['email'], $subject, $text);
                $sent++;
            } catch (\Throwable $e) {
                error_log("Contact form mail failed: {$e->getMessage()}");
            }
        }
        if ($sent === 0) return $this->form('contact.err_mail', 502, $old);
        return Response::redirect('/contact?sent=1');
    }

    /** One line only: line breaks in a name or address are how mail headers get forged. */
    private function oneLine(string $v, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v) ?? ''), 0, $max);
    }

    private function form(?string $error, int $status, array $old, bool $sent = false): Response
    {
        $title = \App\Lang::t('contact.heading');
        $html = $this->view->render('contact/form', ['title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical('/contact'),
            'csrf' => $this->session->csrfToken(), 'error' => $error, 'old' => $old, 'sent' => $sent]);
        return new Response($html, $status);
    }
}
