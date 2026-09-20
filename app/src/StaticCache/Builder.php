<?php // app/src/StaticCache/Builder.php
namespace App\StaticCache;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;

final class Builder
{
    /** Render every cacheable URL from the database into the cache dir.
     *  @return int number of pages written */
    public static function build(array $config, string $cacheDir): int
    {
        $db = new Database($config['db']['dsn']);
        $cache = new Cache($cacheDir);
        $cache->purgeAll();
        // A build must be side-effect-free: no double-filling the framework
        // page cache, no synthetic guest rows with empty IPs in the audit log.
        unset($config['cache_db']);
        $config['log_db'] = ['dsn' => 'sqlite::memory:'];
        $app = new App($config);
        $urls = ['/', '/browse', '/browse/recent'];
        foreach ($db->all('SELECT slug FROM categories') as $c) {
            $urls[] = '/browse/category/' . $c['slug'];
        }
        foreach ($db->all('SELECT slug FROM stories WHERE validated = 1 AND deleted_at IS NULL AND is_restricted = 0') as $s) {
            $urls[] = '/story/view/' . $s['slug'];
            $n = (int) $db->one('SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = ?) AND validated = 1', [$s['slug']])['c'];
            for ($i = 1; $i <= $n; $i++) {
                $urls[] = '/story/read/' . $s['slug'] . '/' . $i;
            }
        }
        $written = 0;
        foreach ($urls as $url) {
            $request = new Request('GET', $url, [], [], []);
            $response = $app->handle($request);
            $before = ($f = $cache->fileFor($url)) !== null && is_file($f);
            $cache->maybeStore($request, $response);
            if (!$before && $f !== null && is_file($f)) $written++;
        }
        return $written;
    }

    /** Wipe the static layer and the framework page cache. */
    public static function prune(string $cacheDir, string $appDir): void
    {
        (new Cache($cacheDir))->purgeAll();
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($appDir . '/cache.sqlite' . $suffix);
        }
    }
}
