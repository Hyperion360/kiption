<?php // app/src/BrowsePanel.php
namespace App;
use Kip\Database;

/** What the header's Browse panel lists beyond fixed links: categories with
 *  story counts and the most used tags. It renders from a JSON file beside
 *  the nav artifact, never a query, so every cached page stays free of a
 *  content query. rebuild() runs wherever NavLinks::rebuild and the admin
 *  purge run; counts can lag a publish until the next of those. read() never
 *  errors: a missing or corrupt file reads empty and the panel shows only its
 *  fixed links. */
final class BrowsePanel
{
    public const TAGS = 8;

    public static function fileFor(string $navFile): string
    {
        return $navFile === '' ? '' : dirname($navFile) . '/browse-panel.json';
    }

    public static function rebuild(Database $db, string $file): void
    {
        if ($file === '') return;
        $categories = $db->all(
            'SELECT c.name, c.slug,
                    (SELECT COUNT(*) FROM story_categories sc JOIN stories s ON s.id = sc.story_id
                     WHERE sc.category_id = c.id AND s.validated = 1 AND s.deleted_at IS NULL) AS count
             FROM categories c ORDER BY c.position, c.name');
        $tags = $db->all(
            'SELECT t.id, t.name, COUNT(*) AS count
             FROM story_tags st JOIN tags t ON t.id = st.tag_id JOIN stories s ON s.id = st.story_id
             WHERE s.validated = 1 AND s.deleted_at IS NULL
             GROUP BY t.id ORDER BY count DESC, t.name LIMIT ' . self::TAGS);
        foreach ($categories as &$c) { $c = ['name' => (string) $c['name'], 'slug' => (string) $c['slug'], 'count' => (int) $c['count']]; }
        foreach ($tags as &$t) { $t = ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'count' => (int) $t['count']]; }
        @mkdir(dirname($file), 0775, true);
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmp, json_encode(['categories' => $categories, 'tags' => $tags], JSON_UNESCAPED_SLASHES));
        @rename($tmp, $file);
    }

    /** @return array{categories: list<array{name: string, slug: string, count: int}>, tags: list<array{id: int, name: string, count: int}>} */
    public static function read(string $file): array
    {
        $empty = ['categories' => [], 'tags' => []];
        if ($file === '' || !is_file($file)) return $empty;
        $d = json_decode((string) @file_get_contents($file), true);
        if (!is_array($d)) return $empty;
        $out = $empty;
        foreach ((array) ($d['categories'] ?? []) as $c) {
            if (isset($c['name'], $c['slug'], $c['count']) && is_string($c['name']) && is_string($c['slug'])) {
                $out['categories'][] = ['name' => $c['name'], 'slug' => $c['slug'], 'count' => (int) $c['count']];
            }
        }
        foreach ((array) ($d['tags'] ?? []) as $t) {
            if (isset($t['id'], $t['name'], $t['count']) && is_string($t['name'])) {
                $out['tags'][] = ['id' => (int) $t['id'], 'name' => $t['name'], 'count' => (int) $t['count']];
            }
        }
        return $out;
    }
}
