<?php // app/migrations/035_legacy_page_urls.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // One purpose: eFiction's viewpage.php?page={message_name} -> the
        // imported page_slug. legacy_name is the PK (one mapping per legacy
        // name); page_slug references pages.slug with cascades so deleting or
        // renaming a page removes or follows the mapping. pages.slug is TEXT
        // while legacy_urls.target_id is INTEGER, which is why this table is
        // separate. Reversible: down() drops it, the importer rewrites it on
        // the next commit.
        $db->query('CREATE TABLE legacy_page_urls (legacy_name TEXT PRIMARY KEY, page_slug TEXT NOT NULL REFERENCES pages(slug) ON DELETE CASCADE ON UPDATE CASCADE)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE legacy_page_urls');
    }
};
