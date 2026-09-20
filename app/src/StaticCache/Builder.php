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
        $urls = ['/', '/browse', '/browse/recent', '/top'];
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
        foreach ($db->all('SELECT slug FROM series') as $ser) {
            $urls[] = '/series/view/' . $ser['slug'];
        }
        // Custom pages: every non-empty body is a public cacheable page; an
        // empty body renders the noindex shape and never fills the layer.
        foreach ($db->all("SELECT slug FROM pages WHERE body <> ''") as $p) {
            $urls[] = '/page/view/' . $p['slug'];
        }
        // Profiles: every approvable member's view page; the stories and
        // favorites tabs only when they would list something (the tab gates:
        // validated, not deleted, not restricted; favorites additionally need
        // a visible shelf row).
        foreach ($db->all("SELECT profile_slug FROM users WHERE penname IS NOT NULL AND is_locked = 0 AND approved_at IS NOT NULL AND email_verified_at IS NOT NULL") as $u) {
            $urls[] = '/user/view/' . $u['profile_slug'];
            $has = $db->one('SELECT (SELECT COUNT(*) FROM stories st WHERE st.deleted_at IS NULL AND st.validated = 1 AND (st.author_id = (SELECT id FROM users WHERE profile_slug = ?) OR EXISTS (SELECT 1 FROM coauthors ca JOIN users u2 ON u2.id = ca.user_id WHERE ca.story_id = st.id AND u2.profile_slug = ?))) c', [$u['profile_slug'], $u['profile_slug']])['c'];
            if ((int) $has > 0) $urls[] = '/user/stories/' . $u['profile_slug'];
            $fav = $db->one('SELECT (SELECT COUNT(*) FROM favorites f JOIN stories st ON st.id = f.story_id WHERE f.user_id = (SELECT id FROM users WHERE profile_slug = ?) AND st.deleted_at IS NULL AND st.validated = 1 AND st.is_restricted = 0) c', [$u['profile_slug']])['c'];
            if ((int) $fav > 0) $urls[] = '/user/favorites/' . $u['profile_slug'];
        }
        // Directory: the index plus one letter page per distinct first character
        // among directory members (same membership gates as the page itself);
        // non-[a-z] first chars fold into the '0' bucket and dedupe there.
        $urls[] = '/browse/authors';
        $letters = [];
        foreach ($db->all('SELECT DISTINCT lower(substr(profile_slug, 1, 1)) c FROM users WHERE profile_slug IS NOT NULL AND penname IS NOT NULL AND is_locked = 0 AND approved_at IS NOT NULL AND email_verified_at IS NOT NULL') as $r) {
            $c = (string) $r['c'];
            $letters[preg_match('/^[a-z]$/', $c) ? $c : '0'] = true;
        }
        foreach (array_keys($letters) as $l) {
            $urls[] = '/browse/authors/' . $l;
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
