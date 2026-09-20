<?php // app/migrations/014_search_fts.php
return new class extends Kip\Migrations\Migration {
    // Feature-detect FTS5 (master plan R3: shared-hosting SQLite builds can
    // lack it). When absent, this migration completes as a no-op and
    // SearchRepository runs the LIKE path forever. rowids ARE domain ids:
    // stories_fts.rowid = stories.id, chapters_fts.rowid = chapters.id.
    public function up(Kip\Database $db): void
    {
        try {
            $db->query("CREATE VIRTUAL TABLE stories_fts USING fts5(title, summary, tokenize = 'porter unicode61')");
        } catch (\PDOException) {
            return;
        }
        $db->query("CREATE VIRTUAL TABLE chapters_fts USING fts5(title, content, story_id UNINDEXED, tokenize = 'porter unicode61')");
        $db->query('INSERT INTO stories_fts (rowid, title, summary) SELECT id, title, summary FROM stories');
        $db->query('INSERT INTO chapters_fts (rowid, title, content, story_id) SELECT id, title, content, story_id FROM chapters');
        $db->query('CREATE TRIGGER stories_fts_ins AFTER INSERT ON stories BEGIN
            INSERT INTO stories_fts (rowid, title, summary) VALUES (new.id, new.title, new.summary); END');
        $db->query('CREATE TRIGGER stories_fts_upd AFTER UPDATE OF title, summary ON stories BEGIN
            DELETE FROM stories_fts WHERE rowid = old.id;
            INSERT INTO stories_fts (rowid, title, summary) VALUES (new.id, new.title, new.summary); END');
        $db->query('CREATE TRIGGER stories_fts_del AFTER DELETE ON stories BEGIN
            DELETE FROM stories_fts WHERE rowid = old.id; END');
        $db->query('CREATE TRIGGER chapters_fts_ins AFTER INSERT ON chapters BEGIN
            INSERT INTO chapters_fts (rowid, title, content, story_id) VALUES (new.id, new.title, new.content, new.story_id); END');
        $db->query('CREATE TRIGGER chapters_fts_upd AFTER UPDATE OF title, content ON chapters BEGIN
            DELETE FROM chapters_fts WHERE rowid = old.id;
            INSERT INTO chapters_fts (rowid, title, content, story_id) VALUES (new.id, new.title, new.content, new.story_id); END');
        $db->query('CREATE TRIGGER chapters_fts_del AFTER DELETE ON chapters BEGIN
            DELETE FROM chapters_fts WHERE rowid = old.id; END');
        // NOTE (plan review finding 1, probed): the FTS5 'delete'-command INSERT
        // form works only on contentless tables; on ordinary tables it raises
        // "SQL logic error" at the first UPDATE/DELETE. Plain DELETE by rowid is
        // the correct sync form here.
    }

    public function down(Kip\Database $db): void
    {
        foreach (['stories_fts_ins', 'stories_fts_upd', 'stories_fts_del', 'chapters_fts_ins', 'chapters_fts_upd', 'chapters_fts_del'] as $t) {
            try { $db->query("DROP TRIGGER {$t}"); } catch (\PDOException) {}
        }
        foreach (['stories_fts', 'chapters_fts'] as $t) {
            try { $db->query("DROP TABLE {$t}"); } catch (\PDOException) {}
        }
    }
};
