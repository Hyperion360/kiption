<?php
namespace App\Tests;

use App\Repositories\TagFilter;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TagFilterMembershipTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE stories (id INTEGER PRIMARY KEY);
            CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT, canonical_id INTEGER);
            CREATE TABLE story_tags (story_id INTEGER, tag_id INTEGER);
            CREATE INDEX idx_story_tags_rev ON story_tags(tag_id, story_id);
            CREATE INDEX idx_tags_name ON tags(name COLLATE NOCASE);
            CREATE INDEX idx_tags_canonical ON tags(canonical_id);
            INSERT INTO stories VALUES (1), (2), (3), (4), (5);
            INSERT INTO tags VALUES (1, \'Fantasy\', NULL), (2, \'Space\', 1),
                (3, \'Adventure\', NULL), (4, \'Gore\', NULL), (5, \'Courage\', NULL), (6, \'Fantasy\', NULL);
            INSERT INTO story_tags VALUES (1, 1), (1, 2), (1, 3), (2, 2), (3, 3), (3, 4), (5, 6), (NULL, 4)');
    }

    #[DataProvider('cases')]
    public function test_membership_matches_correlated_results(array $query, array $expected): void
    {
        $filter = TagFilter::fromQuery($query);
        $results = [];
        $bindings = [];
        foreach ([false, true] as $membership) {
            $binds = [];
            $sql = $filter->sql($binds, 'st', $membership);
            $statement = $this->db->prepare('SELECT st.id FROM stories st WHERE 1=1' . $sql . ' ORDER BY st.id');
            $statement->execute($binds);
            $results[] = $statement->fetchAll(PDO::FETCH_COLUMN);
            $bindings[] = $binds;
        }
        $this->assertSame($expected, $results[0], 'Original correlated behavior');
        $this->assertSame($results[0], $results[1], 'Membership must preserve every row and its multiplicity');
        $this->assertSame($bindings[0], $bindings[1], 'Bind order is unchanged');
    }

    public static function cases(): array
    {
        return [
            'empty' => [[], [1, 2, 3, 4, 5]],
            'canonical ID' => [['tag' => [1]], [1, 2]],
            'alias ID' => [['tag' => [2]], [1, 2]],
            'canonical and alias attachments do not duplicate rows' => [['tag' => [1, 2]], [1, 2]],
            'include is AND' => [['tag' => [1, 3]], [1]],
            'exclude canonical with null attachment' => [['xtag' => [1, 4]], [4, 5]],
            'exclude alias' => [['xtag' => [2]], [3, 4, 5]],
            'unknown include ID' => [['tag' => [999]], []],
            'unknown exclude ID' => [['xtag' => [999]], [1, 2, 3, 4, 5]],
            'name matches all types' => [['tags' => 'fantasy'], [1, 2, 5]],
            'alias name' => [['tags' => 'SPACE'], [1, 2]],
            'names use AND' => [['tags' => 'Space, Adventure'], [1]],
            'exclude alias name' => [['xtags' => 'Space'], [3, 4, 5]],
            'exclude names with null attachment' => [['xtags' => 'Gore'], [1, 2, 4, 5]],
            'unknown include name' => [['tags' => 'Unknown'], []],
            'unknown exclude name' => [['xtags' => 'Unknown'], [1, 2, 3, 4, 5]],
            'ID overlap includes win' => [['tag' => [1], 'xtag' => [1, 4]], [1, 2]],
            'name overlap includes win' => [['tags' => 'Space', 'xtags' => 'space, Gore'], [1, 2]],
            'distinct IDs with same canonical retain old conflict' => [['tag' => [1], 'xtag' => [2]], []],
            'cross-form overlap retains old conflict' => [['tag' => [1], 'xtags' => 'Space'], []],
            'mixed constraints' => [['tag' => [1], 'xtag' => [4], 'tags' => 'Adventure', 'xtags' => 'Courage'], [1]],
            'invalid nested inputs' => [['tag' => [[1], 'bad', 0, -1, '1', 1], 'xtag' => [[4]], 'tags' => ['Adventure'], 'xtags' => ['Gore']], [1, 2]],
            'bound malicious name' => [['tags' => "x') OR 1=1 --"], []],
        ];
    }

    public function test_membership_is_explicit_uncorrelated_and_bound_in_the_original_order(): void
    {
        $filter = TagFilter::fromQuery(['tag' => [1], 'xtag' => [4], 'tags' => 'Adventure', 'xtags' => "x') OR 1=1 --"]);
        $binds = ['before'];
        $original = $filter->sql($binds, 'st');
        $this->assertStringContainsString('x.story_id = st.id', $original);
        $this->assertStringNotContainsString('+st.id IN', $original);
        $expected = ['before', 1, 4, 'Adventure', "x') OR 1=1 --"];
        $this->assertSame($expected, $binds);
        $binds = ['before'];
        $membership = $filter->sql($binds, 'st', true);
        $this->assertStringContainsString('+st.id IN(SELECT x.story_id', $membership);
        $this->assertStringContainsString('+st.id NOT IN(SELECT x.story_id', $membership);
        $this->assertStringNotContainsString('x.story_id = st.id', $membership);
        $this->assertStringNotContainsString('OR 1=1', $membership);
        $this->assertSame($expected, $binds);
    }

    public function test_each_membership_constraint_seeks_the_reverse_attachment_index(): void
    {
        $binds = [];
        $sql = TagFilter::fromQuery(['tag' => [1], 'xtag' => [4], 'tags' => 'Adventure', 'xtags' => 'Courage'])->sql($binds, 'st', true);
        $statement = $this->db->prepare('EXPLAIN QUERY PLAN SELECT st.id FROM stories st WHERE 1=1' . $sql);
        $statement->execute($binds);
        $plan = $statement->fetchAll(PDO::FETCH_ASSOC);
        $details = array_column($plan, 'detail', 'id');
        $accesses = array_values(array_filter($plan, fn (array $row): bool => preg_match('/^(?:SCAN|SEARCH) x(?: |$)/', $row['detail']) === 1));
        $this->assertCount(4, $accesses, 'One set for each ID or name inclusion and exclusion');
        foreach ($accesses as $access) {
            $this->assertStringContainsString('SEARCH x USING COVERING INDEX idx_story_tags_rev', $access['detail']);
            $this->assertStringStartsWith('LIST SUBQUERY', $details[$access['parent']] ?? '');
        }
    }
}
