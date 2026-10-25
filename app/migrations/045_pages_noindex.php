<?php // app/migrations/045_pages_noindex.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Per-page noindex (0 = index, the default): the admin checkbox that
        // takes a page out of search engines without emptying its body (the
        // empty-body precedent was body-driven only). The column rides the
        // rows the view path already loads; the one-query budget holds.
        // down() needs SQLite >= 3.35 for DROP COLUMN (the 037/041 shape).
        $db->query('ALTER TABLE pages ADD COLUMN noindex INTEGER NOT NULL DEFAULT 0');
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, drop pages.noindex manually on this host');
        }
        $db->query('ALTER TABLE pages DROP COLUMN noindex');
    }
};
