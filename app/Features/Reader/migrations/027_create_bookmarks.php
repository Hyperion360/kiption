<?php // app/Features/Reader/migrations/027_create_bookmarks.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query("CREATE TABLE bookmarks (
            user_id INTEGER NOT NULL,
            story_id INTEGER NOT NULL,
            chapter_id INTEGER NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            PRIMARY KEY (user_id, story_id, chapter_id)
        )");
        // chapter_id rides the index as the ORDER BY's tie-breaker so the
        // envelope fold's derived table (ORDER BY created_at, chapter_id)
        // resolves entirely from the seek: no TEMP B-TREE (/db-optimize).
        $db->query('CREATE INDEX idx_bookmarks_user_story ON bookmarks (user_id, story_id, created_at, chapter_id)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE bookmarks'); }
};
