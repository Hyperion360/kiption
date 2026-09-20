<?php // app/src/StaticCache/Cache.php
namespace App\StaticCache;
use Kip\Http\{Request, Response};

final class Cache
{
    private const MARKER = '.maintenance-purged';

    public function __construct(private string $dir) {}

    /** Route shapes this layer may serve and fill. Mirrors the router's own
     *  segment whitelist; anything else maps to null and is never cached. */
    public function fileFor(string $path): ?string
    {
        if (!preg_match('#^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$#', $path)) return null;
        if (!preg_match('#^(?:/|/browse|/browse/recent|/browse/authors|/browse/authors/[a-z0-9]|/browse/category/[a-z0-9-]+|/story/view/[a-z0-9-]+|/story/read/[a-z0-9-]+(?:/[1-9][0-9]{0,8})?|/series/view/[a-z0-9-]+|/lists/view/[a-z0-9-]+|/user/view/[a-z0-9_-]+|/user/stories/[a-z0-9_-]+|/user/favorites/[a-z0-9_-]+|/page/view/[a-z0-9-]+|/news|/news/view/[1-9][0-9]{0,8}|/top)$#', $path)) return null;
        $prefix = $path === '/' ? '' : $path;   // '/' must not become '//'
        return $this->dir . $prefix . '/index.html';
    }

    public function isCacheable(Request $request): bool
    {
        return $request->method === 'GET'
            && $request->get === []
            && $request->cookies === []
            && $this->fileFor($request->path) !== null;
    }

    /** Serve a cached hit without booting App. Null = miss (caller falls through). */
    public function serve(Request $request): ?Response
    {
        if (!$this->isCacheable($request)) return null;
        $file = $this->fileFor($request->path);
        if ($file === null) return null;
        $body = @file_get_contents($file); // the read is the check: a purge between
        if ($body === false) return null;  // is_file and read yields a miss, not an empty HIT
        return new Response($body, 200, ['X-Static-Cache' => 'HIT']);
    }

    /** Fill after a successful anonymous render. Refuses anything that could
     *  poison the anonymous variant: non-200, Set-Cookie, or a request that
     *  was not cookieless/queryless/whitelisted to begin with. */
    public function maybeStore(Request $request, Response $response): void
    {
        if (!$this->isCacheable($request)) return;
        if ($response->status !== 200) return;
        if (array_intersect(['Set-Cookie', 'set-cookie'], array_keys($response->headers)) !== []) return;
        if (($response->headers['X-Robots-Tag'] ?? '') !== '') return; // nothing worth indexing, nothing worth caching
        $file = $this->fileFor($request->path);
        if ($file === null) return;
        @mkdir(dirname($file), 0775, true);
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (file_put_contents($tmp, $response->body) !== false) {
            @rename($tmp, $file); // atomic swap; readers never see a torn file
        }
        if (is_file($tmp)) @unlink($tmp);
    }

    public function purgeAll(): void
    {
        $marker = $this->dir . '/' . self::MARKER;
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? exec('rm -rf ' . escapeshellarg($entry)) : @unlink($entry);
        }
        @mkdir($this->dir, 0775, true);
        if (is_file($marker)) touch($marker); // keep marker semantics across purge
    }

    /** Purge a story's pages plus the collection pages its updates affect.
     *  $categorySlugs are the story's categories (caller reads them from the
     *  DB before deleting/changing the row). $seriesSlugs are the confirmed
     *  series containing it and $authorProfileSlug the author's profile key:
     *  a member story changing refreshes its series pages and the author's
     *  profile pages (the master plan's invalidation contract). */
    public function purgeStory(string $slug, array $categorySlugs, array $seriesSlugs = [], string $authorProfileSlug = ''): void
    {
        foreach (['/story/view/' . $slug, '/browse', '/browse/recent', '/'] as $p) {
            $f = $this->fileFor($p);
            if ($f !== null && is_file($f)) @unlink($f);
        }
        $readDir = $this->dir . '/story/read/' . $slug;
        if (is_dir($readDir)) exec('rm -rf ' . escapeshellarg($readDir));
        foreach ($categorySlugs as $catSlug) {
            $f = $this->fileFor('/browse/category/' . $catSlug);
            if ($f !== null && is_file($f)) @unlink($f);
        }
        foreach ($seriesSlugs as $ss) $this->purgeSeries($ss);
        if ($authorProfileSlug !== '') $this->purgeUser($authorProfileSlug);
        // The toplists hub aggregates every story's engagement, so any story's
        // kudos/favorites/reviews/visibility change can reorder it: every
        // engagement and visibility write path already calls purgeStory, and
        // /top rides them all through this unconditional unlink.
        $f = $this->fileFor('/top');
        if ($f !== null && is_file($f)) @unlink($f);
    }

    public function purgeSeries(string $slug): void
    {
        $f = $this->fileFor('/series/view/' . $slug);
        if ($f !== null && is_file($f)) @unlink($f);
    }

    /** A reading list's single cache file; private lists never fill (their
     *  guest render is the SQL 404), so this only ever hits public pages. */
    public function purgeList(string $slug): void
    {
        $f = $this->fileFor('/lists/view/' . $slug);
        if ($f !== null && is_file($f)) @unlink($f);
    }

    /** A custom page's single cache file; the slug is immutable, so the
     *  file's path can never change under an edit. */
    public function purgePage(string $slug): void
    {
        $f = $this->fileFor('/page/view/' . $slug);
        if ($f !== null && is_file($f)) @unlink($f);
    }

    /** A news item's two cached surfaces: the item's own view file plus the
     *  index (its listing row and the comment-count scalar both live there).
     *  Admin writes and comment POSTs both change both surfaces. */
    public function purgeNews(int $id): void
    {
        foreach (['/news', '/news/view/' . $id] as $p) {
            $f = $this->fileFor($p);
            if ($f !== null && is_file($f)) @unlink($f);
        }
    }

    public function purgeUser(string $profileSlug): void
    {
        foreach (['view', 'stories', 'favorites'] as $tab) {
            $f = $this->fileFor('/user/' . $tab . '/' . $profileSlug);
            if ($f !== null && is_file($f)) @unlink($f);
        }
    }

    /** The whole authors directory (index + every letter page). */
    public function purgeAuthors(): void
    {
        $dir = $this->dir . '/browse/authors';
        if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
    }

    /** Phase 1 contract: the moment maintenance blocks the first request, the
     *  static layer must go dark once; the marker prevents per-request purges.
     *  Call with true on every blocked request, false on every normal one. */
    public function maintenancePurge(bool $maintenanceOn): void
    {
        $marker = $this->dir . '/' . self::MARKER;
        if ($maintenanceOn && !is_file($marker)) {
            $this->purgeAll();
            @mkdir($this->dir, 0775, true);
            touch($marker);
        } elseif (!$maintenanceOn && is_file($marker)) {
            @unlink($marker);
        }
    }
}
