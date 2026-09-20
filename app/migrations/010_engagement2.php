<?php // app/migrations/010_engagement2.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Threaded replies under reviews (flat one level under the root).
        $db->query('ALTER TABLE reviews ADD COLUMN parent_id INTEGER REFERENCES reviews (id) ON DELETE CASCADE');
        $db->query('ALTER TABLE reviews ADD COLUMN ip TEXT NOT NULL DEFAULT \'\'');
        $db->query('CREATE INDEX idx_reviews_parent ON reviews (parent_id)');

        $db->query('ALTER TABLE stories ADD COLUMN is_restricted INTEGER NOT NULL DEFAULT 0');
        $db->query('ALTER TABLE stories ADD COLUMN language TEXT NOT NULL DEFAULT \'\'');
        $db->query('ALTER TABLE stories ADD COLUMN cover_path TEXT');

        $db->query('ALTER TABLE users ADD COLUMN support_url TEXT');
        $db->query('ALTER TABLE user_prefs ADD COLUMN digest_sent_at TEXT');
        $db->query('ALTER TABLE user_prefs ADD COLUMN notify_favorite_digest INTEGER NOT NULL DEFAULT 0');

        $db->query('CREATE TABLE reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reporter_id INTEGER REFERENCES users (id) ON DELETE SET NULL,
            story_id INTEGER REFERENCES stories (id) ON DELETE CASCADE,
            review_id INTEGER REFERENCES reviews (id) ON DELETE CASCADE,
            reason TEXT NOT NULL,
            resolved_at TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            CHECK (story_id IS NOT NULL OR review_id IS NOT NULL)
        )');
        $db->query('CREATE UNIQUE INDEX idx_reports_open_story ON reports (story_id, reporter_id) WHERE resolved_at IS NULL AND story_id IS NOT NULL');
        $db->query('CREATE UNIQUE INDEX idx_reports_open_review ON reports (review_id, reporter_id) WHERE resolved_at IS NULL AND review_id IS NOT NULL');
        $db->query('CREATE INDEX idx_reports_open ON reports (resolved_at)');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35; remove the columns manually on this host');
        }
        $db->query('DROP TABLE reports');
        $db->query('ALTER TABLE user_prefs DROP COLUMN notify_favorite_digest');
        $db->query('ALTER TABLE user_prefs DROP COLUMN digest_sent_at');
        $db->query('ALTER TABLE users DROP COLUMN support_url');
        $db->query('ALTER TABLE stories DROP COLUMN cover_path');
        $db->query('ALTER TABLE stories DROP COLUMN language');
        $db->query('ALTER TABLE stories DROP COLUMN is_restricted');
        $db->query('DROP INDEX idx_reviews_parent');
        $db->query('ALTER TABLE reviews DROP COLUMN ip');
        $db->query('ALTER TABLE reviews DROP COLUMN parent_id');
    }
};
