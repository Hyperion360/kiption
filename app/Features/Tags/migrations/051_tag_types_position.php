<?php // app/Features/Tags/migrations/051_tag_types_position.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Tag types get an order the owner can change. Existing types keep
        // their current order (by id) as their starting positions.
        $db->query('ALTER TABLE tag_types ADD COLUMN position INTEGER NOT NULL DEFAULT 0');
        $db->query('UPDATE tag_types SET position = id');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare((string) $db->one('SELECT sqlite_version() AS v')['v'], '3.35.0', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, remove tag_types.position manually on this host');
        }
        $db->query('ALTER TABLE tag_types DROP COLUMN position');
    }
};
