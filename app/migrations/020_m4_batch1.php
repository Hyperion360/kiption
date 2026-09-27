<?php // app/migrations/020_m4_batch1.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The challenges triple mirrors the series pattern: owner-gated
        // containers with slug identities, an open/moderated/closed membership
        // CHECK, and item rows joining stories in with a confirmed flag.
        $db->query('CREATE TABLE challenges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            summary TEXT NOT NULL DEFAULT \'\',
            owner_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            membership TEXT NOT NULL DEFAULT \'closed\' CHECK (membership IN (\'open\', \'moderated\', \'closed\')),
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $db->query('CREATE TABLE challenge_prompts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            challenge_id INTEGER NOT NULL REFERENCES challenges (id) ON DELETE CASCADE,
            position INTEGER NOT NULL DEFAULT 0,
            prompt_text TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        // The public page's prompts blob walks each challenge's prompts by
        // position (the series_items COUNT lesson, restated for prompts): the
        // fold's correlated WHERE challenge_id = ? ORDER BY position must
        // resolve as a SEARCH, never a scan plus TEMP B-TREE per row.
        $db->query('CREATE INDEX idx_challenge_prompts_challenge ON challenge_prompts (challenge_id, position)');
        $db->query('CREATE TABLE challenge_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            challenge_id INTEGER NOT NULL REFERENCES challenges (id) ON DELETE CASCADE,
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            position INTEGER NOT NULL DEFAULT 0,
            confirmed INTEGER NOT NULL DEFAULT 1,
            UNIQUE (challenge_id, story_id)
        )');
        // The story-side lookup (the builder's gated enumeration and every
        // purge rider) walks items by story_id, a NON-prefix column of the
        // UNIQUE (challenge_id, story_id) constraint (the migration 013/018
        // lesson); the constraint itself covers the page fold's
        // challenge_id searches.
        $db->query('CREATE INDEX idx_challenge_items_story ON challenge_items (story_id)');
        // Gift-recipient metadata on stories; scheduled-release bookkeeping on
        // chapters (validated = 0 plus a publish_at instant while waiting).
        $db->query('ALTER TABLE stories ADD COLUMN gift_to TEXT');
        $db->query('ALTER TABLE chapters ADD COLUMN publish_at TEXT');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE challenge_items');
        $db->query('DROP TABLE challenge_prompts');
        $db->query('DROP TABLE challenges');
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, remove stories.gift_to and chapters.publish_at manually on this host');
        }
        $db->query('ALTER TABLE stories DROP COLUMN gift_to');
        $db->query('ALTER TABLE chapters DROP COLUMN publish_at');
    }
};
