<?php // app/migrations/022_challenges_created_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The public /challenges listing orders every row by created_at DESC,
        // id DESC (indexRows, the newest-first index page). Without an index
        // the whole listing sorted through a TEMP B-TREE on every render:
        // EXPLAIN QUERY PLAN at 400 challenges showed SCAN + USE TEMP B-TREE
        // FOR ORDER BY. idx_news_published is the precedent for exactly this
        // page shape; a reverse walk of (created_at, id) serves both ORDER BY
        // terms (EXPLAIN: SCAN ch USING INDEX, no temp sort, 0.5 ms -> 0.5 ms
        // at 400 rows; the win is the shape, not the timing at seed volume).
        $db->query('CREATE INDEX idx_challenges_created ON challenges (created_at, id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX idx_challenges_created');
    }
};
