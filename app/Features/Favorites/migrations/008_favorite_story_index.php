<?php // app/migrations/008_favorite_story_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Story view's favorite_count subquery (StoryRepository::findStoryBySlug)
        // filters favorites by story_id alone; idx_favorites_story leads with
        // user_id, so the count resolved as a covering-index SCAN of the whole
        // table on every story page render. The story-side partial index holds
        // only story favorites, matching the house partial-index pattern.
        $db->query('CREATE INDEX idx_favorites_story_count ON favorites (story_id) WHERE story_id IS NOT NULL');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX IF EXISTS idx_favorites_story_count');
    }
};
