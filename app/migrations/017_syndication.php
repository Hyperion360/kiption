<?php // app/migrations/017_syndication.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Both nullable: NULL means the story is in the self-canonical state.
        // canonical_url: absolute external home (deindexes locally); crosspost_url:
        // the work is canonical elsewhere, the local page suppresses its link.
        $db->query('ALTER TABLE stories ADD COLUMN canonical_url TEXT');
        $db->query('ALTER TABLE stories ADD COLUMN crosspost_url TEXT');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, remove stories.canonical_url and stories.crosspost_url manually on this host');
        }
        $db->query('ALTER TABLE stories DROP COLUMN canonical_url');
        $db->query('ALTER TABLE stories DROP COLUMN crosspost_url');
    }
};
