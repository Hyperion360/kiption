<?php // app/src/Import/LegacyRedirects.php
namespace App\Import;
use Kip\Database;
use Kip\Http\Request;

final class LegacyRedirects
{
    // Param shapes verified against the eFiction source (finding 9): reviews.php
    // links are reviews.php?type=ST&item=N (and type=SE for series); the others
    // are single-param. browse.php's id=/type=categories variant and
    // viewpage.php?page={message_name} are handled explicitly in lookup().
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
        // browse.php's second category shape: every mapped category also gets
        // the canonical id=N&type=categories row (idempotent on re-import)
        foreach ($db->all('SELECT legacy_id, new_id FROM import_map WHERE legacy_table = ?', ['fanfiction_categories']) as $m) {
            $db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, ?, ?, ?)',
                ['browse.php', 'id=' . $m['legacy_id'] . '&type=categories', 'category', (int) $m['new_id']]);
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
     *  consult. Params canonicalize via ksort so item/type order never matters;
     *  browse.php's id=/type=categories variant canonicalizes the same way. */
    public function lookup(Request $request, Database $db): ?string
    {
        if ($request->method !== 'GET') return null;
        $path = trim($request->path, '/');
        // viewpage.php?page={message_name}: resolved through the import's
        // legacy_page_urls table (pages are slug-keyed, not id-keyed). Only a
        // scalar name in eFiction's own message_name shape consults; the query
        // value itself is never the redirect destination.
        if ($path === 'viewpage.php') {
            $name = $request->get['page'] ?? null;
            if (!is_string($name) || preg_match('/^[a-z0-9_]{3,30}$/D', $name) !== 1) return null;
            $page = $db->one('SELECT p.slug FROM legacy_page_urls l JOIN pages p ON p.slug = l.page_slug WHERE l.legacy_name = ?', [$name]);
            return $page === null ? null : '/page/view/' . $page['slug'];
        }
        if (!isset(self::SHAPES[$path])) return null;
        if ($path === 'reviews.php') {
            $item = $request->get['item'] ?? null;
            $type = $request->get['type'] ?? null;
            if (!is_string($item) || !is_string($type)) return null; // array params (eFiction's browse accepted them) hold no honest match
            $get = ['item' => $item, 'type' => $type];
            ksort($get);
            $params = http_build_query($get);
        } elseif ($path === 'browse.php' && isset($request->get['id'], $request->get['type'])) {
            // the category-listing variant: id=N&type=categories, any order.
            // Anything else about the pair (arrays, another type) is no match.
            $id = $request->get['id'];
            $type = $request->get['type'];
            if (!is_string($id) || !is_string($type) || $type !== 'categories') return null;
            $params = 'id=' . $id . '&type=categories';
        } else {
            $param = array_key_first(self::SHAPES[$path]);
            $value = $request->get[$param] ?? null;
            if (!is_string($value)) return null;
            $params = $param . '=' . $value;
        }
        $row = $db->one('SELECT target_type, target_id FROM legacy_urls WHERE legacy_path = ? AND params = ?',
            [$path, $params]);
        if ($row === null) return null; // entity gone: fall through to the honest 404
        return $this->destination($db, (string) $row['target_type'], (int) $row['target_id']);
    }

    /** Fictioneer side of the map, post-commit: /story/{slug}, /author/{login},
     *  and the ?p=&post_type= query fallbacks, all derived from import_map.
     *  legacy_id is site-scoped ('{host}/{id}' or '{host}/{login}'); the id
     *  or login is the part after the last slash. Chapter path rows
     *  (/chapter/{slug}, /story/{story}/{chapter}) are written inside the
     *  import instead, because chapter slugs exist only in the WXR. INSERT
     *  OR IGNORE keeps re-runs idempotent. */
    public function writeForFictioneer(Database $db): void
    {
        $tail = static fn(string $legacyId): string => (string) substr(strrchr('/' . $legacyId, '/'), 1);
        foreach ($db->all("SELECT m.legacy_id, m.new_id, s.slug FROM import_map m
                           JOIN stories s ON s.id = m.new_id WHERE m.legacy_table = 'fcn_story'") as $m) {
            $db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, ?, ?, ?)',
                ['/story/' . $m['slug'], '', 'story', (int) $m['new_id']]);
            $db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, ?, ?, ?)',
                ['', 'p=' . $tail((string) $m['legacy_id']) . '&post_type=fcn_story', 'story', (int) $m['new_id']]);
        }
        foreach ($db->all("SELECT m.legacy_id, c.story_id FROM import_map m
                           JOIN chapters c ON c.id = m.new_id WHERE m.legacy_table = 'fcn_chapter'") as $m) {
            $db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, ?, ?, ?)',
                ['', 'p=' . $tail((string) $m['legacy_id']) . '&post_type=fcn_chapter', 'story', (int) $m['story_id']]);
        }
        foreach ($db->all("SELECT m.legacy_id, m.new_id FROM import_map m
                           JOIN users u ON u.id = m.new_id WHERE m.legacy_table = 'fcn_user'") as $m) {
            $db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, ?, ?, ?)',
                ['/author/' . $tail((string) $m['legacy_id']), '', 'user', (int) $m['new_id']]);
        }
    }

    /** Fictioneer path shapes, consulted only after a routed 404: an exact
     *  path row (params ''), or the sorted query-string fallbacks, or a
     *  legacy page name (recommendation pages landed in pages). Clean
     *  pages and static-cache hits never reach here, so the one indexed
     *  lookup is paid by 404s alone. */
    public function lookupPath(Request $request, Database $db): ?string
    {
        if ($request->method !== 'GET') return null;
        $path = '/' . trim($request->path, '/');
        $row = null;
        if ($request->get === []) {
            $row = $db->one('SELECT target_type, target_id FROM legacy_urls WHERE legacy_path = ? AND params = ?', [$path, '']);
            if ($row === null) {
                $page = $db->one('SELECT p.slug FROM legacy_page_urls l JOIN pages p ON p.slug = l.page_slug WHERE l.legacy_name = ?', [trim($path, '/')]);
                if ($page !== null) return '/page/view/' . $page['slug'];
            }
        } else {
            $get = [];
            foreach ($request->get as $k => $v) { if (is_string($v)) $get[$k] = $v; }
            if (count($get) === count($request->get)) {
                ksort($get);
                $row = $db->one('SELECT target_type, target_id FROM legacy_urls WHERE legacy_path = ? AND params = ?', ['', http_build_query($get)]);
                // WordPress's canonical shortlink arrives as a bare ?p={digits}
                // (no post_type): try the two Fictioneer post types before
                // giving up, in the story-then-chapter order the map writes.
                if ($row === null && count($get) === 1 && ctype_digit((string) ($get['p'] ?? ''))) {
                    foreach (['p=' . $get['p'] . '&post_type=fcn_story', 'p=' . $get['p'] . '&post_type=fcn_chapter'] as $shaped) {
                        $row = $db->one('SELECT target_type, target_id FROM legacy_urls WHERE legacy_path = ? AND params = ?', ['', $shaped]);
                        if ($row !== null) break;
                    }
                }
            }
        }
        if ($row === null) return null;
        return $this->destination($db, (string) $row['target_type'], (int) $row['target_id']);
    }

    /** target -> current URL, resolved at request time so slug edits
     *  survive; entity gone = null = the honest 404. */
    private function destination(Database $db, string $type, int $id): ?string
    {
        $slug = match ($type) {
            'story' => $db->one('SELECT slug FROM stories WHERE id = ?', [$id])['slug'] ?? null,
            'series' => $db->one('SELECT slug FROM series WHERE id = ?', [$id])['slug'] ?? null,
            'category' => $db->one('SELECT slug FROM categories WHERE id = ?', [$id])['slug'] ?? null,
            'user' => $db->one('SELECT profile_slug FROM users WHERE id = ?', [$id])['profile_slug'] ?? null,
            default => null,
        };
        if ($slug === null) return null;
        return match ($type) {
            'story' => '/story/view/' . $slug,
            'series' => '/series/view/' . $slug,
            'category' => '/browse/category/' . $slug,
            'user' => '/user/view/' . $slug,
            default => null,
        };
    }
}
