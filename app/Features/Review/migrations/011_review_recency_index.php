<?php // app/migrations/011_review_recency_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The story landing folds its reviews in as a blob subquery ordered
        // created_at DESC LIMIT 50; without an ordered index that ORDER BY built
        // a TEMP B-TREE on every render of the hottest page. Mirrors
        // idx_favorites_feed / idx_notifications_user, the same ordered
        // per-parent listing pattern.
        $db->query('CREATE INDEX idx_reviews_story_recent ON reviews (story_id, created_at DESC)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX IF EXISTS idx_reviews_story_recent');
    }
};
