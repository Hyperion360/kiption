<?php // app/migrations/044_create_mail_sends.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The daily outbound mail counter (App\MailGuard, config
        // 'mail_daily_cap'). One row per UTC day; the counting upsert's ON
        // CONFLICT target is the primary key itself (the rate_limits shape,
        // migration 025), so consult and increment are one seek. day is a
        // plain YYYY-MM-DD string: the prune compares lexically, the
        // login-throttle's julianday-on-column lesson in reverse.
        $db->query('CREATE TABLE mail_sends (
            day TEXT NOT NULL,
            sent INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (day)
        )');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE mail_sends'); }
};
