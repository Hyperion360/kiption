<?php // app/src/Marketing/TopPaths.php
namespace App\Marketing;

/** The one map of marketing slugs that serve at top-level paths instead of
 *  /page/view/{slug}. PageController 301s the old spelling; the controllers
 *  render the new one. One canonical URL per page, the router's doctrine. */
final class TopPaths
{
    public const MAP = [
        'cloud' => '/cloud',
        'dmca' => '/dmca',
        'compare-efiction' => '/compare/efiction',
        'compare-fictioneer' => '/compare/fictioneer',
        'compare-wattpad' => '/compare/wattpad',
        'compare-webnovel' => '/compare/webnovel',
        'compare-royal-road' => '/compare/royalroad',
        'compare-fanfiction-net' => '/compare/fanfictionnet',
        'compare-ao3' => '/compare/ao3',
        'compare-substack' => '/compare/substack',
    ];

    /** @return list<array{slug: string, path: string}> */
    public static function comparisons(): array
    {
        $out = [];
        foreach (self::MAP as $slug => $path) {
            if (str_starts_with($slug, 'compare-')) $out[] = ['slug' => $slug, 'path' => $path];
        }
        return $out;
    }

    public static function path(string $slug): ?string
    {
        return self::MAP[$slug] ?? null;
    }
}
