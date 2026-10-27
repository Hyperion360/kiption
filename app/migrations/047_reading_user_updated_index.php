<?php // app/migrations/047_reading_user_updated_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The home page's Continue card (HomeController) walks a member's
        // history newest-first; without this index SQLite sorted the user's
        // whole history per render (TEMP B-TREE FOR ORDER BY). (user_id,
        // updated_at) serves WHERE user_id = ? ORDER BY updated_at DESC
        // LIMIT 1 as a single index walk. Not a page-serving WHERE term on
        // any other query; the trailing DESC is notation (SQLite walks a
        // composite index in either direction).
        $db->query('CREATE INDEX idx_reading_user_updated ON reading_history (user_id, updated_at)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX idx_reading_user_updated');
    }
};
