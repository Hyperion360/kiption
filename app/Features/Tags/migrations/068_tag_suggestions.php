<?php // app/Features/Tags/migrations/068_tag_suggestions.php
declare(strict_types=1);
return new class extends Kip\Migrations\Migration {
    // Pending tag suggestions live in their own table, never in tags, so no
    // existing reader of tags (story pages, search, browse, feeds, sitemap,
    // importers, the seeder) can leak a pending name. Approved rows are
    // deleted; the audit trail lives in the activity log. A rejected row
    // stays, so the same name on the same story is not re-asked.
    // UNIQUE (story_id, tag_type_id, name COLLATE NOCASE): one pending or
    // rejected suggestion per name per story per type, case-variants included
    // (probed: the expression unique refuses 'slow burn' after 'Slow Burn').

    public function up(Kip\Database $db): void
    {
        $db->query("CREATE TABLE tag_suggestions (
            id INTEGER PRIMARY KEY,
            tag_type_id INTEGER NOT NULL REFERENCES tag_types (id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            suggested_by INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'rejected')),
            created_at TEXT NOT NULL,
            resolved_at TEXT,
            resolved_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
            UNIQUE (story_id, tag_type_id, name COLLATE NOCASE)
        )");
        $db->query('CREATE INDEX idx_tag_sugg_queue ON tag_suggestions(status, tag_type_id, name COLLATE NOCASE)');
        $db->query('CREATE INDEX idx_tag_sugg_user ON tag_suggestions(suggested_by, status)');
    }

    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS tag_suggestions');
    }
};
