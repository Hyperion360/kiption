<?php // app/migrations/001_init.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // users: framework auth contract (email, password_hash, is_admin) merged
        // with the archive columns. penname is nullable because Kip\Auth::register()
        // inserts only email + password; Plan 5 makes registration require one.
        $db->query('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            penname TEXT UNIQUE COLLATE NOCASE,
            legacy_md5 TEXT,
            role TEXT NOT NULL DEFAULT \'member\' CHECK (role IN (\'member\', \'validated_author\', \'moderator\', \'admin\')),
            is_admin INTEGER NOT NULL DEFAULT 0,
            bio TEXT NOT NULL DEFAULT \'\',
            avatar_path TEXT,
            is_beta INTEGER NOT NULL DEFAULT 0,
            is_locked INTEGER NOT NULL DEFAULT 0,
            age_consented_at TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $db->query('CREATE INDEX idx_users_role ON users (role)');

        $db->query('CREATE TABLE user_prefs (
            user_id INTEGER PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
            notify_review INTEGER NOT NULL DEFAULT 1,
            notify_response INTEGER NOT NULL DEFAULT 1,
            notify_favorites INTEGER NOT NULL DEFAULT 1,
            default_sort TEXT NOT NULL DEFAULT \'recent\' CHECK (default_sort IN (\'recent\', \'alpha\')),
            toc_first INTEGER NOT NULL DEFAULT 0,
            theme TEXT NOT NULL DEFAULT \'dark\' CHECK (theme IN (\'dark\', \'light\'))
        )');

        $db->query('CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER REFERENCES categories (id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT \'\',
            locked INTEGER NOT NULL DEFAULT 0,
            position INTEGER NOT NULL DEFAULT 0
        )');

        $db->query('CREATE TABLE characters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL REFERENCES categories (id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT \'\'
        )');
        $db->query('CREATE INDEX idx_characters_category ON characters (category_id)');

        $db->query('CREATE TABLE tag_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        )');

        $db->query('CREATE TABLE tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tag_type_id INTEGER NOT NULL REFERENCES tag_types (id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            UNIQUE (tag_type_id, name)
        )');
        $db->query('CREATE INDEX idx_tags_type ON tags (tag_type_id)');

        $db->query('CREATE TABLE ratings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            is_adult INTEGER NOT NULL DEFAULT 0,
            warning_text TEXT NOT NULL DEFAULT \'\',
            position INTEGER NOT NULL DEFAULT 0
        )');

        $db->query('CREATE TABLE stories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            summary TEXT NOT NULL DEFAULT \'\',
            notes TEXT NOT NULL DEFAULT \'\',
            author_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            rating_id INTEGER NOT NULL REFERENCES ratings (id),
            completed INTEGER NOT NULL DEFAULT 0,
            featured INTEGER NOT NULL DEFAULT 0,
            validated INTEGER NOT NULL DEFAULT 0,
            round_robin INTEGER NOT NULL DEFAULT 0,
            word_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            deleted_at TEXT
        )');
        $db->query('CREATE INDEX idx_stories_updated ON stories (updated_at)');
        $db->query('CREATE INDEX idx_stories_author ON stories (author_id)');
        $db->query('CREATE INDEX idx_stories_validated ON stories (validated)');
        $db->query('CREATE INDEX idx_stories_rating ON stories (rating_id)');

        $db->query('CREATE TABLE story_categories (
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            category_id INTEGER NOT NULL REFERENCES categories (id) ON DELETE CASCADE,
            PRIMARY KEY (story_id, category_id)
        )');
        $db->query('CREATE INDEX idx_story_categories_category ON story_categories (category_id)');

        $db->query('CREATE TABLE story_tags (
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            tag_id INTEGER NOT NULL REFERENCES tags (id) ON DELETE CASCADE,
            PRIMARY KEY (story_id, tag_id)
        )');
        $db->query('CREATE INDEX idx_story_tags_tag ON story_tags (tag_id)');

        $db->query('CREATE TABLE story_characters (
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            character_id INTEGER NOT NULL REFERENCES characters (id) ON DELETE CASCADE,
            PRIMARY KEY (story_id, character_id)
        )');
        $db->query('CREATE INDEX idx_story_characters_character ON story_characters (character_id)');

        $db->query('CREATE TABLE coauthors (
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            PRIMARY KEY (story_id, user_id)
        )');

        $db->query('CREATE TABLE chapters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            position INTEGER NOT NULL,
            title TEXT NOT NULL DEFAULT \'\',
            notes_before TEXT NOT NULL DEFAULT \'\',
            content TEXT NOT NULL,
            notes_after TEXT NOT NULL DEFAULT \'\',
            validated INTEGER NOT NULL DEFAULT 0,
            word_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            UNIQUE (story_id, position)
        )');

        $db->query('CREATE TABLE series (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            summary TEXT NOT NULL DEFAULT \'\',
            owner_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            membership TEXT NOT NULL DEFAULT \'closed\' CHECK (membership IN (\'open\', \'moderated\', \'closed\')),
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $db->query('CREATE INDEX idx_series_owner ON series (owner_id)');

        $db->query('CREATE TABLE series_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            series_id INTEGER NOT NULL REFERENCES series (id) ON DELETE CASCADE,
            story_id INTEGER REFERENCES stories (id) ON DELETE CASCADE,
            subseries_id INTEGER REFERENCES series (id) ON DELETE CASCADE,
            position INTEGER NOT NULL DEFAULT 0,
            confirmed INTEGER NOT NULL DEFAULT 1,
            CHECK ((story_id IS NULL) <> (subseries_id IS NULL))
        )');
        $db->query('CREATE UNIQUE INDEX idx_series_items_story ON series_items (series_id, story_id) WHERE story_id IS NOT NULL');
        $db->query('CREATE UNIQUE INDEX idx_series_items_sub ON series_items (series_id, subseries_id) WHERE subseries_id IS NOT NULL');

        $db->query('CREATE TABLE reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            story_id INTEGER REFERENCES stories (id) ON DELETE CASCADE,
            series_id INTEGER REFERENCES series (id) ON DELETE CASCADE,
            chapter_id INTEGER REFERENCES chapters (id) ON DELETE CASCADE,
            user_id INTEGER REFERENCES users (id) ON DELETE SET NULL,
            guest_name TEXT,
            body TEXT,
            rating INTEGER CHECK (rating IS NULL OR (rating >= 0 AND rating <= 10)),
            response TEXT,
            responded_at TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            CHECK (story_id IS NOT NULL OR series_id IS NOT NULL)
        )');
        $db->query('CREATE INDEX idx_reviews_story ON reviews (story_id)');
        $db->query('CREATE INDEX idx_reviews_series ON reviews (series_id)');
        $db->query('CREATE INDEX idx_reviews_chapter ON reviews (chapter_id)');
        $db->query('CREATE INDEX idx_reviews_user ON reviews (user_id)');

        $db->query('CREATE TABLE favorites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            story_id INTEGER REFERENCES stories (id) ON DELETE CASCADE,
            series_id INTEGER REFERENCES series (id) ON DELETE CASCADE,
            author_id INTEGER REFERENCES users (id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            CHECK ((story_id IS NULL) + (series_id IS NULL) + (author_id IS NULL) = 2)
        )');
        $db->query('CREATE UNIQUE INDEX idx_favorites_story ON favorites (user_id, story_id) WHERE story_id IS NOT NULL');
        $db->query('CREATE UNIQUE INDEX idx_favorites_series ON favorites (user_id, series_id) WHERE series_id IS NOT NULL');
        $db->query('CREATE UNIQUE INDEX idx_favorites_author ON favorites (user_id, author_id) WHERE author_id IS NOT NULL');

        $db->query('CREATE TABLE news (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER REFERENCES users (id) ON DELETE SET NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            published_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $db->query('CREATE INDEX idx_news_published ON news (published_at)');

        $db->query('CREATE TABLE news_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            news_id INTEGER NOT NULL REFERENCES news (id) ON DELETE CASCADE,
            user_id INTEGER REFERENCES users (id) ON DELETE SET NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');

        $db->query('CREATE TABLE pages (
            slug TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');

        $db->query('CREATE TABLE mail_templates (
            name TEXT PRIMARY KEY,
            subject TEXT NOT NULL,
            body TEXT NOT NULL
        )');

        // page_stats: chapter_id 0 is the story-level rollup row; real chapter
        // ids are > 0. Keeps the composite PK free of NULLs. No FK on
        // chapter_id on purpose: the 0 sentinel has no chapter row, and stats
        // are day-scoped rollups pruned by day, not cascade targets.
        $db->query('CREATE TABLE page_stats (
            day TEXT NOT NULL,
            story_id INTEGER NOT NULL REFERENCES stories (id) ON DELETE CASCADE,
            chapter_id INTEGER NOT NULL DEFAULT 0,
            reads INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (day, story_id, chapter_id)
        )');
        $db->query('CREATE INDEX idx_page_stats_story ON page_stats (story_id)');

        // Framework auth contract: identical shapes to the skeleton migrations
        // they replace, so Kip\Auth throttling and password reset work unchanged.
        // login_attempts.kind is the split throttle bucket (login vs
        // password_reset) that skeleton 006 adds via ALTER; here it is native.
        $db->query('CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            ip TEXT NOT NULL,
            attempted_at TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT \'login\'
        )');
        $db->query('CREATE INDEX idx_login_attempts_lookup ON login_attempts (email, ip, attempted_at)');
        $db->query('CREATE TABLE password_resets (
            email TEXT PRIMARY KEY,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL
        )');
        $db->query('CREATE UNIQUE INDEX idx_password_resets_token ON password_resets (token_hash)');

        // Migration tooling (see 2026-09-18-efiction-migration-tool.md).
        // import_runs.checkpoints holds per-table {last processed key, row count}
        // as JSON; import_runs.counts-of-rejects and resume logic read it.
        $db->query('CREATE TABLE import_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            finished_at TEXT,
            options_hash TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'running\',
            checkpoints TEXT NOT NULL DEFAULT \'{}\'
        )');
        $db->query('CREATE TABLE import_map (
            legacy_table TEXT NOT NULL,
            legacy_id TEXT NOT NULL,
            new_table TEXT NOT NULL,
            new_id INTEGER NOT NULL,
            run_id INTEGER NOT NULL,
            PRIMARY KEY (legacy_table, legacy_id)
        )');
        $db->query('CREATE INDEX idx_import_map_reverse ON import_map (new_table, new_id)');
        $db->query('CREATE TABLE legacy_urls (
            legacy_path TEXT NOT NULL,
            params TEXT NOT NULL,
            target_type TEXT NOT NULL CHECK (target_type IN (\'story\', \'series\', \'user\', \'category\', \'page\')),
            target_id INTEGER NOT NULL,
            PRIMARY KEY (legacy_path, params)
        )');
        $db->query('CREATE TABLE legacy_log (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_action TEXT,
            log_uid INTEGER,
            log_timestamp TEXT,
            log_type TEXT
        )');
    }

    public function down(Kip\Database $db): void
    {
        foreach ([
            'legacy_log', 'legacy_urls', 'import_map', 'import_runs',
            'password_resets', 'login_attempts',
            'page_stats', 'mail_templates', 'pages', 'news_comments', 'news',
            'favorites', 'reviews', 'series_items', 'series', 'chapters',
            'coauthors', 'story_characters', 'story_tags', 'story_categories',
            'stories', 'ratings', 'tags', 'tag_types', 'characters',
            'categories', 'user_prefs', 'users',
        ] as $table) {
            $db->query("DROP TABLE IF EXISTS {$table}");
        }
    }
};
