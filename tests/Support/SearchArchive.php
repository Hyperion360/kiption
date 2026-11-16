<?php
declare(strict_types=1);
namespace App\Tests\Support;

use Kip\Database;

/** Synthetic stories and taxonomy for discovery tests. */
final class SearchArchive
{
    private int $next = 0;

    public function __construct(public Database $db, public int $author, public int $rating) {}

    public function clear(): void
    {
        $this->db->query('DELETE FROM story_tags');
        $this->db->query('DELETE FROM story_categories');
        $this->db->query('DELETE FROM stories');
    }

    public function category(string $slug): int
    {
        $row = $this->db->one('SELECT id FROM categories WHERE slug = ?', [$slug]);
        if ($row) return (int) $row['id'];
        $this->db->query('INSERT INTO categories (name, slug) VALUES (?, ?)', [ucfirst($slug), $slug]);
        return (int) $this->db->lastInsertId();
    }

    public function tag(string $name, string $type = 'Search Fixture', ?int $canonical = null): int
    {
        $this->db->query('INSERT OR IGNORE INTO tag_types (name) VALUES (?)', [$type]);
        $typeId = (int) $this->db->one('SELECT id FROM tag_types WHERE name = ?', [$type])['id'];
        $row = $this->db->one('SELECT id FROM tags WHERE tag_type_id = ? AND name = ?', [$typeId, $name]);
        if ($row) return (int) $row['id'];
        $this->db->query('INSERT INTO tags (tag_type_id, name, canonical_id) VALUES (?, ?, ?)', [$typeId, $name, $canonical]);
        return (int) $this->db->lastInsertId();
    }

    public function story(string $title, array $options = []): int
    {
        $options += [
            'slug' => 'fixture-' . (++$this->next), 'summary' => 'A synthetic archive story.', 'author' => $this->author,
            'completed' => 0, 'language' => 'en', 'words' => 1000, 'validated' => 1, 'restricted' => 0,
            'deleted' => null, 'locked' => null, 'created' => '2026-01-01T00:00:00Z', 'updated' => '2026-01-01T00:00:00Z',
            'tags' => [], 'categories' => [],
        ];
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, completed, validated, word_count,
            language, is_restricted, deleted_at, access_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$title, $options['slug'], $options['summary'], $options['author'], $this->rating, $options['completed'], $options['validated'],
                $options['words'], $options['language'], $options['restricted'], $options['deleted'], $options['locked'],
                $options['created'], $options['updated']]);
        $id = (int) $this->db->lastInsertId();
        foreach ($options['tags'] as $tag) $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$id, $tag]);
        foreach ($options['categories'] as $category) $this->db->query('INSERT INTO story_categories (story_id, category_id) VALUES (?, ?)', [$id, $category]);
        return $id;
    }
}
