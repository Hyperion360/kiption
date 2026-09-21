<?php // app/migrations/019_feature_flags.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // One row per OVERridden flag only: absent means the config-shipped
        // default (or the inventory default) applies. updated_at re-defaults
        // on every INSERT OR REPLACE, so it reads as last-toggled-at.
        $db->query('CREATE TABLE feature_flags (
            key TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE feature_flags');
    }
};
