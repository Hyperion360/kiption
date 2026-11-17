<?php // app/migrations/071_author_images.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The personal image library: one row per image an author uploaded
        // from the chapter editor. owner_id scopes every read and every new
        // chapter reference; path is the server-generated /uploads/ name.
        // upload_key is the editor's per-image token, so a retried upload
        // after a lost response returns the stored row instead of a second file.
        $db->query('CREATE TABLE IF NOT EXISTS author_images (
            id INTEGER PRIMARY KEY,
            owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            path TEXT NOT NULL UNIQUE,
            content_sha256 TEXT NOT NULL,
            upload_key TEXT,
            created_at TEXT NOT NULL
        )');
        $db->query('CREATE INDEX IF NOT EXISTS idx_author_images_owner ON author_images(owner_id, id DESC)');
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS idx_author_images_key ON author_images(owner_id, upload_key) WHERE upload_key IS NOT NULL');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE IF EXISTS author_images'); }
};
