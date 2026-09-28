<?php // app/migrations/018_reading_lists.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE reading_lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            summary TEXT NOT NULL DEFAULT \'\',
            is_public INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $db->query('CREATE INDEX idx_reading_lists_owner ON reading_lists (owner_id)');
        $db->query('CREATE TABLE reading_list_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            list_id INTEGER NOT NULL REFERENCES reading_lists (id) ON DELETE CASCADE,
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            position INTEGER NOT NULL DEFAULT 1,
            note TEXT NOT NULL DEFAULT \'\',
            added_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            UNIQUE (list_id, story_id)
        )');
        // The story-side lookup (Task 2's item ops and the purge rider) walks
        // items by story_id; the UNIQUE (list_id, story_id) constraint already
        // covers the blob's list_id searches (the migration 013 lesson).
        $db->query('CREATE INDEX idx_reading_list_items_story ON reading_list_items (story_id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE reading_list_items');
        $db->query('DROP TABLE reading_lists');
    }
};
