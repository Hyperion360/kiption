<?php // app/migrations/036_content_policy_page.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Backfills the content-policy page onto installs that predate the
        // Seeder::bootstrap addition: existing archives upgrade into the
        // launch policy instead of waiting for a fresh db:blank. OR IGNORE
        // on the slug: an operator's own page (or a bootstrap-seeded one)
        // is never overwritten, exactly like the seeded About page. The
        // clause text is frozen here on purpose: a migration is history,
        // it does not track Seeder wording.
        $db->query("INSERT OR IGNORE INTO pages (slug, title, body) VALUES ('content-policy', 'Content policy', ?)",
            ['This archive hosts original fiction and fanfiction for a general readership.

No works whose primary purpose is sexual gratification. Romance is welcome, including mature romance behind the content gate, but material written to arouse is out of scope for this archive and will be removed through the validation queue and reports.']);
    }
    public function down(Kip\Database $db): void
    {
        // Only lifts a row this migration itself could have inserted: an
        // operator-written or bootstrap-seeded page has no marker and stays.
        $db->query("DELETE FROM pages WHERE slug = 'content-policy' AND body LIKE '%material written to arouse is out of scope%'");
    }
};
