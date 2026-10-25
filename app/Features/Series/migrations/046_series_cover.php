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
        $db->query('ALTER TABLE series DROP COLUMN cover_path');
    }
};
