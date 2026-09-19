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
        if ($force) {
            foreach (['reviews', 'favorites', 'page_stats', 'chapters', 'story_characters', 'story_tags',
                      'story_categories', 'coauthors', 'series_items', 'stories', 'characters', 'tags',
                      'tag_types', 'categories', 'ratings'] as $t) {
                $db->query("DELETE FROM {$t}");
            }
            $db->query('DELETE FROM users WHERE email = ?', ['demo@example.test']); // only OUR demo row
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
        $db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$tagTypeId, 'Adventure']);
        $db->query('INSERT INTO categories (name, slug, description) VALUES (?, ?, ?)',
            ['General', 'general', 'Stories that fit nowhere finer.']);
        $db->query('INSERT INTO users (email, password_hash, penname, role, created_at) VALUES (?, ?, ?, ?, ?)',
            ['demo@example.test', password_hash('password123', PASSWORD_DEFAULT), 'Demo Author', 'validated_author',
             '2026-01-01T00:00:00Z']);
        $authorId = (int) $db->lastInsertId();
        $teenId = (int) $db->one('SELECT id FROM ratings WHERE label = ?', ['Teen'])['id'];
        $explicitId = (int) $db->one('SELECT id FROM ratings WHERE label = ?', ['Explicit'])['id'];
        $categoryId = (int) $db->one('SELECT id FROM categories WHERE slug = ?', ['general'])['id'];
        // Story 1: teen WIP, 3 chapters.
        $db->query('INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, validated, completed, word_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, 600, ?, ?)',
            ['The Rabbit Hole', 'the-rabbit-hole', 'A slow fall into a stranger world.', 'Thanks for reading.', $authorId, $teenId,
             '2026-08-01T09:00:00Z', '2026-09-10T09:00:00Z']);
        $story1 = (int) $db->lastInsertId();
        foreach ([[1, 'Down', '<p>Falling <em>down</em> the hole, past shelves of nothing.</p>', 100],
                  [2, 'Through', '<p>Through the little door, the garden is wrong.</p>', 200],
                  [3, 'Up', '<p>Climbing back is its own kind of falling.</p>', 300]] as [$pos, $title, $body, $words]) {
            $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (' . $story1 . ', ?, ?, ?, 1, ?)',
                [$pos, $title, $body, $words]);
        }
        $db->query('INSERT INTO story_categories (story_id, category_id) VALUES (' . $story1 . ', ' . $categoryId . ')');
        // Story 2: explicit complete, 1 chapter (exercises the age gate).
        $db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, 1, 100, ?, ?)',
            ['After Hours', 'after-hours', 'What the warning is for.', $authorId, $explicitId, '2026-09-01T09:00:00Z', '2026-09-12T09:00:00Z']);
        $story2 = (int) $db->lastInsertId();
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (' . $story2 . ', ?, ?, ?, 1, ?)',
            [1, 'One', '<p>Body.</p>', 100]);
        $db->query('INSERT INTO story_categories (story_id, category_id) VALUES (' . $story2 . ', ' . $categoryId . ')');
    }
}
