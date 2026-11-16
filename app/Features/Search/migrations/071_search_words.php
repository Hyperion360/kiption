<?php
declare(strict_types=1);

return new class extends Kip\Migrations\Migration {
    /** Internal SQL expressions only; values never come from a request. */
    private function tagsText(string $story, bool $withoutOld = false): string
    {
        $direct = $withoutOld ? ' AND t.id <> old.id' : '';
        $synonym = $withoutOld ? ' AND syn.id <> old.id AND syn.canonical_id <> old.id' : '';
        return "(SELECT COALESCE(group_concat(n, ' '), '') FROM (
            SELECT t.name n FROM story_tags st JOIN tags t ON t.id = st.tag_id
                WHERE st.story_id = {$story}{$direct}
            UNION SELECT syn.name FROM story_tags st JOIN tags syn ON syn.canonical_id = st.tag_id
                WHERE st.story_id = {$story}{$synonym}))";
    }

    private function rebuild(string $ids, bool $withoutOld = false): string
    {
        // The FTS source is scratch space inside the current write transaction.
        // Tokenize only affected stories, then leave it empty before returning.
        return "DELETE FROM search_word_posts WHERE story_id IN ({$ids});
            DELETE FROM search_word_source;
            INSERT INTO search_word_source(rowid, title, tags)
                SELECT s.id, s.title, " . $this->tagsText('s.id', $withoutOld) . " FROM stories s WHERE s.id IN ({$ids});
            INSERT OR IGNORE INTO search_words(word)
                SELECT term FROM search_word_vocab WHERE length(term) <= 65;
            INSERT OR IGNORE INTO search_word_posts(word, story_id, field)
                SELECT term, doc, col FROM search_word_vocab WHERE length(term) <= 65;
            DELETE FROM search_word_source;";
    }

    private function repairRetainedIndexes(Kip\Database $db): void
    {
        foreach (['stories_fts' => 'stories_fts_tags_del', 'stories_trigram' => 'stories_tri_tags_del'] as $table => $trigger) {
            if (!$db->one("SELECT 1 FROM sqlite_master WHERE name = ? AND sql LIKE '%fts5%'", [$table])) continue;
            $columns = $table === 'stories_fts' ? 'title, summary, tags' : 'title, tags';
            $values = $table === 'stories_fts' ? 's.title, s.summary, ' : 's.title, ';
            $ids = 'SELECT story_id FROM story_tags WHERE tag_id IN (old.id, old.canonical_id)';
            $db->query('DROP TRIGGER IF EXISTS ' . $trigger);
            $db->query('CREATE TRIGGER ' . $trigger . ' BEFORE DELETE ON tags BEGIN DELETE FROM ' . $table . ' WHERE rowid IN (' . $ids . '); '
                . 'INSERT INTO ' . $table . '(rowid, ' . $columns . ') SELECT s.id, ' . $values . $this->tagsText('s.id', true)
                . ' FROM stories s WHERE s.id IN (' . $ids . '); END');
            // Correct entries left stale by an already-deleted synonym too.
            $db->query('DELETE FROM ' . $table);
            $db->query('INSERT INTO ' . $table . '(rowid, ' . $columns . ') SELECT s.id, ' . $values . $this->tagsText('s.id') . ' FROM stories s');
        }
    }

    public function up(Kip\Database $db): void
    {
        if (!$db->one("SELECT 1 FROM sqlite_master WHERE name = 'stories_fts' AND sql LIKE '%fts5%'")) return;
        $this->repairRetainedIndexes($db);
        $db->exec(<<<'SQL'
CREATE VIRTUAL TABLE search_word_source USING fts5(title, tags, tokenize='unicode61 remove_diacritics 0');
CREATE VIRTUAL TABLE search_word_vocab USING fts5vocab(search_word_source, 'instance');
CREATE TABLE search_words (word TEXT PRIMARY KEY) WITHOUT ROWID;
CREATE TABLE search_word_posts (
    word TEXT NOT NULL REFERENCES search_words(word) ON DELETE CASCADE,
    story_id INTEGER NOT NULL REFERENCES stories(id) ON DELETE CASCADE,
    field TEXT NOT NULL CHECK(field IN ('title', 'tags')),
    PRIMARY KEY(word, story_id, field)
) WITHOUT ROWID;
CREATE INDEX idx_search_word_posts_story ON search_word_posts(story_id, word, field);
CREATE TABLE search_deletes (
    key TEXT NOT NULL,
    position INTEGER NOT NULL,
    word TEXT NOT NULL REFERENCES search_words(word) ON DELETE CASCADE,
    PRIMARY KEY(key, position, word)
) WITHOUT ROWID;
CREATE INDEX idx_search_deletes_word ON search_deletes(word);
CREATE TRIGGER search_word_added AFTER INSERT ON search_words BEGIN
    INSERT OR IGNORE INTO search_deletes(key, position, word)
    SELECT substr(new.word, 1, n - 1) || substr(new.word, n + 1), n, new.word FROM
        (WITH RECURSIVE positions(n) AS
            (SELECT 1 UNION ALL SELECT n + 1 FROM positions WHERE n < length(new.word))
         SELECT n FROM positions)
    WHERE length(new.word) BETWEEN 3 AND 65;
END;
CREATE TRIGGER search_post_removed AFTER DELETE ON search_word_posts BEGIN
    DELETE FROM search_words WHERE word = old.word
        AND NOT EXISTS (SELECT 1 FROM search_word_posts WHERE word = old.word);
END;
SQL);
        $triggers = [
            'search_story_added' => 'AFTER INSERT ON stories BEGIN ' . $this->rebuild('new.id') . ' END',
            'search_story_changed' => 'AFTER UPDATE OF title ON stories BEGIN ' . $this->rebuild('new.id') . ' END',
            'search_story_removed' => 'AFTER DELETE ON stories BEGIN DELETE FROM search_word_source WHERE rowid = old.id; END',
            'search_tag_attached' => 'AFTER INSERT ON story_tags BEGIN ' . $this->rebuild('new.story_id') . ' END',
            'search_tag_detached' => 'AFTER DELETE ON story_tags BEGIN ' . $this->rebuild('old.story_id') . ' END',
            'search_tag_changed' => 'AFTER UPDATE OF name, canonical_id ON tags BEGIN '
                . $this->rebuild('SELECT story_id FROM story_tags WHERE tag_id IN (old.id, new.id, old.canonical_id, new.canonical_id)') . ' END',
            'search_synonym_added' => 'AFTER INSERT ON tags BEGIN '
                . $this->rebuild('SELECT story_id FROM story_tags WHERE tag_id = new.canonical_id') . ' END',
            'search_tag_removed' => 'BEFORE DELETE ON tags BEGIN '
                . $this->rebuild('SELECT story_id FROM story_tags WHERE tag_id IN (old.id, old.canonical_id)', true) . ' END',
        ];
        foreach ($triggers as $name => $body) $db->query('CREATE TRIGGER ' . $name . ' ' . $body);
        $db->exec($this->rebuild('SELECT id FROM stories'));
    }

    public function down(Kip\Database $db): void
    {
        foreach (['search_story_added', 'search_story_changed', 'search_story_removed', 'search_tag_attached',
            'search_tag_detached', 'search_tag_changed', 'search_synonym_added', 'search_tag_removed',
            'search_word_added', 'search_post_removed'] as $name) $db->query('DROP TRIGGER IF EXISTS ' . $name);
        foreach (['search_deletes', 'search_word_posts', 'search_words', 'search_word_vocab', 'search_word_source'] as $name) {
            $db->query('DROP TABLE IF EXISTS ' . $name);
        }
        // The repaired retained-index triggers remain correct for old application code.
    }
};
