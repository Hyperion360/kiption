<?php
namespace App\Tests;

use App\Repositories\BrowseQuery;
use App\Repositories\DiscoveryFilter;
use PDO;
use PHPUnit\Framework\TestCase;

final class DiscoveryFilterTest extends TestCase
{
    private PDO $db;
    private const FILTERS = ['category' => ['fiction', 'poetry'], 'rating_id' => 1, 'completed' => true,
        'language' => 'en', 'wmin' => 100, 'wmax' => 1000, 'tag' => [1], 'xtag' => [4],
        'tags' => 'Adventure', 'xtags' => 'Gore', 'character' => 1];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE stories (id INTEGER PRIMARY KEY, rating_id INTEGER, completed INTEGER, language TEXT, word_count INTEGER);
            CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT);
            CREATE TABLE story_categories (story_id INTEGER, category_id INTEGER);
            CREATE TABLE story_characters (story_id INTEGER, character_id INTEGER);
            CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT, canonical_id INTEGER);
            CREATE TABLE story_tags (story_id INTEGER, tag_id INTEGER);
            INSERT INTO categories VALUES (1, \'fiction\'), (2, \'poetry\'), (3, \'other\');
            INSERT INTO tags VALUES (1, \'Fantasy\', NULL), (2, \'Adventure\', NULL), (3, \'Gore\', NULL), (4, \'Angst\', NULL)');
        for ($id = 1; $id <= 10; $id++) {
            $this->db->prepare('INSERT INTO stories VALUES (?, ?, ?, ?, ?)')->execute([
                $id, $id === 3 ? 2 : 1, $id === 4 ? 0 : 1, $id === 5 ? 'fr' : 'en',
                $id === 7 ? 10 : ($id === 8 ? 2000 : 500),
            ]);
            $this->db->prepare('INSERT INTO story_categories VALUES (?, ?)')->execute([$id, $id === 2 ? 3 : ($id % 2 + 1)]);
            $this->db->prepare('INSERT INTO story_characters VALUES (?, ?)')->execute([$id, $id === 9 ? 2 : 1]);
            $this->db->prepare('INSERT INTO story_tags VALUES (?, 1), (?, 2)')->execute([$id, $id]);
        }
        $this->db->exec('INSERT INTO story_tags VALUES (6, 3), (10, 4)');
    }

    private function ids(array $filters, string $omit = ''): array
    {
        [$sql, $binds] = DiscoveryFilter::sql($filters, $omit);
        $statement = $this->db->prepare('SELECT s.id FROM stories s WHERE 1=1' . $sql . ' ORDER BY s.id');
        $statement->execute($binds);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function test_each_dimension_omits_only_its_own_constraint(): void
    {
        $this->assertSame([1], $this->ids(self::FILTERS));
        foreach (['category' => [1, 2], 'rating' => [1, 3], 'status' => [1, 4],
            'language' => [1, 5], 'exclusions' => [1, 6, 10], 'unknown' => [1]] as $dimension => $expected) {
            $this->assertSame($expected, $this->ids(self::FILTERS, $dimension), $dimension);
        }
        $this->db->exec('DELETE FROM story_tags WHERE story_id = 6 AND tag_id = 1;
            DELETE FROM story_tags WHERE story_id = 10 AND tag_id = 2');
        $this->assertSame([1], $this->ids(self::FILTERS, 'exclusions'), 'Both include forms still constrain omitted exclusions');
    }

    public function test_empty_and_invalid_tag_category_inputs_are_ignored(): void
    {
        $this->assertSame(['', []], DiscoveryFilter::sql([]));
        $this->assertSame(['', []], DiscoveryFilter::sql(['category' => [['fiction'], 'bad!'], 'completed' => null,
            'tag' => [['1'], -1, 'bad'], 'xtag' => [-1], 'tags' => ['Adventure'], 'xtags' => ['Gore']]));
    }

    public function test_bind_order_follows_categories_scalars_ranges_tags_then_character(): void
    {
        [$sql, $binds] = DiscoveryFilter::sql(self::FILTERS);
        $this->assertSame(['fiction', 'poetry', 1, 1, 'en', 100, 1000, 1, 4, 'Adventure', 'Gore', 1], $binds);
        $this->assertSame(count($binds), substr_count($sql, '?'));
    }

    public function test_browse_mapping_preserves_status_categories_word_bounds_and_legacy_tags(): void
    {
        $query = ['cat' => ['fiction', 'poetry'], 'language' => 'en', 'wmin' => '100', 'wmax' => '1000',
            'tag' => [1], 'xtag' => [4], 'tags' => 'Adventure', 'xtags' => 'Gore'];
        foreach (['any' => [1, 3, 4, 9], 'complete' => [1, 3, 9], 'wip' => [4]] as $status => $expected) {
            $filters = DiscoveryFilter::fromBrowse(BrowseQuery::fromQuery($query + ['status' => $status]));
            $this->assertSame($expected, $this->ids($filters), $status);
            $this->assertSame(['fiction', 'poetry'], $filters['category']);
            $this->assertSame('Adventure', $filters['tags']);
            $this->assertSame('Gore', $filters['xtags']);
        }
    }
}
