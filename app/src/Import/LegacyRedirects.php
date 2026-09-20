<?php // app/src/Import/LegacyRedirects.php
namespace App\Import;
use Kip\Database;
use Kip\Http\Request;

final class LegacyRedirects
{
    // Param shapes verified against the eFiction source (finding 9): reviews.php
    // links are reviews.php?type=ST&item=N (and type=SE for series); the others
    // are single-param. browse.php also accepts id=/type=categories variants and
    // array catids - out of scope, noted for the operator docs.
    private const SHAPES = [
        'viewstory.php' => ['sid' => 'story'],
        'viewuser.php' => ['uid' => 'user'],
        'viewseries.php' => ['seriesid' => 'series'],
        'browse.php' => ['catid' => 'category'],
        'reviews.php' => ['item+type' => 'mixed'],
    ];

    /** Fill legacy_urls from import_map (id -> entity, resolved to slugs at
     *  request time so slug edits survive). Called post-commit. */
    public function writeForImport(Database $db): void
    {
        foreach (['viewstory.php' => ['sid', 'fanfiction_stories', 'story'],
                  'viewuser.php' => ['uid', 'fanfiction_authors', 'user'],
                  'viewseries.php' => ['seriesid', 'fanfiction_series', 'series'],
                  'browse.php' => ['catid', 'fanfiction_categories', 'category']] as $path => [$param, $table, $type]) {
            foreach ($db->all('SELECT legacy_id, new_id FROM import_map WHERE legacy_table = ?', [$table]) as $m) {
                $db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, ?, ?, ?)',
                    [$path, $param . '=' . $m['legacy_id'], $type, (int) $m['new_id']]);
            }
        }
        // reviews.php: canonical 'item=N&type=ST' (ksort order: item before type)
        foreach ($db->all('SELECT legacy_id, new_id FROM import_map WHERE legacy_table = ?', ['fanfiction_stories']) as $m) {
            $db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, ?, ?, ?)',
                ['reviews.php', 'item=' . $m['legacy_id'] . '&type=ST', 'story', (int) $m['new_id']]);
        }
        foreach ($db->all('SELECT legacy_id, new_id FROM import_map WHERE legacy_table = ?', ['fanfiction_series']) as $m) {
            $db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, ?, ?, ?)',
                ['reviews.php', 'item=' . $m['legacy_id'] . '&type=SE', 'series', (int) $m['new_id']]);
        }
    }

    /** Null = not a legacy URL (fall through to the app). Only GET .php paths
     *  consult. Params canonicalize via ksort so item/type order never matters. */
    public function lookup(Request $request, Database $db): ?string
    {
        if ($request->method !== 'GET') return null;
        $path = trim($request->path, '/');
        if (!isset(self::SHAPES[$path])) return null;
        if ($path === 'reviews.php') {
            if (!isset($request->get['item'], $request->get['type'])) return null;
            $get = ['item' => (string) $request->get['item'], 'type' => (string) $request->get['type']];
            ksort($get);
            $params = http_build_query($get);
        } else {
            $param = array_key_first(self::SHAPES[$path]);
            if (!isset($request->get[$param])) return null;
            $params = $param . '=' . $request->get[$param];
        }
        $row = $db->one('SELECT target_type, target_id FROM legacy_urls WHERE legacy_path = ? AND params = ?',
            [$path, $params]);
        if ($row === null) return null;
        $slug = match ($row['target_type']) {
            'story' => $db->one('SELECT slug FROM stories WHERE id = ?', [$row['target_id']])['slug'] ?? null,
            'series' => $db->one('SELECT slug FROM series WHERE id = ?', [$row['target_id']])['slug'] ?? null,
            'category' => $db->one('SELECT slug FROM categories WHERE id = ?', [$row['target_id']])['slug'] ?? null,
            'user' => $db->one('SELECT profile_slug FROM users WHERE id = ?', [$row['target_id']])['profile_slug'] ?? null,
            default => null,
        };
        if ($slug === null) return null; // entity gone: fall through to the honest 404
        return match ($row['target_type']) {
            'story' => '/story/view/' . $slug,
            'series' => '/series/view/' . $slug,
            'category' => '/browse/category/' . $slug,
            'user' => '/user/view/' . $slug,
            default => null,
        };
    }
}
