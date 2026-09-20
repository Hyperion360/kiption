<?php // app/migrations/013_series_item_query_indexes.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Task 9 EXPLAIN sweep: the only unguarded scans on the new Phase 7
        // shapes both hit series_items. The story-view fold's series blob
        // filters si.story_id, a NON-prefix column of the partial unique
        // idx_series_items_story (series_id, story_id), so every /story/view
        // render scanned that whole index; and the pending/confirmed COUNT
        // subqueries (mySeries, the account fold's 5series branch) cannot use
        // the partial index safely because a row with story_id NULL would sit
        // outside it, so they full-scanned the table once per series row.
        $db->query('CREATE INDEX idx_series_items_by_story ON series_items (story_id, confirmed)');
        $db->query('CREATE INDEX idx_series_items_counts ON series_items (series_id, confirmed)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX IF EXISTS idx_series_items_by_story');
        $db->query('DROP INDEX IF EXISTS idx_series_items_counts');
    }
};
