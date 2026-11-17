<?php // app/migrations/072_chapter_save_requests.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Idempotent JSON chapter saves: the editor sends a UUID save_key with
        // each save. The row is written in the same transaction as the chapter,
        // so a retry after a lost response finds it and replays the recorded
        // redirect instead of writing a second chapter.
        $db->query('CREATE TABLE IF NOT EXISTS chapter_save_requests (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            save_key TEXT NOT NULL,
            request_hash TEXT NOT NULL,
            chapter_id INTEGER NOT NULL REFERENCES chapters(id) ON DELETE CASCADE,
            operation TEXT NOT NULL,
            redirect TEXT NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (user_id, save_key)
        )');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE IF EXISTS chapter_save_requests'); }
};
