<?php // app/src/ChapterImages.php
namespace App;

use Kip\Database;

/** The image references inside chapter Markdown, read with the same grammar
 *  Markdown::render uses for a library image: ![alt](/uploads/<16 hex>.<ext>).
 *  New references must come from the signed-in author's own library; an
 *  unchanged reference (already in the stored text) always passes, so prose
 *  edits stay possible after the switch goes off or after a co-author's image
 *  was inserted. */
final class ChapterImages
{
    private function __construct() {}

    /** @return list<array{alt: string, src: string}> one entry per occurrence */
    public static function sources(string $md): array
    {
        preg_match_all('/!\[([^\]]*)\]\((' . Markdown::LOCAL_IMAGE . ')\)/', $md, $m, PREG_SET_ORDER);
        return array_map(static fn(array $x): array => ['alt' => $x[1], 'src' => $x[2]], $m);
    }

    /** An editor placeholder that never reached storage (epigraph-pending: or blob:). */
    public static function hasUnresolved(string $md): bool
    {
        return preg_match('/!\[[^\]]*\]\(\s*(?:epigraph-pending:|blob:)/i', $md) === 1;
    }

    /** Occurrences present in $submitted beyond those already in $stored, per src. */
    public static function added(string $stored, string $submitted): array
    {
        $have = [];
        foreach (self::sources($stored) as $n) $have[$n['src']] = ($have[$n['src']] ?? 0) + 1;
        $added = [];
        foreach (self::sources($submitted) as $n) {
            if (($have[$n['src']] ?? 0) > 0) { $have[$n['src']]--; continue; }
            $added[] = $n;
        }
        return $added;
    }

    /** The language key of the first refusal, or null when the text may be saved. */
    public static function refusal(Database $db, int $userId, string $stored, string $submitted, bool $imagesOn): ?string
    {
        if (self::hasUnresolved($submitted)) return 'chapter.err_image_pending';
        $added = self::added($stored, $submitted);
        if ($added === []) return null;
        if (!$imagesOn) return 'chapter.err_image_off';
        foreach ($added as $n) {
            if (trim($n['alt']) === '') return 'chapter.err_image_alt';
        }
        // One statement for the whole save, however many images it adds: how many of the distinct
        // sources sit in this author's library, against how many were asked for.
        $wanted = array_values(array_unique(array_column($added, 'src')));
        $owned = (int) ($db->one('SELECT COUNT(DISTINCT path) AS n FROM author_images WHERE owner_id = ? AND path IN (SELECT value FROM json_each(?))',
            [$userId, json_encode($wanted, JSON_THROW_ON_ERROR)])['n'] ?? 0);
        return $owned === count($wanted) ? null : 'chapter.err_image_library';
    }
}
