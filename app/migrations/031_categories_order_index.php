<?php // app/migrations/031_categories_order_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Category listings walk the operator's order (position, then name):
        // /browse (categoriesWithCounts) and /feed/subscribe. Without this
        // index both sorted in a TEMP B-TREE on every uncached render.
        $db->query('CREATE INDEX idx_categories_order ON categories (position, name)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP INDEX idx_categories_order'); }
};
