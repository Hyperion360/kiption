<?php // app/migrations/040_import_runs_importer.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Import identity (review 2026-10-21): the options-family guard was
        // global, so a second importer would refuse to run after the first.
        // Every existing run is eFiction's (the only importer until now).
        $db->query("ALTER TABLE import_runs ADD COLUMN importer TEXT NOT NULL DEFAULT 'efiction'");
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, drop import_runs.importer manually on this host');
        }
        $db->query('ALTER TABLE import_runs DROP COLUMN importer');
    }
};
