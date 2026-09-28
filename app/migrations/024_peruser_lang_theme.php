<?php // app/migrations/024_peruser_lang_theme.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The per-user UI language (M4 final): empty string = follow the
        // archive default (config ui_lang), a two-letter pack code = the
        // member's own choice. The dormant theme column beside it becomes
        // live in the same phase without schema change (CHECK dark|light
        // already pins its shape).
        $db->query('ALTER TABLE user_prefs ADD COLUMN lang TEXT NOT NULL DEFAULT \'\'');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, remove user_prefs.lang manually on this host');
        }
        $db->query('ALTER TABLE user_prefs DROP COLUMN lang');
    }
};
