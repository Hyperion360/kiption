<?php // app/Features/Text/migrations/049_site_text.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Languages the owner added (the shipped packs are files, not rows).
        $db->query('CREATE TABLE site_languages (
            code TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            dir TEXT NOT NULL DEFAULT \'ltr\',
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        // One row per changed string. Only text that differs from the built-in pack is stored.
        $db->query('CREATE TABLE site_text (
            lang TEXT NOT NULL,
            key TEXT NOT NULL,
            value TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            PRIMARY KEY (lang, key)
        )');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE site_text');
        $db->query('DROP TABLE site_languages');
    }
};
