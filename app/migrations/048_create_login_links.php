<?php // app/migrations/048_create_login_links.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // One-time sign-in links (App\LoginLinks). token_hash is the sha256
        // of the emailed token, never the token. pwd_epoch is the password
        // hash prefix at mint: a password change since then voids the link.
        $db->query('CREATE TABLE login_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE,
            pwd_epoch TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $db->query('CREATE INDEX idx_login_links_user ON login_links (user_id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE login_links');
    }
};
