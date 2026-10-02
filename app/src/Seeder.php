<?php // app/src/Seeder.php
namespace App;
use Kip\Database;

final class Seeder
{
    public static function run(Database $db, bool $force = false): void
    {
        $existing = (int) $db->one('SELECT COUNT(*) c FROM stories')['c'];
        if ($existing > 0 && !$force) {
            throw new \RuntimeException("Refusing to seed: database already contains stories (use --force).");
        }
        $db->begin();
        try {
        if ($force) {
            foreach (['reviews', 'favorites', 'page_stats', 'chapters', 'story_characters', 'story_tags',
                      'story_categories', 'coauthors', 'challenge_items', 'challenge_prompts', 'challenges',
                      'series', 'series_items', 'stories', 'characters', 'tags',
                      'tag_types', 'categories', 'ratings', 'news'] as $t) { // news last: its comments cascade (FK on, finding 12)
                $db->query("DELETE FROM {$t}");
            }
            $db->query('DELETE FROM users WHERE email IN (?, ?)', ['demo@example.test', 'beta@example.test']); // only OUR fixture rows
        } else {
            // fresh install: taxonomy rows may not exist yet either way; make seeding idempotent for them
            foreach (['ratings', 'tag_types', 'tags', 'categories', 'characters'] as $t) {
                $db->query("DELETE FROM {$t}");
            }
        }
        foreach ([['General', 0, '', 1], ['Teen', 0, '', 2], ['Mature', 1, 'Contains adult content.', 3],
                  ['Explicit', 1, 'Contains explicit adult content.', 4]] as [$label, $adult, $warn, $pos]) {
            $db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, ?, ?, ?)',
                [$label, $adult, $warn, $pos]);
        }
        $db->query('INSERT INTO tag_types (name) VALUES (?)', ['genre']);
        $tagTypeId = (int) $db->lastInsertId();
        $db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$tagTypeId, 'Fantasy']);
        $fantasyTagId = (int) $db->lastInsertId();
        $db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$tagTypeId, 'Adventure']);
        $db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$tagTypeId, 'Found family']);
        $foundFamilyTagId = (int) $db->lastInsertId();
        $db->query('INSERT INTO tag_types (name) VALUES (?)', ['content']);
        $contentTypeId = (int) $db->lastInsertId();
        $db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$contentTypeId, 'Coming of age']);
        $comingOfAgeTagId = (int) $db->lastInsertId();
        $db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$contentTypeId, 'Maritime']);
        $maritimeTagId = (int) $db->lastInsertId();
        $db->query('INSERT INTO categories (name, slug, description) VALUES (?, ?, ?)',
            ['General', 'general', 'Stories that fit nowhere finer.']);
        $db->query('INSERT INTO users (email, password_hash, penname, role, email_verified_at, approved_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['demo@example.test', password_hash('password123', PASSWORD_DEFAULT), 'Demo Author', 'validated_author',
             '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']);
        $authorId = (int) $db->lastInsertId();
        (new \App\Repositories\UserRepository($db))->backfillProfileSlug($authorId);
        $db->query("INSERT INTO users (email, password_hash, penname, is_beta, email_verified_at, approved_at, profile_slug) VALUES ('beta@example.test', ?, 'betafriend', 1, ?, ?, 'betafriend')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $teenId = (int) $db->one('SELECT id FROM ratings WHERE label = ?', ['Teen'])['id'];
        $explicitId = (int) $db->one('SELECT id FROM ratings WHERE label = ?', ['Explicit'])['id'];
        $categoryId = (int) $db->one('SELECT id FROM categories WHERE slug = ?', ['general'])['id'];
        // Story 1: teen WIP, 3 chapters.
        $db->query('INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, validated, completed, word_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, 600, ?, ?)',
            ['The Rabbit Hole', 'the-rabbit-hole', 'A slow fall into a stranger world.', 'Thanks for reading.', $authorId, $teenId,
             '2026-08-01T09:00:00Z', '2026-09-10T09:00:00Z']);
        $story1 = (int) $db->lastInsertId();
        foreach ([[1, 'Down', "Falling *down* the hole, past shelves of nothing.", 100],
                  [2, 'Through', 'Through the little door, the garden is wrong.', 200],
                  [3, 'Up', 'Climbing back is its own kind of falling.', 300]] as [$pos, $title, $body, $words]) {
            $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (' . $story1 . ', ?, ?, ?, 1, ?)',
                [$pos, $title, $body, $words]);
        }
        $db->query('INSERT INTO story_categories (story_id, category_id) VALUES (' . $story1 . ', ' . $categoryId . ')');
        // The demo story carries the chip row the comps draw (M1/D3): four
        // tags across two types. Warnings stay a rating concern; the ratings
        // row's empty warning_text renders the "No major warnings" line.
        foreach ([$fantasyTagId, $foundFamilyTagId, $comingOfAgeTagId, $maritimeTagId] as $tagId) {
            $db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (' . $story1 . ', ' . $tagId . ')');
        }
        // Story 2: explicit complete, 1 chapter (exercises the age gate).
        $db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, 1, 100, ?, ?)',
            ['After Hours', 'after-hours', 'What the warning is for.', $authorId, $explicitId, '2026-09-01T09:00:00Z', '2026-09-12T09:00:00Z']);
        $story2 = (int) $db->lastInsertId();
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (' . $story2 . ', ?, ?, ?, 1, ?)',
            [1, 'One', 'Body.', 100]);
        $db->query('INSERT INTO story_categories (story_id, category_id) VALUES (' . $story2 . ', ' . $categoryId . ')');
        $db->query("INSERT INTO series (title, slug, summary, owner_id, membership) VALUES ('Down the Rabbit Hole', 'down-the-rabbit-hole', 'The complete descent, chapter by chapter.', (SELECT id FROM users WHERE penname = 'Demo Author'), 'open')");
        $db->query("INSERT INTO series_items (series_id, story_id, position, confirmed) VALUES ((SELECT id FROM series WHERE slug = 'down-the-rabbit-hole'), (SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), 1, 1)");
        // The Community Challenge rides every seed (QueryBudget pins its index
        // and view pages): open membership, one prompt, NO items, so the
        // Builder's gated enumeration never joins its view page (finding 10).
        $db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership) VALUES ('Community Challenge', 'community-challenge', 'A seasonal prompt, anyone may join.', (SELECT id FROM users WHERE penname = 'Demo Author'), 'open')");
        $db->query("INSERT INTO challenge_prompts (challenge_id, position, prompt_text) VALUES ((SELECT id FROM challenges WHERE slug = 'community-challenge'), 1, 'A story that opens with a door.')");
        // The about page rides every seed (QueryBudget/Builder want a real row):
        // OR IGNORE on the slug PK because force mode does NOT clear pages, so
        // an operator's or the import rider's pages survive a reseed untouched.
        $db->query("INSERT OR IGNORE INTO pages (slug, title, body) VALUES ('about', 'About', 'This archive is *new*.')");
        // The Welcome post rides every seed too (news renders need a real row):
        // force mode cleared news above, so the plain insert cannot duplicate.
        $db->query("INSERT INTO news (title, body) VALUES ('Welcome', 'First post.')");
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $db->commit();
    }
}
