<?php // app/Features/Reader/migrations/029_user_prefs_theme_nullable.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The theme column becomes nullable, NULL meaning Auto (follow the
        // device). Two defects forced this rebuild: 001_init declared the
        // column NOT NULL DEFAULT 'dark', so every prefs row created for any
        // other reason (a notification toggle, a language pick) silently
        // carried "dark", which 026 mapped to night and the login sync then
        // applied as a theme the member never chose; and Auto could not be
        // stored at all (it was written as paper), so a member who picked
        // Auto was forced to Paper on every new device. Stored values are
        // reset to NULL: an inherited default cannot be told apart from a
        // real choice, and the browser's own theme cookie keeps each
        // device's current look, so nothing visible changes until a member
        // chooses again. Same column list and rebuild shape as 026.
        $db->query("CREATE TABLE user_prefs_new (
            user_id INTEGER PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
            notify_review INTEGER NOT NULL DEFAULT 1,
            notify_response INTEGER NOT NULL DEFAULT 1,
            notify_favorites INTEGER NOT NULL DEFAULT 1,
            default_sort TEXT NOT NULL DEFAULT 'recent' CHECK (default_sort IN ('recent', 'alpha')),
            toc_first INTEGER NOT NULL DEFAULT 0,
            theme TEXT DEFAULT NULL CHECK (theme IS NULL OR theme IN ('paper', 'sepia', 'night')),
            digest_sent_at TEXT,
            notify_favorite_digest INTEGER NOT NULL DEFAULT 0,
            lang TEXT NOT NULL DEFAULT ''
        )");
        $db->query("INSERT INTO user_prefs_new (user_id, notify_review, notify_response, notify_favorites,
                default_sort, toc_first, theme, digest_sent_at, notify_favorite_digest, lang)
            SELECT user_id, notify_review, notify_response, notify_favorites, default_sort, toc_first,
                NULL, digest_sent_at, notify_favorite_digest, lang FROM user_prefs");
        $db->query('DROP TABLE user_prefs');
        $db->query('ALTER TABLE user_prefs_new RENAME TO user_prefs');
    }
    public function down(Kip\Database $db): void
    {
        // Back to 026's NOT NULL shape; NULL (Auto) maps to paper, as 026 stored it.
        $db->query("CREATE TABLE user_prefs_old (
            user_id INTEGER PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
            notify_review INTEGER NOT NULL DEFAULT 1,
            notify_response INTEGER NOT NULL DEFAULT 1,
            notify_favorites INTEGER NOT NULL DEFAULT 1,
            default_sort TEXT NOT NULL DEFAULT 'recent' CHECK (default_sort IN ('recent', 'alpha')),
            toc_first INTEGER NOT NULL DEFAULT 0,
            theme TEXT NOT NULL DEFAULT 'paper' CHECK (theme IN ('paper', 'sepia', 'night')),
            digest_sent_at TEXT,
            notify_favorite_digest INTEGER NOT NULL DEFAULT 0,
            lang TEXT NOT NULL DEFAULT ''
        )");
        $db->query("INSERT INTO user_prefs_old (user_id, notify_review, notify_response, notify_favorites,
                default_sort, toc_first, theme, digest_sent_at, notify_favorite_digest, lang)
            SELECT user_id, notify_review, notify_response, notify_favorites, default_sort, toc_first,
                COALESCE(theme, 'paper'), digest_sent_at, notify_favorite_digest, lang FROM user_prefs");
        $db->query('DROP TABLE user_prefs');
        $db->query('ALTER TABLE user_prefs_old RENAME TO user_prefs');
    }
};
