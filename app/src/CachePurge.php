<?php // app/src/CachePurge.php
namespace App;

/**
 * The purge set every admin save runs, in one place: the whole static layer
 * (maintenance marker preserved, plus the framework page cache file), the
 * sitemap refreshed under the public_dir + base_url guard, and a configured
 * CDN purged after the response leaves, so a slow or failed edge call can
 * never fail or delay the save that triggered it.
 */
final class CachePurge
{
    /** Test seam: replaces the raw HTTPS transport the Cdn client uses. */
    public static ?\Closure $cdnTransport = null;

    public static function run(\Kip\App $app, \Kip\Database $db): void
    {
        $cacheDir = StaticCache\Cache::configuredDir($app);
        StaticCache\Builder::prune($cacheDir, (string) $app->config('app_dir', dirname(__DIR__)));
        $publicDir = $app->config('public_dir');
        $baseUrl = rtrim((string) $app->config('base_url', ''), '/');
        if ($publicDir !== null && $baseUrl !== '') {
            Seo\Sitemap::writeAll($db, (string) $publicDir, $baseUrl, []);
        }
        $cdn = (array) ($app->config('cdn', []) ?? []);
        if (!empty($cdn['enabled'])) {
            $client = new Cdn($cdn, self::$cdnTransport);
            $prefix = (string) ($cdn['purge_host'] ?? '');
            $app->defer(fn () => $client->purge($prefix));
        }
    }
}
