<?php // app/Features/Browse/migrations/066_browse_filter_indexes.php
declare(strict_types=1);
return new class extends Kip\Migrations\Migration {
    // Index-only. The filter page sorts four ways (updated, created, words,
    // title) and each sort needs its own index, or SQLite sorts every match.
    // The tag indexes serve the picker list and the canonical-tag lookups.
    public function up(Kip\Database $db): void
    {
        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_stories_created  ON stories(validated, created_at DESC, id DESC)',
            'CREATE INDEX IF NOT EXISTS idx_stories_words    ON stories(validated, word_count DESC, id DESC)',
            'CREATE INDEX IF NOT EXISTS idx_stories_title    ON stories(validated, title COLLATE NOCASE, id)',
            'CREATE INDEX IF NOT EXISTS idx_stories_language ON stories(validated, language)',
            'CREATE INDEX IF NOT EXISTS idx_tags_name        ON tags(name COLLATE NOCASE)',
            'CREATE INDEX IF NOT EXISTS idx_tags_canonical   ON tags(canonical_id)',
            'CREATE INDEX IF NOT EXISTS idx_tags_type_name   ON tags(tag_type_id, name COLLATE NOCASE)',
            'CREATE INDEX IF NOT EXISTS idx_story_tags_rev   ON story_tags(tag_id, story_id)',
        ] as $sql) {
            $db->query($sql);
        }
    }

    public function down(Kip\Database $db): void
    {
        foreach (['idx_stories_created', 'idx_stories_words', 'idx_stories_title', 'idx_stories_language', 'idx_tags_name', 'idx_tags_canonical', 'idx_tags_type_name', 'idx_story_tags_rev'] as $i) {
            $db->query("DROP INDEX IF EXISTS {$i}");
        }
    }
};
