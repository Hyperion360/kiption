<?php // app/migrations/003_add_meta_description.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('ALTER TABLE stories ADD COLUMN meta_description TEXT');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, remove stories.meta_description manually on this host');
        }
        $db->query('ALTER TABLE stories DROP COLUMN meta_description');
    }
};
