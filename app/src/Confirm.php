<?php // app/src/Confirm.php
namespace App;

use Kip\{App, Http\Request, Session, View};

/** The second step of an action that cannot be taken back (remove a queued
 *  story, reject a member, delete a menu link, revoke an invite code). The
 *  first post, which has already passed the screen's own gate and the token
 *  check, gets a page that asks the question; its one button posts the same
 *  address again with confirmed=1, and only that post does the work. The
 *  confirm script asks the same question in a dialog and sends confirmed=1
 *  itself, so with scripting on there is no extra page. */
final class Confirm
{
    public static function given(Request $request): bool
    {
        return ($request->post['confirmed'] ?? '') === '1';
    }

    /** The question page. $question and $yes are language keys; $back is where
     *  "Cancel" leads. $carry is the short list of posted fields the action
     *  needs again (a file name, a language code); the view escapes them.
     *  @param array<string,string> $carry */
    public static function page(View $view, Request $request, Session $session, App $app, string $question, string $yes, string $back, bool $isAdmin, array $carry = []): string
    {
        $title = Lang::t('admin.confirm_heading');
        return $view->render('confirm', [
            'title' => $title,
            'head' => Seo\Head::make(
                siteName: (string) $app->config('site_name', 'Kiption'),
                ogImage: (string) $app->config('og_image', ''),
                baseUrl: rtrim((string) $app->config('base_url', ''), '/'),
            )->withTitle($title)->withNoindex(),
            'theme' => Theme::current($request),
            'request' => $request,
            'navFile' => (string) $app->config('nav_file', ''),
            'path' => $request->path,
            'csrf' => $session->csrfToken(),
            'question' => $question, 'yes' => $yes, 'back' => $back, 'carry' => $carry,
            'loggedIn' => true,
            'isAdmin' => $isAdmin,
        ]);
    }
}
