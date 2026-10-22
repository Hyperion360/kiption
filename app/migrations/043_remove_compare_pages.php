<?php // app/migrations/043_remove_compare_pages.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The /compare marketing pages left the product: comparison copy is
        // documentation now (docs/guides/SWITCHING.md), not rows on every
        // install. This lifts the migration-seeded 042 row (and any operator
        // page that copied the slug prefix); databases that never ran 042
        // get a no-op.
        $db->query("DELETE FROM pages WHERE slug LIKE 'compare-%'");
    }
    public function down(Kip\Database $db): void
    {
        // No-op on purpose: the marketing row stays removed. 042's history
        // records its copy for anyone who wants it back by hand.
    }
};
