<?php // app/migrations/064_admin_context_indexes.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The members page shows each member's last sign-in, read from the
        // activity log by actor and action; without this it scanned the log
        // once per row. The queue and members pages count each author's
        // reports through reports.story_id, which only a partial index
        // (open reports) covered.
        $db->query('CREATE INDEX idx_activity_log_actor ON activity_log (actor_id, action, created_at)');
        $db->query('CREATE INDEX idx_reports_story ON reports (story_id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX idx_activity_log_actor');
        $db->query('DROP INDEX idx_reports_story');
    }
};
