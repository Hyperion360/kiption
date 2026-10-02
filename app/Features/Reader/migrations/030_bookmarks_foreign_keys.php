<?php // app/Features/Reader/migrations/030_bookmarks_foreign_keys.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // 027 created bookmarks without foreign keys, so deleting an account,
        // a story, or a chapter outside AuthoringRepository's explicit
        // cleanup left orphan rows (a member's private notes outliving the
        // account). SQLite cannot add a FOREIGN KEY to an existing table, so
        // this rebuilds it with ON DELETE CASCADE on all three ids, carries
        // only rows whose targets still exist (the orphans are dropped), and
        // recreates 027's and 028's indexes; idx_bookmarks_chapter also
        // serves the chapter cascade lookup.
        $db->query("CREATE TABLE bookmarks_new (
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            chapter_id INTEGER NOT NULL REFERENCES chapters (id) ON DELETE CASCADE,
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            PRIMARY KEY (user_id, story_id, chapter_id)
        )");
        $db->query('INSERT INTO bookmarks_new (user_id, story_id, chapter_id, note, created_at)
            SELECT b.user_id, b.story_id, b.chapter_id, b.note, b.created_at FROM bookmarks b
            WHERE EXISTS (SELECT 1 FROM users u WHERE u.id = b.user_id)
              AND EXISTS (SELECT 1 FROM stories s WHERE s.id = b.story_id)
              AND EXISTS (SELECT 1 FROM chapters c WHERE c.id = b.chapter_id)');
        $db->query('DROP TABLE bookmarks');
        $db->query('ALTER TABLE bookmarks_new RENAME TO bookmarks');
        $db->query('CREATE INDEX idx_bookmarks_user_story ON bookmarks (user_id, story_id, created_at, chapter_id)');
        $db->query('CREATE INDEX idx_bookmarks_chapter ON bookmarks (chapter_id)');
    }
    public function down(Kip\Database $db): void
    {
        // Back to 027's key-less shape (028's chapter index included).
        $db->query("CREATE TABLE bookmarks_old (
            user_id INTEGER NOT NULL,
            story_id INTEGER NOT NULL,
            chapter_id INTEGER NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            PRIMARY KEY (user_id, story_id, chapter_id)
        )");
        $db->query('INSERT INTO bookmarks_old SELECT user_id, story_id, chapter_id, note, created_at FROM bookmarks');
        $db->query('DROP TABLE bookmarks');
        $db->query('ALTER TABLE bookmarks_old RENAME TO bookmarks');
        $db->query('CREATE INDEX idx_bookmarks_user_story ON bookmarks (user_id, story_id, created_at, chapter_id)');
        $db->query('CREATE INDEX idx_bookmarks_chapter ON bookmarks (chapter_id)');
    }
};
