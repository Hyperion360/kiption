<?php // app/src/Posting.php
declare(strict_types=1);

namespace App;

use Kip\Database;
use Kip\Http\{Request, Response};
use Kip\View;

/**
 * The owner's "posting is paused" switch (submissions_open). When it is off,
 * the new story and new chapter forms and their handlers answer with a
 * friendly page instead; owners (admins) are never blocked. Existing
 * stories stay editable: the pause stops new work, not fixes.
 */
final class Posting
{
    /** The merged boot config's registration mode (open, verify, approval,
     *  invite, or closed), captured once like AdminNav::init so views, which
     *  have no config access, can ask about it. */
    private static string $mode = 'verify';

    public static function init(array $config): void
    {
        $mode = $config['registration_mode'] ?? 'verify';
        self::$mode = is_string($mode) && $mode !== '' ? $mode : 'verify';
    }

    /** Whether a guest is offered "Post a story": posting is open and an
     *  account can still be created. Every guest gets the same answer, built
     *  only from cached settings, so the anonymous page cache stays safe. */
    public static function guestOffered(): bool
    {
        return Settings::on('submissions_open') && self::$mode !== 'closed';
    }

    public static function paused(Database $db, int $userId): bool
    {
        if (Settings::on('submissions_open')) return false;
        $row = $db->one('SELECT is_admin FROM users WHERE id = ?', [$userId]);
        return !($row !== null && (int) $row['is_admin'] === 1);
    }

    /** The friendly stop page. 403 so a browser or script sees a refusal, not a saved post. */
    public static function stopPage(View $view, Request $request, \Kip\App $app): Response
    {
        $title = Lang::t('posting.paused_title');
        return new Response($view->render('settings/paused', [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $app->config('site_name', 'Kiption'),
                ogImage: (string) $app->config('og_image', ''),
                baseUrl: rtrim((string) $app->config('base_url', ''), '/'),
            )->withTitle($title)->withNoindex(),
            'theme' => Theme::current($request),
            'request' => $request,
            'navFile' => (string) $app->config('nav_file', ''),
            'path' => $request->path,
            'loggedIn' => true,
        ]), 403);
    }
}
