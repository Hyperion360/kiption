<?php // app/migrations/037_epub_imports.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // One purpose: the EPUB importer's ledger. sha256 is the PK (one
        // row per imported file, so a re-run of the same EPUB is recognized
        // instead of duplicated); story_id cascades with its story so
        // deleting a story lifts its ledger entry; imported_at records the
        // first import. Reversible: down() drops it.
        $db->query('CREATE TABLE epub_imports (sha256 TEXT PRIMARY KEY, story_id INTEGER NOT NULL REFERENCES stories(id) ON DELETE CASCADE, imported_at TEXT NOT NULL)');
        // house standard: every FK column carries the index its lookups use
        // (story deletes cascade through it; story pages list their imports)
        $db->query('CREATE INDEX idx_epub_imports_story ON epub_imports (story_id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE epub_imports');
    }
};
