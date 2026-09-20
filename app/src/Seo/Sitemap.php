<?php // app/src/Seo/Sitemap.php
namespace App\Seo;
use Kip\Database;

/** Writes the segmented sitemap index and regenerates robots.txt as real
 *  files in the web-served public dir: dot-paths cannot route through the
 *  convention router (the /page lesson applied to /sitemap.xml), so these are
 *  generated artifacts rebuilt by pages:build and `kip robots`. */
final class Sitemap
{
    private const X = ENT_XML1 | ENT_COMPAT;

    /** Same discipline as Feed::e: XML 1.0 admits only tab, LF and CR among
     *  C0 controls, and htmlspecialchars strips none of them. Strip first,
     *  then escape. */
    private static function e(?string $s): string
    {
        $s = preg_replace('#[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}]#u', '', (string) $s) ?? '';
        return htmlspecialchars($s, self::X, 'UTF-8');
    }

    /** @param list<array{0:string,1:?string}> $urls [loc, lastmod] rows */
    private static function segmentXml(array $urls): string
    {
        $body = '';
        foreach ($urls as [$loc, $lastmod]) {
            $body .= "  <url>\n    <loc>" . self::e($loc) . "</loc>\n"
                . ($lastmod === null ? '' : "    <lastmod>" . self::e($lastmod) . "</lastmod>\n")
                . "  </url>\n";
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $body . "</urlset>\n";
    }

    /** @param list<string> $files segment filenames as written */
    private static function indexXml(array $files, string $baseUrl): string
    {
        $body = '';
        foreach ($files as $f) {
            $body .= "  <sitemap>\n    <loc>" . self::e($baseUrl . '/' . $f) . "</loc>\n  </sitemap>\n";
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $body . "</sitemapindex>\n";
    }

    /** Write sitemap.xml (the index) plus one file per non-empty segment into
     *  $publicDir. The segment gates quote the Builder's WHERE clauses
     *  verbatim (accepted duplication: one source of truth per surface) plus
     *  the syndication exclusion: an external-canonical story deindexes
     *  locally and never lists. Empty segments are skipped and unlisted.
     *  @param array $config carried for the build callers; no keys consumed yet
     *  @return int files written (segments + the index) */
    public static function writeAll(Database $db, string $publicDir, string $baseUrl, array $config, int $maxPerSegment = 45000): int
    {
        if (!is_dir($publicDir)) mkdir($publicDir, 0775, true);
        // Regeneration owns the sitemap*.xml names: a smaller rebuild must not
        // leave stale segments from a larger previous one lying around.
        foreach (glob($publicDir . '/sitemap*.xml') ?: [] as $stale) @unlink($stale);

        $segments = [];
        // Stories: the view URL plus every validated read URL per story, all
        // carrying the story's updated_at; split into segments of
        // $maxPerSegment URLs. The WHERE clause is the Builder's story gate
        // (validated, not deleted, not restricted) plus canonical_url IS NULL.
        // One query: the LEFT JOIN yields one row per validated chapter and a
        // single NULL-position row for chapterless stories, so the view URL is
        // emitted on each story's first row (the slug change) and one read URL
        // per chapter row.
        $storyUrls = [];
        $lastSlug = null;
        foreach ($db->all('SELECT s.slug, s.updated_at, c.position FROM stories s LEFT JOIN chapters c ON c.story_id = s.id AND c.validated = 1 WHERE s.validated = 1 AND s.deleted_at IS NULL AND s.is_restricted = 0 AND s.canonical_url IS NULL ORDER BY s.id, c.position') as $r) {
            $slug = rawurlencode((string) $r['slug']);
            if ($r['slug'] !== $lastSlug) {
                $storyUrls[] = [$baseUrl . '/story/view/' . $slug, $r['updated_at']];
                $lastSlug = $r['slug'];
            }
            if ($r['position'] !== null) {
                $storyUrls[] = [$baseUrl . '/story/read/' . $slug . '/' . (int) $r['position'], $r['updated_at']];
            }
        }
        foreach (array_chunk($storyUrls, max(1, $maxPerSegment)) as $i => $chunk) {
            $segments[] = ['sitemap-stories-' . ($i + 1) . '.xml', $chunk];
        }
        // Authors: the directory gate (approvable members with a penname).
        $segments[] = ['sitemap-authors.xml', array_map(static fn(array $u): array
            => [$baseUrl . '/user/view/' . rawurlencode((string) $u['profile_slug']), $u['created_at']],
            $db->all('SELECT profile_slug, created_at FROM users WHERE penname IS NOT NULL AND is_locked = 0 AND approved_at IS NOT NULL AND email_verified_at IS NOT NULL'))];
        // Categories carry no timestamp columns: loc only.
        $segments[] = ['sitemap-categories.xml', array_map(static fn(array $c): array
            => [$baseUrl . '/browse/category/' . rawurlencode((string) $c['slug']), null],
            $db->all('SELECT slug FROM categories'))];
        $segments[] = ['sitemap-series.xml', array_map(static fn(array $s): array
            => [$baseUrl . '/series/view/' . rawurlencode((string) $s['slug']), $s['created_at']],
            $db->all('SELECT slug, created_at FROM series'))];
        $segments[] = ['sitemap-pages.xml', array_map(static fn(array $p): array
            => [$baseUrl . '/page/view/' . rawurlencode((string) $p['slug']), $p['updated_at']],
            $db->all("SELECT slug, updated_at FROM pages WHERE body <> ''"))];
        $segments[] = ['sitemap-news.xml', array_map(static fn(array $n): array
            => [$baseUrl . '/news/view/' . (int) $n['id'], $n['published_at']],
            $db->all('SELECT id, published_at FROM news'))];

        $files = [];
        foreach ($segments as [$file, $urls]) {
            if ($urls === []) continue;
            file_put_contents($publicDir . '/' . $file, self::segmentXml($urls));
            $files[] = $file;
        }
        file_put_contents($publicDir . '/sitemap.xml', self::indexXml($files, $baseUrl));
        return count($files) + 1;
    }

    /** Regenerate robots.txt. Permissive is the committed public/robots.txt
     *  verbatim (byte-identical, the 52 bytes incl. the blank line before
     *  Sitemap); restrictive prepends one RFC 9309 group for the named AI
     *  crawlers: consecutive User-agent lines share the single Disallow. */
    public static function writeRobots(string $file, bool $permissive): void
    {
        $body = "User-agent: *\nDisallow: /*?*\n\nSitemap: /sitemap.xml\n";
        $ai = "User-agent: GPTBot\nUser-agent: CCBot\nUser-agent: ClaudeBot\nUser-agent: anthropic-ai\nUser-agent: Google-Extended\nDisallow: /\n\n";
        file_put_contents($file, $permissive ? $body : $ai . $body);
    }
}
