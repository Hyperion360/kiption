<?php // app/Features/Search/migrations/069_search_trigram.php
declare(strict_types=1);
return new class extends Kip\Migrations\Migration {
    // Typo tolerance (the owner's last slice): a trigram copy of the story
    // index over title and tag names. The search fold reads it as a guarded
    // second arm of the SAME statement, so the budget stays one query and a
    // story the normal arm matched is never contributed twice. The tag names
    // text is the same expression migration 067 indexes into stories_fts, so
    // a reader's typo reaches tags too ("Rivrs" finds "Slow Rivers").
    //
    // Feature detection mirrors 014 and 067: no FTS5, or no trigram
    // tokenizer in this SQLite build, means the CREATE VIRTUAL TABLE fails
    // and the migration completes as a no-op (the LIKE path serves search;
    // the repository probes the table before it adds the arm).

    /** SQL text of the tags column for the story whose id is $storyExpr. */
    private function tagsText(string $storyExpr): string
    {
        return "(SELECT COALESCE(group_concat(n, ' '), '') FROM (
                    SELECT t.name AS n FROM story_tags st JOIN tags t ON t.id = st.tag_id WHERE st.story_id = {$storyExpr}
                    UNION
                    SELECT syn.name FROM story_tags st JOIN tags syn ON syn.canonical_id = st.tag_id WHERE st.story_id = {$storyExpr}))";
    }

    private function reindex(string $storyIdsSql): string
    {
        return "DELETE FROM stories_trigram WHERE rowid IN ({$storyIdsSql});
                INSERT INTO stories_trigram (rowid, title, tags)
                  SELECT s.id, s.title, " . $this->tagsText('s.id') . " FROM stories s WHERE s.id IN ({$storyIdsSql});";
    }

    /** The tags text as it will be once tag old.id is gone (the same
     *  BEFORE-delete shape 067 needs, for the same cascade-order reason). */
    private function tagsTextWithout(string $storyExpr): string
    {
        return "(SELECT COALESCE(group_concat(n, ' '), '') FROM (
                    SELECT t.name AS n FROM story_tags st JOIN tags t ON t.id = st.tag_id WHERE st.story_id = {$storyExpr} AND t.id <> old.id
                    UNION
                    SELECT syn.name FROM story_tags st JOIN tags syn ON syn.canonical_id = st.tag_id WHERE st.story_id = {$storyExpr} AND syn.canonical_id <> old.id))";
    }

    private function reindexWithout(string $storyIdsSql): string
    {
        return "DELETE FROM stories_trigram WHERE rowid IN ({$storyIdsSql});
                INSERT INTO stories_trigram (rowid, title, tags)
                  SELECT s.id, s.title, " . $this->tagsTextWithout('s.id') . " FROM stories s WHERE s.id IN ({$storyIdsSql});";
    }

    private function present(Kip\Database $db): bool
    {
        // FTS5 present AND the trigram tokenizer usable: the probe statement
        // is the tokenizer check (a build without trigram refuses to plan it).
        try {
            $db->query("SELECT rowid FROM stories_fts WHERE stories_fts MATCH 'zzz' LIMIT 1");
        } catch (\PDOException) {
            return false; // no stories_fts at all (014 no-opped)
        }
        try {
            $db->one("CREATE VIRTUAL TABLE IF NOT EXISTS _trigram_probe USING fts5(x, tokenize = 'trigram')");
            $ok = $db->one("SELECT 1 FROM sqlite_master WHERE name = '_trigram_probe'") !== null;
            $db->query('DROP TABLE IF EXISTS _trigram_probe');
            return $ok;
        } catch (\PDOException) {
            $db->query('DROP TABLE IF EXISTS _trigram_probe');
            return false;
        }
    }

    public function up(Kip\Database $db): void
    {
        if (!$this->present($db)) {
            return;
        }
        foreach (['stories_tri_ins', 'stories_tri_upd'] as $t) {
            $db->query("DROP TRIGGER IF EXISTS {$t}");
        }
        $db->query('DROP TABLE IF EXISTS stories_trigram');
        $db->query("CREATE VIRTUAL TABLE stories_trigram USING fts5(title, tags, tokenize = 'trigram')");
        $db->query('INSERT INTO stories_trigram (rowid, title, tags) SELECT s.id, s.title, ' . $this->tagsText('s.id') . ' FROM stories s');
        $db->query('CREATE TRIGGER stories_tri_ins AFTER INSERT ON stories BEGIN
            INSERT INTO stories_trigram (rowid, title, tags) VALUES (new.id, new.title, ' . $this->tagsText('new.id') . '); END');
        $db->query('CREATE TRIGGER stories_tri_upd AFTER UPDATE OF title, summary ON stories BEGIN
            DELETE FROM stories_trigram WHERE rowid = old.id;
            INSERT INTO stories_trigram (rowid, title, tags) VALUES (new.id, new.title, ' . $this->tagsText('new.id') . '); END');
        $db->query('CREATE TRIGGER stories_tri_del AFTER DELETE ON stories BEGIN
            DELETE FROM stories_trigram WHERE rowid = old.id; END');
        $db->query('CREATE TRIGGER stories_tri_tag_ins AFTER INSERT ON story_tags BEGIN ' . $this->reindex('new.story_id') . ' END');
        $db->query('CREATE TRIGGER stories_tri_tag_del AFTER DELETE ON story_tags BEGIN ' . $this->reindex('old.story_id') . ' END');
        $affected = 'SELECT story_id FROM story_tags WHERE tag_id IN (old.id, new.id, old.canonical_id, new.canonical_id)';
        $db->query('CREATE TRIGGER stories_tri_tags_upd AFTER UPDATE OF name, canonical_id ON tags BEGIN ' . $this->reindex($affected) . ' END');
        $db->query('CREATE TRIGGER stories_tri_tags_ins AFTER INSERT ON tags BEGIN '
            . $this->reindex('SELECT story_id FROM story_tags WHERE tag_id = new.canonical_id') . ' END');
        $db->query('CREATE TRIGGER stories_tri_tags_del BEFORE DELETE ON tags BEGIN '
            . $this->reindexWithout('SELECT story_id FROM story_tags WHERE tag_id = old.id') . ' END');
    }

    public function down(Kip\Database $db): void
    {
        if ($db->one("SELECT 1 FROM sqlite_master WHERE name = 'stories_trigram'") === null) {
            return;
        }
        foreach (['stories_tri_ins', 'stories_tri_upd', 'stories_tri_del', 'stories_tri_tag_ins', 'stories_tri_tag_del', 'stories_tri_tags_ins', 'stories_tri_tags_upd', 'stories_tri_tags_del'] as $t) {
            $db->query("DROP TRIGGER IF EXISTS {$t}");
        }
        $db->query('DROP TABLE IF EXISTS stories_trigram');
    }
};
