<?php // app/src/ChapterImageUse.php
namespace App;

use Kip\Database;

/** Which chapters still show one uploaded image. UploadRefs ignores images
 *  embedded in chapter text, so a delete that only asked UploadRefs could
 *  break a live chapter. This is the missing scan: every chapter counts,
 *  published, scheduled or draft, in a live or a removed story, and the
 *  chapter's notes count too because they render Markdown images. One
 *  statement, run on a delete action and never on a rendered page. */
final class ChapterImageUse
{
    private function __construct() {}

    /** @return list<array{story: string, chapter: int}> up to five, by story title */
    public static function chaptersUsing(Database $db, string $path): array
    {
        $rows = $db->all('SELECT s.title AS story, c.id AS chapter FROM chapters c JOIN stories s ON s.id = c.story_id
            WHERE instr(c.content, ?) > 0 OR instr(c.notes_before, ?) > 0 OR instr(c.notes_after, ?) > 0
            ORDER BY s.title, c.id LIMIT 5', [$path, $path, $path]);
        return array_map(static fn(array $r): array => ['story' => (string) $r['story'], 'chapter' => (int) $r['chapter']], $rows);
    }
}
