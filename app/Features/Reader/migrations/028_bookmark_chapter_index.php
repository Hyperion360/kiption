<?php // app/Features/Reader/migrations/028_bookmark_chapter_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The chapter hard-delete paths (AuthoringRepository::deleteChapter
        // and the unvalidated-chapter purge) now clean member bookmarks
        // inside their transactions: DELETE FROM bookmarks WHERE chapter_id
        // (IN (...)). The existing (user_id, story_id, created_at,
        // chapter_id) index cannot serve a chapter_id-leading predicate, so
        // this one makes the cleanup a seek instead of a scan.
        $db->query('CREATE INDEX idx_bookmarks_chapter ON bookmarks (chapter_id)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP INDEX idx_bookmarks_chapter'); }
};
