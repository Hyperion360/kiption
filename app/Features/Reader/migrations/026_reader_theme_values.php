<?php // app/Features/Reader/migrations/026_reader_theme_values.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Rebuild, not UPDATE: the CHECK(theme IN ('dark','light')) constraint
        // (001_init) cannot be altered in SQLite. Column list = the live set:
        // 001 base + 010's digest_sent_at/notify_favorite_digest + 024's lang.
        // user_prefs is a child of users only (nothing references it), so the
        // DROP is safe under the Database's PRAGMA foreign_keys=ON.
        $db->query("CREATE TABLE user_prefs_new (
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
        $db->query("INSERT INTO user_prefs_new (user_id, notify_review, notify_response, notify_favorites,
                default_sort, toc_first, theme, digest_sent_at, notify_favorite_digest, lang)
            SELECT user_id, notify_review, notify_response, notify_favorites, default_sort, toc_first,
                CASE theme WHEN 'light' THEN 'paper' WHEN 'dark' THEN 'night' ELSE 'paper' END,
                digest_sent_at, notify_favorite_digest, lang FROM user_prefs");
        $db->query('DROP TABLE user_prefs');
        $db->query('ALTER TABLE user_prefs_new RENAME TO user_prefs');
    }
    public function down(Kip\Database $db): void
    {
        // Mirror rebuild: the old CHECK (dark|light) with the old default, new
        // values mapped back (paper->light, sepia/night->dark).
        $db->query("CREATE TABLE user_prefs_old (
            user_id INTEGER PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
            notify_review INTEGER NOT NULL DEFAULT 1,
            notify_response INTEGER NOT NULL DEFAULT 1,
            notify_favorites INTEGER NOT NULL DEFAULT 1,
            default_sort TEXT NOT NULL DEFAULT 'recent' CHECK (default_sort IN ('recent', 'alpha')),
            toc_first INTEGER NOT NULL DEFAULT 0,
            theme TEXT NOT NULL DEFAULT 'dark' CHECK (theme IN ('dark', 'light')),
            digest_sent_at TEXT,
            notify_favorite_digest INTEGER NOT NULL DEFAULT 0,
            lang TEXT NOT NULL DEFAULT ''
        )");
        $db->query("INSERT INTO user_prefs_old (user_id, notify_review, notify_response, notify_favorites,
                default_sort, toc_first, theme, digest_sent_at, notify_favorite_digest, lang)
            SELECT user_id, notify_review, notify_response, notify_favorites, default_sort, toc_first,
                CASE theme WHEN 'paper' THEN 'light' ELSE 'dark' END,
                digest_sent_at, notify_favorite_digest, lang FROM user_prefs");
        $db->query('DROP TABLE user_prefs');
        $db->query('ALTER TABLE user_prefs_old RENAME TO user_prefs');
    }
};
