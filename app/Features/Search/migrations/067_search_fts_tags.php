<?php // app/Features/Search/migrations/067_search_fts_tags.php
declare(strict_types=1);
return new class extends Kip\Migrations\Migration {
    // stories_fts gains a third column, tags: the names of a story's attached
    // tags plus the names of those tags' synonyms. FTS5 cannot add a column in
    // place, so the table is dropped and rebuilt from stories. A no-op when
    // FTS5 is missing (014 created nothing): the LIKE path serves search.
    // Triggers keep the column live on every tag writer, so no PHP write path
    // needs to know about the index. Plain DELETE by rowid plus INSERT is the
    // sync form (the FTS5 'delete' command only works on contentless tables).
    //
    // Tag deletes need a BEFORE trigger: story_tags.tag_id cascades, and
    // SQLite removes the child rows before an AFTER trigger on tags runs, so
    // an AFTER trigger would find no affected stories and leave stale text.
    // The BEFORE trigger still sees the attachments and computes each story's
    // text with the dying tag's name (and its synonyms' names) excluded,
    // which is exactly the text the story will have after the delete.

    /** SQL text of the tags column for the story whose id is $storyExpr. */
    private function tagsText(string $storyExpr): string
    {
        return "(SELECT COALESCE(group_concat(n, ' '), '') FROM (
                    SELECT t.name AS n FROM story_tags st JOIN tags t ON t.id = st.tag_id WHERE st.story_id = {$storyExpr}
                    UNION
                    SELECT syn.name FROM story_tags st JOIN tags syn ON syn.canonical_id = st.tag_id WHERE st.story_id = {$storyExpr}))";
    }

    /** The tags text as it will be once tag old.id is gone: the direct name
     *  and the names of old.id's synonyms are left out. Every other tag keeps
     *  contributing its name, including a same-named tag of another type. */
    private function tagsTextWithout(string $storyExpr): string
    {
        return "(SELECT COALESCE(group_concat(n, ' '), '') FROM (
                    SELECT t.name AS n FROM story_tags st JOIN tags t ON t.id = st.tag_id WHERE st.story_id = {$storyExpr} AND t.id <> old.id
                    UNION
                    SELECT syn.name FROM story_tags st JOIN tags syn ON syn.canonical_id = st.tag_id WHERE st.story_id = {$storyExpr} AND syn.canonical_id <> old.id))";
    }

    /** Statements that reindex every story returned by $storyIdsSql. */
    private function reindex(string $storyIdsSql): string
    {
        return "DELETE FROM stories_fts WHERE rowid IN ({$storyIdsSql});
                INSERT INTO stories_fts (rowid, title, summary, tags)
                  SELECT s.id, s.title, s.summary, " . $this->tagsText('s.id') . " FROM stories s WHERE s.id IN ({$storyIdsSql});";
    }

    private function reindexWithout(string $storyIdsSql): string
    {
        return "DELETE FROM stories_fts WHERE rowid IN ({$storyIdsSql});
                INSERT INTO stories_fts (rowid, title, summary, tags)
                  SELECT s.id, s.title, s.summary, " . $this->tagsTextWithout('s.id') . " FROM stories s WHERE s.id IN ({$storyIdsSql});";
    }

    /** True only when stories_fts is the FTS5 table 014 built. A database
     *  without FTS5 has no such row; one where 014 no-opped (the name taken
     *  by an ordinary table) has a row whose sql says CREATE TABLE. */
    private function present(Kip\Database $db): bool
    {
        return $db->one("SELECT 1 FROM sqlite_master WHERE name = 'stories_fts' AND sql LIKE '%fts5%'") !== null;
    }

    public function up(Kip\Database $db): void
    {
        if (!$this->present($db)) {
            return;
        }
        foreach (['stories_fts_ins', 'stories_fts_upd'] as $t) {
            $db->query("DROP TRIGGER IF EXISTS {$t}");
        }
        $db->query('DROP TABLE stories_fts');
        $db->query("CREATE VIRTUAL TABLE stories_fts USING fts5(title, summary, tags, tokenize = 'porter unicode61', prefix = '2 3')");
        $db->query('INSERT INTO stories_fts (rowid, title, summary, tags) SELECT s.id, s.title, s.summary, ' . $this->tagsText('s.id') . ' FROM stories s');
        $db->query('CREATE TRIGGER stories_fts_ins AFTER INSERT ON stories BEGIN
            INSERT INTO stories_fts (rowid, title, summary, tags) VALUES (new.id, new.title, new.summary, ' . $this->tagsText('new.id') . '); END');
        $db->query('CREATE TRIGGER stories_fts_upd AFTER UPDATE OF title, summary ON stories BEGIN
            DELETE FROM stories_fts WHERE rowid = old.id;
            INSERT INTO stories_fts (rowid, title, summary, tags) VALUES (new.id, new.title, new.summary, ' . $this->tagsText('new.id') . '); END');
        // stories_fts_del (014) stays: it only needs the rowid.
        $db->query('CREATE TRIGGER stories_fts_tag_ins AFTER INSERT ON story_tags BEGIN ' . $this->reindex('new.story_id') . ' END');
        $db->query('CREATE TRIGGER stories_fts_tag_del AFTER DELETE ON story_tags BEGIN ' . $this->reindex('old.story_id') . ' END');
        // A new synonym row (INSERT with canonical_id) adds its name to every
        // story attached to the canonical. A new canonical has no attachments
        // yet, and tag_id = NULL matches nothing, so one form serves both.
        $db->query('CREATE TRIGGER stories_fts_tags_ins AFTER INSERT ON tags BEGIN '
            . $this->reindex('SELECT story_id FROM story_tags WHERE tag_id = new.canonical_id') . ' END');
        $affected = 'SELECT story_id FROM story_tags WHERE tag_id IN (old.id, new.id, old.canonical_id, new.canonical_id)';
        $db->query('CREATE TRIGGER stories_fts_tags_upd AFTER UPDATE OF name, canonical_id ON tags BEGIN ' . $this->reindex($affected) . ' END');
        $db->query('CREATE TRIGGER stories_fts_tags_del BEFORE DELETE ON tags BEGIN '
            . $this->reindexWithout('SELECT story_id FROM story_tags WHERE tag_id = old.id') . ' END');
    }

    public function down(Kip\Database $db): void
    {
        if (!$this->present($db)) {
            return;
        }
        foreach (['stories_fts_ins', 'stories_fts_upd', 'stories_fts_tag_ins', 'stories_fts_tag_del', 'stories_fts_tags_ins', 'stories_fts_tags_upd', 'stories_fts_tags_del'] as $t) {
            $db->query("DROP TRIGGER IF EXISTS {$t}");
        }
        $db->query('DROP TABLE stories_fts');
        $db->query("CREATE VIRTUAL TABLE stories_fts USING fts5(title, summary, tokenize = 'porter unicode61')");
        $db->query('INSERT INTO stories_fts (rowid, title, summary) SELECT id, title, summary FROM stories');
        $db->query('CREATE TRIGGER stories_fts_ins AFTER INSERT ON stories BEGIN
            INSERT INTO stories_fts (rowid, title, summary) VALUES (new.id, new.title, new.summary); END');
        $db->query('CREATE TRIGGER stories_fts_upd AFTER UPDATE OF title, summary ON stories BEGIN
            DELETE FROM stories_fts WHERE rowid = old.id;
            INSERT INTO stories_fts (rowid, title, summary) VALUES (new.id, new.title, new.summary); END');
    }
};
