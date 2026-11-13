<?php // app/Features/Account/migrations/056_create_email_changes.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // A member's pending change of email address (App\AccountSecurity).
        // One row per member: asking again replaces it. token_hash is the
        // sha256 of the token mailed to the NEW address, never the token.
        // The address on users only changes when that link is used.
        // pwd_epoch is the password-hash prefix at request time: a password
        // change or reset moves it, which voids the pending change.
        $db->query('CREATE TABLE email_changes (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            new_email TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            pwd_epoch TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE email_changes');
    }
};
