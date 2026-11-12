<?php // app/src/NoticePage.php
declare(strict_types=1);

namespace App;

use Kip\{App, Http\Request, Http\Response, View};

/** Renders one of the owner's long messages as a public page (/help, /rules, /terms). */
final class NoticePage
{
    public static function render(View $view, Request $request, App $app, bool $loggedIn, string $messageKey, string $titleKey, string $canonical): Response|string
    {
        $body = Notices::html($messageKey);
        if ($body === '') return new Response(Lang::t('error.not_found'), 404);
        $title = Lang::t($titleKey);
        return $view->render('notice', [
            'title' => $title,
            'head' => Seo\Head::make(
                siteName: (string) $app->config('site_name', 'Kiption'),
                ogImage: (string) $app->config('og_image', ''),
                baseUrl: rtrim((string) $app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical($canonical),
            'theme' => Theme::current($request),
            'request' => $request,
            'navFile' => (string) $app->config('nav_file', ''),
            'path' => $request->path,
            'body' => $body,
            'loggedIn' => $loggedIn,
        ]);
    }
}
