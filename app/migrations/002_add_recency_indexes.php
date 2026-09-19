<?php // app/migrations/002_add_recency_indexes.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE INDEX idx_stories_recency ON stories (validated, updated_at DESC, id DESC)');
        $db->query('CREATE INDEX idx_story_categories_cat ON story_categories (category_id, story_id)');
        $db->query('CREATE INDEX idx_chapters_listing ON chapters (story_id, validated, position)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX IF EXISTS idx_stories_recency');
        $db->query('DROP INDEX IF EXISTS idx_story_categories_cat');
        $db->query('DROP INDEX IF EXISTS idx_chapters_listing');
    }
};
