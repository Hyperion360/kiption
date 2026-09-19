<?php // app/migrations/005_queue_member_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Queue member branch (approved_at IS NULL AND email_verified_at IS NOT NULL):
        // a full users scan per moderator visit otherwise; the partial index holds
        // only the pending members, so SQLite resolves the lookup as a SEARCH.
        $db->query('CREATE INDEX idx_users_pending_approval ON users (approved_at) WHERE approved_at IS NULL');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX IF EXISTS idx_users_pending_approval');
    }
};
