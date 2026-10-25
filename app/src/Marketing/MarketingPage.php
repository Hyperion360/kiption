<?php // app/src/Marketing/MarketingPage.php
namespace App\Marketing;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

/** Renders a page row at its top-level path. SRP: the find/404/render/canonical
 *  half of PageController::view, reused by every marketing controller so no
 *  payload drift is possible between /page/view and the top-level spelling.
 *  $template lets a marketing controller render a page-specific template
 *  (resolved override-first like every view) instead of the plain page view;
 *  the template receives the page payload plus 'marketing' (the
 *  'cloud_marketing' config array, [] when unset) and degrades to the plain
 *  rendering when the config carries nothing. */
final class MarketingPage
{
    public static function render(View $view, Request $request, Database $db, Session $session, App $app, string $slug, string $template = 'page/view'): Response|string
    {
        if (!preg_match('#^[a-z0-9-]+$#', $slug)) return new Response('Page not found', 404);
        $row = $db->one('SELECT slug, title, body, updated_at, noindex FROM pages WHERE slug = ?', [$slug]);
        if ($row === null) return new Response('Page not found', 404);
        $path = TopPaths::path($slug) ?? '/page/view/' . $slug;
        $body = (string) $row['body'];
        $head = \App\Seo\Head::make(
            siteName: (string) $app->config('site_name', 'Kiption'),
            ogImage: (string) $app->config('og_image', ''),
            baseUrl: rtrim((string) $app->config('base_url', ''), '/'),
        )->withTitle((string) $row['title'])->withCanonical($path);
        $text = trim(html_entity_decode(strip_tags(\App\Markdown::render($body)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $head = $head->withDescription((string) preg_replace('/\s+/u', ' ', $text) ?: $text);
        $noindex = $body === '' || (int) ($row['noindex'] ?? 0) === 1;
        $data = [
            'title' => (string) $row['title'],
            'head' => $noindex ? $head->withNoindex() : $head,
            'theme' => \App\Theme::current($request),
            'request' => $request,
            'navFile' => (string) $app->config('nav_file', ''),
            'page' => ['slug' => $slug, 'title' => (string) $row['title'], 'body' => $body, 'updated_at' => (string) $row['updated_at']],
            'marketing' => $app->config('cloud_marketing', []),
            'loggedIn' => \App\Viewer::id($request, $session, $db) !== 0,
        ];
        return $noindex
            ? (new Response($view->render($template, $data), 200))->withHeader('X-Robots-Tag', 'noindex')
            : $view->render($template, $data);
    }
}
