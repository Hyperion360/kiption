<?php // app/migrations/053_create_mass_mail_log.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // One row per owner broadcast from /mailusers (App\MassMail). The
        // newest row's sent_at is the ten-minute rate limit; the rest is the
        // owner's record of what went out and to how many.
        $db->query('CREATE TABLE mass_mail_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            subject TEXT NOT NULL,
            recipients INTEGER NOT NULL,
            sent_at TEXT NOT NULL
        )');
        $db->query('CREATE INDEX idx_mass_mail_log_sent ON mass_mail_log (sent_at)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE mass_mail_log');
    }
};
