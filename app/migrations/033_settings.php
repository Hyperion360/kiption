<?php // app/migrations/033_settings.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // One row per operator-managed setting only: absent means the
        // config.php value applies. The rows ride the SAME boot statement as
        // the feature flags (Features::resolve folds both in one UNION ALL),
        // so the settings table adds zero boot round trips.
        $db->query('CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE settings');
    }
};
