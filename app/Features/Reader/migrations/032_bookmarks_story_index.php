<?php // app/Features/Reader/migrations/032_bookmarks_story_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // 030's story_id foreign key cascades on story deletes; the primary
        // key and both 027/028 indexes lead with user_id or chapter_id, so
        // the cascade's lookup scanned every bookmark under the write lock.
        $db->query('CREATE INDEX idx_bookmarks_story ON bookmarks (story_id)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP INDEX idx_bookmarks_story'); }
};
