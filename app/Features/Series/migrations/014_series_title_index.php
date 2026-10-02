<?php // app/Features/Series/migrations/014_series_title_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The /series index listing orders by title for its LIMIT/OFFSET
        // window; without this index the plan is SCAN series + USE TEMP
        // B-TREE FOR ORDER BY, which fails the no-TEMP-B-TREE page-serving
        // standard. The index serves exactly that ORDER BY (the index-shape
        // rule: one real ORDER BY, one index).
        $db->query('CREATE INDEX idx_series_title ON series (title)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX IF EXISTS idx_series_title');
    }
};
