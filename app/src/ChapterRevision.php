<?php
namespace App;

/** A saved chapter's identity and editable fields, independent of row order. */
final class ChapterRevision
{
    public static function of(array $row): string
    {
        $fields = [(int) ($row['chapter_id'] ?? 0)];
        foreach (['title', 'content', 'notes_before', 'notes_after', 'publish_at', 'access_hash'] as $key) {
            $fields[] = ($row[$key] ?? null) === null ? null : (string) $row[$key];
        }
        // The draft flag is part of what a save changes: a stale form must not flip it back.
        $fields[] = (int) ($row['is_draft'] ?? 0);
        // Preserve legacy text bytes without replacing invalid UTF-8 sequences.
        return hash('sha256', serialize($fields));
    }
}
