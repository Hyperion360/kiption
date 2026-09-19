<?php // app/migrations/004_auth_authoring.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('ALTER TABLE users ADD COLUMN email_verified_at TEXT');
        $db->query('ALTER TABLE users ADD COLUMN approved_at TEXT');
        // Grandfather: every existing account is active (they predate the gates).
        $db->query("UPDATE users SET email_verified_at = COALESCE(created_at, datetime('now')),
                    approved_at = COALESCE(created_at, datetime('now'))");
        $db->query('CREATE TABLE email_verifications (
            email TEXT PRIMARY KEY,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL
        )');
        $db->query('CREATE TABLE invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            created_by INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            used_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
            used_at TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35; remove the columns manually on this host');
        }
        $db->query('DROP TABLE invites');
        $db->query('DROP TABLE email_verifications');
        $db->query('ALTER TABLE users DROP COLUMN approved_at');
        $db->query('ALTER TABLE users DROP COLUMN email_verified_at');
    }
};
