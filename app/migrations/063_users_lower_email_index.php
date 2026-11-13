<?php // app/migrations/063_users_lower_email_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Registration, sign-in links and email change all match emails with
        // lower(email) = lower(?), which scanned the whole users table. Not
        // UNIQUE: an older archive may already hold two case-variant rows,
        // and a unique index would fail the upgrade.
        $db->query('CREATE INDEX idx_users_lower_email ON users (lower(email))');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX idx_users_lower_email');
    }
};
