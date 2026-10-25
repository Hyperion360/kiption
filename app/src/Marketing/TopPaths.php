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
    ];

    public static function path(string $slug): ?string
    {
        return self::MAP[$slug] ?? null;
    }
}
