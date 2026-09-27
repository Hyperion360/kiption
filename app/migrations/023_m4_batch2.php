<?php // app/migrations/023_m4_batch2.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The per-user mute list: the PK pair IS the idempotence contract
        // (toggle rides INSERT OR IGNORE against it, so a duplicate row can
        // never land) and serves the muter-side probe of the listing
        // anti-join. idx_muted_author covers the author-side lookups the
        // user-row ON DELETE CASCADE deletion probe walks (the 013/018
        // lesson: a non-prefix column of the composite PK needs its own
        // index, or the cascade full-scans muted on every user delete).
        $db->query('CREATE TABLE muted (
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            author_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            PRIMARY KEY (user_id, author_id)
        )');
        $db->query('CREATE INDEX idx_muted_author ON muted (author_id)');
        // Private messages: the thread window (sender, recipient, created_at)
        // serves the pair-or-pair thread fetch ordered by recency, and the
        // recipient index serves the inbox unread counts and the mark-read
        // UPDATE (recipient_id, read_at IS NULL).
        $db->query('CREATE TABLE messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            recipient_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            read_at TEXT
        )');
        $db->query('CREATE INDEX idx_messages_thread ON messages (sender_id, recipient_id, created_at)');
        $db->query('CREATE INDEX idx_messages_recipient ON messages (recipient_id, read_at)');
        // Wrangling: a synonym points at its canonical. Soft retirement only,
        // never deletion, so imports and old links keep resolving.
        $db->query('ALTER TABLE tags ADD COLUMN canonical_id INTEGER REFERENCES tags (id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE messages');
        $db->query('DROP TABLE muted');
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, remove tags.canonical_id manually on this host');
        }
        $db->query('ALTER TABLE tags DROP COLUMN canonical_id');
    }
};
