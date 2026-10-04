<?php // app/migrations/034_recommendations.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE recommendations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            note TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            UNIQUE (user_id, story_id)
        )');
        $db->query('CREATE INDEX idx_rec_story ON recommendations (story_id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE recommendations');
    }
};
