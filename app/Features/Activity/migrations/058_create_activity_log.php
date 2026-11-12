<?php // app/Features/Activity/migrations/058_create_activity_log.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The owner's activity log (App\ActivityLog): who did what, newest
        // first. The actor's name is copied in so the line still reads right
        // after that account is renamed or deleted.
        $db->query('CREATE TABLE activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            actor_name TEXT NOT NULL DEFAULT \'\',
            action TEXT NOT NULL,
            detail TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $db->query('CREATE INDEX idx_activity_log_created ON activity_log (id DESC)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE activity_log');
    }
};
