<?php // app/migrations/072_cloud_page.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The simplified /cloud page every install ships. A migration runs
        // once, so an admin who deletes the page never sees it seeded again,
        // and OR IGNORE keeps a page the operator already wrote at this slug.
        $db->query("INSERT OR IGNORE INTO pages (slug, title, body) VALUES ('cloud', 'Kiption Cloud', ?)",
            ['Want Kiption hosted for you? We run it, back it up, and keep it updated.']);
    }
    public function down(Kip\Database $db): void
    {
        $db->query("DELETE FROM pages WHERE slug = 'cloud' AND body = 'Want Kiption hosted for you? We run it, back it up, and keep it updated.'");
    }
};
