<?php // app/src/UploadRefs.php
namespace App;

use Kip\Database;

/** Which uploaded files are still referenced. One statement, shared by the
 *  admin image library (the listing and the delete guard) and the storage
 *  quota's retire path, so the two can never disagree about what "in use"
 *  means: avatars, story covers, series covers and the files in an author's personal
 *  image library. An image embedded by
 *  URL inside chapter or page text is invisible here, exactly as today: only
 *  an admin library delete removes such a file, by hand. */
final class UploadRefs
{
    private function __construct() {}

    /** @return array<string, true> basenames of every referenced file */
    public static function names(Database $db): array
    {
        $set = [];
        // UNION ALL, not UNION: the basename map below already dedups, so the
        // dedup sorts (three TEMP B-TREEs under EXPLAIN) buy nothing.
        $rows = $db->all('SELECT avatar_path FROM users WHERE avatar_path IS NOT NULL UNION ALL SELECT cover_path FROM stories WHERE cover_path IS NOT NULL UNION ALL SELECT cover_path FROM series WHERE cover_path IS NOT NULL UNION ALL SELECT path FROM author_images');
        foreach ($rows as $r) {
            $p = (string) (array_values($r)[0] ?? '');
            if ($p !== '') $set[basename($p)] = true;
        }
        return $set;
    }
}
