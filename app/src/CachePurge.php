<?php // app/src/CachePurge.php
namespace App;

/**
 * The purge set every admin save runs, in one place: the whole static layer
 * (maintenance marker preserved, plus the framework page cache rows), the
 * sitemap refreshed under the public_dir + base_url guard, and a configured
 * CDN purged after the response leaves, so a slow or failed edge call can
 * never fail or delay the save that triggered it.
 *
 * The whole run happens at most once per request (PHP-FPM gives each
 * request a fresh process state, which is what makes that safe), so a save
 * that reaches this from two places queues one edge purge, not two. A CLI or
 * import path that saves several times calls reset() before each logical save.
 */
final class CachePurge
{
    /** Test seam: replaces the raw HTTPS transport the Cdn client uses. */
    public static ?\Closure $cdnTransport = null;

    /** The app this request already ran for, and what that run answered. Held
     *  per app object, so a long-lived test process never leaks one test's run
     *  into the next; in production an app lives exactly one request. */
    private static ?\WeakReference $ranFor = null;
    private static bool $result = false;

    public static function reset(): void
    {
        self::$ranFor = null;
        self::$result = false;
    }

    /** Local prune, sitemap write and edge purge, once per request. True means
     *  the edge is enabled and a purge is queued, not that it is confirmed. */
    public static function run(\Kip\App $app, \Kip\Database $db): bool
    {
        if (self::$ranFor?->get() === $app) return self::$result;
        $cacheDir = StaticCache\Cache::configuredDir($app);
        $cacheDb = (array) ($app->config('cache_db', []) ?? []);
        StaticCache\Builder::prune($cacheDir, '');
        StaticCache\Builder::purgeLivePageCache((string) ($cacheDb['dsn'] ?? ''));
        $publicDir = $app->config('public_dir');
        $baseUrl = rtrim((string) $app->config('base_url', ''), '/');
        if ($publicDir !== null && $baseUrl !== '') {
            Seo\Sitemap::writeAll($db, (string) $publicDir, $baseUrl, []);
        }
        $queued = false;
        $cdn = (array) ($app->config('cdn', []) ?? []);
        if (!empty($cdn['enabled']) && (string) ($cdn['zone_id'] ?? '') !== '' && (string) ($cdn['api_token'] ?? '') !== ''
            && (string) ($cdn['purge_host'] ?? '') !== '') {
            $client = new Cdn($cdn, self::$cdnTransport);
            $prefix = (string) ($cdn['purge_host'] ?? '');
            $app->defer(fn () => $client->purge($prefix));
            $queued = true;
        }
        self::$ranFor = \WeakReference::create($app);
        // A request is over once its deferred work runs. Clearing here lets a
        // client that reuses one app for several requests purge on each of them.
        $app->defer(static fn () => self::reset());
        return self::$result = $queued;
    }

    /** True when a CDN sits in front (trusted_proxy) but edge purging is not on,
     *  so visitors may keep the old copy for a while after a save. */
    public static function liveSoon(\Kip\App $app): bool
    {
        $cdn = (array) ($app->config('cdn', []) ?? []);
        return !empty($app->config('trusted_proxy')) && empty($cdn['enabled']);
    }

    /** The Saved code for a save that changed public pages: "live_soon" when
     *  visitors will lag, else the code the screen would have used. */
    public static function savedCode(\Kip\App $app, string $code = '1'): string
    {
        return self::liveSoon($app) ? 'live_soon' : $code;
    }
}
