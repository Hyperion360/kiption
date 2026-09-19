<?php // app/migrations/006_legacy_content_to_markdown.php
return new class extends Kip\Migrations\Migration {
    /** Content written before the 2026-09-19 markdown-at-rest decision was
     *  stored as the old renderer's HTML; App\Markdown escapes it, so those
     *  rows would display as literal markup. This converts the exact
     *  vocabulary this app itself ever wrote (a <p> wrapper plus em/strong);
     *  anything else starting with '<' is left untouched and stays escaped,
     *  same as any other unstoreable HTML. External-archive imports get their
     *  own converter on the way in (importer phase). */
    private function toMarkdown(string $v): string
    {
        $v = trim($v);
        if (!str_starts_with($v, '<')) return $v; // already markdown / plain
        $v = preg_replace('/^<p>\s*/', '', $v) ?? $v;
        $v = preg_replace('/\s*<\/p>$/', '', $v) ?? $v;
        return str_replace(['<em>', '</em>', '<strong>', '</strong>'], ['*', '*', '**', '**'], $v);
    }

    public function up(Kip\Database $db): void
    {
        // The Migrator already wraps each migration in one transaction.
        foreach ($db->all("SELECT id, content, notes_before, notes_after FROM chapters
                           WHERE content LIKE '<%' OR notes_before LIKE '<%' OR notes_after LIKE '<%'") as $ch) {
            $db->query('UPDATE chapters SET content = ?, notes_before = ?, notes_after = ? WHERE id = ?',
                [$this->toMarkdown((string) $ch['content']),
                 $this->toMarkdown((string) $ch['notes_before']),
                 $this->toMarkdown((string) $ch['notes_after']),
                 $ch['id']]);
        }
        foreach ($db->all("SELECT id, notes FROM stories WHERE notes LIKE '<%'") as $s) {
            $db->query('UPDATE stories SET notes = ? WHERE id = ?',
                [$this->toMarkdown((string) $s['notes']), $s['id']]);
        }
    }
    public function down(Kip\Database $db): void
    {
        // Data conversions are one-way; rolling the schema back does not
        // un-convert prose. No-op by design.
    }
};
