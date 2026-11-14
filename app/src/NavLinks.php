<?php // app/src/NavLinks.php
namespace App;
use Kip\Database;

/** The nav renders from a JSON artifact, not a query: every cached page would
 *  otherwise pay a content query for five links. rebuild() runs on every nav
 *  write; all() never errors (missing/corrupt file reads empty). */
final class NavLinks
{
    public static function rebuild(Database $db, string $file): void
    {
        $rows = $db->all('SELECT label, url FROM nav_links WHERE is_hidden = 0 ORDER BY position, id');
        @mkdir(dirname($file), 0775, true);
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmp, json_encode($rows, JSON_UNESCAPED_SLASHES));
        @rename($tmp, $file); // atomic swap (the Cache::maybeStore idiom); readers never see a torn file
    }

    /** @return array<int, array{label: string, url: string}> */
    public static function all(string $file): array
    {
        // Finding 1, probed: @file_get_contents('') THROWS ValueError (not
        // suppressible); is_file('') is a safe false. The empty string is the
        // DEFAULT everywhere a controller forgot the navFile datum.
        if ($file === '' || !is_file($file)) return [];
        $raw = @file_get_contents($file);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];
        $out = [];
        foreach ($decoded as $r) {
            if (isset($r['label'], $r['url']) && is_string($r['label']) && is_string($r['url'])) {
                $out[] = ['label' => $r['label'], 'url' => $r['url']];
            }
        }
        return $out;
    }

    /** The built-in header and Menu links the operator may switch off from the
     *  menu-links page: Recent, Authors and Series. Browse and Search always
     *  show, so nobody can lock readers out of finding stories. A built-in
     *  shows when the artifact lists its URL; before the artifact exists (a
     *  fresh checkout) every built-in shows.
     *  @return array<string, bool> url => shown */
    public static function builtIns(string $file): array
    {
        $urls = ['/browse/recent', '/browse/authors', '/series'];
        if ($file === '' || !is_file($file)) return array_fill_keys($urls, true);
        $listed = array_column(self::all($file), 'url');
        $out = [];
        foreach ($urls as $u) $out[$u] = in_array($u, $listed, true);
        return $out;
    }
}
