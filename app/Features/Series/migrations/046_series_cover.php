<?php // app/Features/Series/migrations/046_series_cover.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The story-cover mirror (migration 010's stories.cover_path): a
        // nullable /uploads/ path, TEXT, no index (never a WHERE term).
        $db->query('ALTER TABLE series ADD COLUMN cover_path TEXT');
    }
    public function down(Kip\Database $db): void
    {
        // The 010/045 shape: DROP COLUMN needs SQLite >= 3.35; on an older
        // host fail with the actionable instruction, not a parse error.
        if (version_compare((string) ($db->one('SELECT sqlite_version() AS v')['v'] ?? '0'), '3.35', '<')) {
            throw new \RuntimeException('DROP COLUMN needs SQLite >= 3.35, drop series.cover_path manually on this host');
        }
        $db->query('ALTER TABLE series DROP COLUMN cover_path');
    }
};
