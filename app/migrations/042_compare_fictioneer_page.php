<?php // app/migrations/042_compare_fictioneer_page.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The /compare/fictioneer marketing row (the 036/037 pattern): a
        // migration is history, so the copy is frozen here; Seeder wording
        // does not track it. OR IGNORE: an operator's own page is never
        // overwritten. Every claim in the body is sourced from the
        // 2026-10-21 research pass (see the plan's research foundation).
        $db->query("INSERT OR IGNORE INTO pages (slug, title, body) VALUES ('compare-fictioneer', 'Kiption vs Fictioneer', ?)",
            ['Fictioneer is a WordPress theme for publishing fiction. Kiption is a
standalone fiction archive: one SQLite file, no plugins, no build step.
Both are open source and free. Here is the honest comparison.

**Setup and upkeep.** Fictioneer needs WordPress, MySQL, and a careful
plugin stack; its own docs call it hard for newcomers and warn that
popular caching plugins break it. Because it ships outside the official
directory, every update is a manual theme upload. Kiption installs as
one directory, and updates are versioned releases you can stage and
roll back.

**Getting your archive in.** Fictioneer has no export tool. Its
migration guide asks you to paste PHP into functions.php, warns you to
delete the script afterward because leaving it deployed is a security
hole, and gives no path in from other software at all. Kiption imports
the standard WordPress export file with one command: stories, chapters,
author notes, genres, fandoms, characters, comments, collections, and
recommendations come across. A dry-run report counts everything first,
and a database snapshot is taken before any of it commits. Old /story/
and /chapter/ links keep working through a 301 map, so your search
rankings move with you.

**Reader engagement.** Fictioneer refuses ratings and review scores on
purpose; three separate request threads were closed wontfix. Kiption
ships zero-to-ten reviews with author responses, kudos, favorites,
follows with email digests, and reading lists. Reader email
notifications, which Fictioneer declines to build, are built in.

**Counts you can trust.** Fictioneer caches hard: its author index can
run 24 hours stale, and its follow counters drift, a bug its author
tracks but does not plan to fix. Kiption renders every page with at
most one database query and keeps the numbers exact.

**Your data stays yours.** Chapters are markdown at rest, not WordPress
HTML. Any work downloads as EPUB or HTML whenever a reader or the
author wants it. Nothing about leaving Kiption needs a plugin.

Minimums, not adjectives: Kiption needs PHP 8.3 and a SQLite file.
Fictioneer needs at least WordPress 6.5, PHP 7.4, and MySQL, plus the
hosting headroom its own FAQ says large archives require.']);
    }
    public function down(Kip\Database $db): void
    {
        // Only lifts a row this migration itself could have inserted.
        $db->query("DELETE FROM pages WHERE slug = 'compare-fictioneer' AND body LIKE '%counts everything first%'");
    }
};
