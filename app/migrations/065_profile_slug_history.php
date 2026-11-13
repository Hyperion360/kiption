<?php // app/migrations/065_profile_slug_history.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // When a member changes their pen name the profile address follows.
        // The address they leave is kept here so old links and bookmarks
        // redirect to the member's current profile instead of a 404. A slug
        // someone holds right now always wins over a row here.
        $db->query('CREATE TABLE IF NOT EXISTS profile_slug_history (
            old_slug TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            changed_at INTEGER NOT NULL
        )');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS profile_slug_history');
    }
};
