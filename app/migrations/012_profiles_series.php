<?php // app/migrations/012_profiles_series.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('ALTER TABLE users ADD COLUMN profile_slug TEXT');
        $db->query('CREATE UNIQUE INDEX idx_users_profile_slug ON users (profile_slug)');
        $repo = new \App\Repositories\UserRepository($db);
        foreach ($db->all('SELECT id FROM users WHERE penname IS NOT NULL AND profile_slug IS NULL') as $u) {
            $repo->backfillProfileSlug((int) $u['id']);
        }
        $db->query('CREATE TABLE contact_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            target_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $db->query('CREATE INDEX idx_contact_log_sender ON contact_log (sender_id, created_at)');
    }

    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE contact_log');
        $db->query('DROP INDEX idx_users_profile_slug');
        $db->query('ALTER TABLE users DROP COLUMN profile_slug');
    }
};
