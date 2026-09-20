<?php // app/migrations/016_nav_links.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE nav_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            url TEXT NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            is_hidden INTEGER NOT NULL DEFAULT 0
        )');
        // Finding 13: news_comments has no index (001 created none) and every
        // news surface filters on it; (news_id, created_at) serves the fold,
        // the count subquery, and the throttle window.
        $db->query('CREATE INDEX idx_news_comments_news ON news_comments (news_id, created_at)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX idx_news_comments_news');
        $db->query('DROP TABLE nav_links');
    }
};
