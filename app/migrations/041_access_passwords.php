<?php // app/migrations/041_access_passwords.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The post-password gate (Fictioneer parity, researched 2026-10-21):
        // a story or chapter row may carry an access hash. The column rides
        // the rows the read path already loads, so the one-query budget
        // holds; NULL means open. down() needs SQLite >= 3.35 for the
        // DROP COLUMN pair.
        $db->query('ALTER TABLE stories ADD COLUMN access_hash TEXT');
        $db->query('ALTER TABLE chapters ADD COLUMN access_hash TEXT');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, drop stories.access_hash and chapters.access_hash manually on this host');
        }
        $db->query('ALTER TABLE stories DROP COLUMN access_hash');
        $db->query('ALTER TABLE chapters DROP COLUMN access_hash');
    }
};
