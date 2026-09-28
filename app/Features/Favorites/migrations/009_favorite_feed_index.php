<?php // app/migrations/009_favorite_feed_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The favorites shelf (EngagementRepository::favoritesRows) orders one
        // member's favorites by created_at DESC LIMIT 100; without an ordered
        // index SQLite materialized a TEMP B-TREE per shelf render. Mirrors
        // idx_notifications_user, the same ordered per-user listing pattern.
        $db->query('CREATE INDEX idx_favorites_feed ON favorites (user_id, created_at DESC)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX IF EXISTS idx_favorites_feed');
    }
};
