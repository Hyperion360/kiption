<?php // app/migrations/007_engagement.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            kind TEXT NOT NULL,
            story_id INTEGER REFERENCES stories (id) ON DELETE CASCADE,
            actor_id INTEGER REFERENCES users (id) ON DELETE SET NULL,
            story_title TEXT,
            read_at TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $db->query('CREATE INDEX idx_notifications_user ON notifications (user_id, created_at DESC)');

        $db->query('CREATE TABLE story_kudos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            user_id INTEGER REFERENCES users (id) ON DELETE SET NULL,
            ip TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $db->query('CREATE UNIQUE INDEX idx_kudos_member ON story_kudos (story_id, user_id) WHERE user_id IS NOT NULL');
        $db->query('CREATE UNIQUE INDEX idx_kudos_guest ON story_kudos (story_id, ip) WHERE user_id IS NULL');
        $db->query('CREATE INDEX idx_kudos_story ON story_kudos (story_id)');

        $db->query('CREATE TABLE follows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            follower_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            author_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            notify_mode TEXT NOT NULL DEFAULT \'site\' CHECK (notify_mode IN (\'site\', \'email\', \'digest\')),
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            UNIQUE (follower_id, author_id)
        )');
        $db->query('CREATE INDEX idx_follows_author ON follows (author_id)');

        $db->query('CREATE TABLE reading_history (
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            last_position INTEGER NOT NULL DEFAULT 1,
            marked_at TEXT,
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            PRIMARY KEY (user_id, story_id)
        )');
        $db->query('CREATE INDEX idx_reading_story ON reading_history (story_id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE reading_history');
        $db->query('DROP TABLE follows');
        $db->query('DROP TABLE story_kudos');
        $db->query('DROP TABLE notifications');
    }
};
