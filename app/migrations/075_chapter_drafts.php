<?php // app/migrations/075_chapter_drafts.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // A real draft state for chapters. A draft is stored with
        // validated = 0 (so every reader surface that gates on validated = 1
        // already hides it, exactly like a scheduled chapter) plus is_draft = 1,
        // which keeps it out of the moderation queue and releaseDue. down()
        // needs SQLite >= 3.35 for DROP COLUMN.
        $db->query('ALTER TABLE chapters ADD COLUMN is_draft INTEGER NOT NULL DEFAULT 0');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, drop chapters.is_draft manually on this host');
        }
        $db->query('ALTER TABLE chapters DROP COLUMN is_draft');
    }
};
