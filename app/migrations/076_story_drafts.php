<?php // app/migrations/076_story_drafts.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // A story with no published chapter yet stays hidden. It is stored the
        // way a chapter draft is (migration 075): validated = 0, so every
        // reader surface that gates on s.validated = 1 already hides it, plus
        // is_draft = 1, which keeps it out of the moderation queue until a
        // chapter goes out. Once 0 it never returns to 1. down() needs SQLite
        // >= 3.35 for DROP COLUMN.
        $db->query('ALTER TABLE stories ADD COLUMN is_draft INTEGER NOT NULL DEFAULT 0');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, drop stories.is_draft manually on this host');
        }
        $db->query('ALTER TABLE stories DROP COLUMN is_draft');
    }
};
