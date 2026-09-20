<?php // app/migrations/015_review_window_indexes.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The roots window walks (story_id, created_at DESC, id DESC) filtered to
        // roots. Finding 12: NO replies index - EXPLAIN shows the replies blob
        // already walks idx_reviews_parent (parent_id=?) for the IN-list; the
        // drafted (parent_id, created_at DESC, id DESC) partial would serve a
        // single-parent shape no production query uses.
        $db->query('CREATE INDEX idx_reviews_roots ON reviews (story_id, created_at DESC, id DESC) WHERE parent_id IS NULL');
    }

    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX idx_reviews_roots');
    }
};
