<?php // app/Features/Characters/migrations/050_characters_any_category.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // A character may now belong to "any category" (category_id NULL), the
        // eFiction model. SQLite cannot drop NOT NULL in place, so the table is
        // rebuilt. DROP TABLE under PRAGMA foreign_keys=ON cascades the delete
        // into story_characters, so those rows are parked in a scratch table
        // and put back afterwards.
        $db->query('CREATE TABLE story_characters_keep AS SELECT story_id, character_id FROM story_characters');
        $db->query('CREATE TABLE characters_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER REFERENCES categories (id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT \'\'
        )');
        $db->query('INSERT INTO characters_new (id, category_id, name, slug, description)
                    SELECT id, category_id, name, slug, description FROM characters');
        $db->query('DROP TABLE characters');
        $db->query('ALTER TABLE characters_new RENAME TO characters');
        $db->query('CREATE INDEX idx_characters_category ON characters (category_id)');
        $db->query('INSERT INTO story_characters (story_id, character_id) SELECT story_id, character_id FROM story_characters_keep');
        $db->query('DROP TABLE story_characters_keep');
    }
    public function down(Kip\Database $db): void
    {
        // Characters with no category cannot fit the old NOT NULL column.
        $db->query('DELETE FROM characters WHERE category_id IS NULL');
    }
};
