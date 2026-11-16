<?php // app/Features/Tags/Tests/TagSuggestionTest.php
namespace App\Features\Tags\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** Slice 4: the tag policy setting and the write-side rules for member tag
 *  suggestions (Task 4.2). */
final class TagSuggestionTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-tagsugg-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function repo(): \App\Repositories\TagSuggestionRepository
    {
        return new \App\Repositories\TagSuggestionRepository($this->db);
    }

    private function typeId(string $name): int
    {
        return (int) $this->db->one('SELECT id FROM tag_types WHERE name = ?', [$name])['id'];
    }

    private function makeStory(): int
    {
        $this->db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated) VALUES ('Suggest Me', 'suggest-me', 1, 1, 1)");
        return (int) $this->db->lastInsertId();
    }

    public function test_normalize_rules(): void
    {
        $n = fn (string $s): array => \App\Repositories\TagSuggestionRepository::normalize($s);
        $this->assertSame(['slow burn'], $n("  slow\x00   burn "));
        $this->assertSame([], $n('a'), 'too short');
        $this->assertSame([], $n(str_repeat('x', 61)), 'too long');
        $this->assertSame([], $n('<script>alert(1)</script>'), 'angle brackets refused');
        $this->assertSame([], $n('has`tick'), 'backtick refused');
        $this->assertSame(['one', 'two'], $n('one, ONE, two'), 'case-insensitive dedupe, first spelling kept');
        $this->assertCount(5, $n('a1,b2,c3,d4,e5,f6,g7'), 'five per save');
    }

    public function test_policy_off_ignores_suggestions_entirely(): void
    {
        $story = $this->makeStory();
        $before = (int) $this->db->one('SELECT COUNT(*) c FROM tags')['c'];
        $out = $this->repo()->submit($story, 2, [$this->typeId('genre') => 'Zzyzx Marker'], 'off');
        $this->assertSame($before, (int) $this->db->one('SELECT COUNT(*) c FROM tags')['c'], 'no tag row');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM tag_suggestions')['c'], 'no suggestion row');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM story_tags WHERE story_id = ?', [$story])['c'], 'nothing attached');
        $this->assertSame([], $out['pending']);
        $this->assertSame([], $out['created']);
    }

    /** An unknown type id parks nothing and attaches nothing: the typed name
     *  is ignored entirely, not refused and not stored. */
    public function test_an_unknown_type_id_parks_and_attaches_nothing(): void
    {
        $story = $this->makeStory();
        $out = $this->repo()->submit($story, 2, [999999 => 'Zzyzx Marker'], 'suggest');
        $this->assertSame([], $out['pending'], 'nothing parked');
        $this->assertSame([], $out['refused'], 'an unknown type is ignored, not refused');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM tag_suggestions')['c']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM story_tags WHERE story_id = ?', [$story])['c'], 'nothing attached');
    }

    public function test_suggest_policy_stores_pending_and_creates_no_tag(): void
    {
        $story = $this->makeStory();
        $before = (int) $this->db->one('SELECT COUNT(*) c FROM tags')['c'];
        $out = $this->repo()->submit($story, 2, [$this->typeId('genre') => 'Zzyzx Marker'], 'suggest');
        $this->assertSame($before, (int) $this->db->one('SELECT COUNT(*) c FROM tags')['c'], 'the live tag list is untouched');
        $row = $this->db->one("SELECT * FROM tag_suggestions WHERE status = 'pending'");
        $this->assertNotNull($row);
        $this->assertSame('Zzyzx Marker', $row['name']);
        $this->assertSame(2, (int) $row['suggested_by']);
        $this->assertSame(['Zzyzx Marker'], $out['pending']);
    }

    public function test_existing_tag_name_attaches_directly_through_its_canonical(): void
    {
        $story = $this->makeStory();
        $genre = $this->typeId('genre');
        $out = $this->repo()->submit($story, 2, [$genre => 'FANTASY'], 'suggest');
        $this->assertSame(['Fantasy'], $out['attached'], 'the stored spelling rides back');
        $row = $this->db->one('SELECT tag_id FROM story_tags WHERE story_id = ?', [$story]);
        $this->assertSame('Fantasy', $this->db->one('SELECT name FROM tags WHERE id = ?', [(int) $row['tag_id']])['name']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM tag_suggestions')['c']);
        // a retired synonym's name resolves to its canonical and attaches that
        $this->db->query('INSERT INTO tags (tag_type_id, name, canonical_id) VALUES (?, ?, (SELECT id FROM tags WHERE name = ?))', [$genre, 'fam', 'Found family']);
        $out = $this->repo()->submit($story, 2, [$genre => 'fam'], 'suggest');
        $this->assertSame(['Found family'], $out['attached'], 'the synonym resolves to the canonical');
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM story_tags WHERE story_id = ?', [$story])['c']);
    }

    public function test_a_name_that_exists_in_another_type_is_refused(): void
    {
        // Owner decision: the same name in two tag types is not allowed.
        $story = $this->makeStory();
        $out = $this->repo()->submit($story, 2, [$this->typeId('genre') => 'Maritime'], 'suggest');
        $this->assertSame(['Maritime' => 'type'], $out['refused'], 'Maritime is a content tag');
        $this->assertSame([], $out['pending']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM tag_suggestions')['c']);
        // the same wall guards free-mode creation
        $out = $this->repo()->submit($story, 2, [$this->typeId('genre') => 'Maritime'], 'free');
        $this->assertSame(['Maritime' => 'type'], $out['refused']);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM tags WHERE name = 'Maritime' AND tag_type_id = (SELECT id FROM tag_types WHERE name = 'genre')")['c']);
    }

    public function test_free_policy_creates_a_live_tag_and_attaches_it(): void
    {
        $story = $this->makeStory();
        $genre = $this->typeId('genre');
        $before = (int) $this->db->one('SELECT COUNT(*) c FROM tags')['c'];
        $out = $this->repo()->submit($story, 2, [$genre => 'Zzyzx Marker'], 'free');
        $this->assertSame($before + 1, (int) $this->db->one('SELECT COUNT(*) c FROM tags')['c']);
        $row = $this->db->one("SELECT id FROM tags WHERE name = 'Zzyzx Marker'");
        $this->assertNotNull($row, 'a live tag row exists');
        $this->assertSame((int) $row['id'], (int) $this->db->one('SELECT tag_id FROM story_tags WHERE story_id = ?', [$story])['tag_id']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM tag_suggestions')['c']);
        $this->assertSame(['Zzyzx Marker'], $out['created']);
        // a second save with the same name attaches the now-live tag
        $out = $this->repo()->submit($story, 2, [$genre => 'zzyzx marker'], 'free');
        $this->assertSame(['Zzyzx Marker'], $out['attached']);
        $this->assertSame($before + 1, (int) $this->db->one('SELECT COUNT(*) c FROM tags')['c'], 'no duplicate tag row');
    }

    public function test_twenty_first_pending_suggestion_is_refused(): void
    {
        $story = $this->makeStory();
        $genre = $this->typeId('genre');
        for ($i = 0; $i < 20; $i++) {
            $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, 2, ?, ?, ?)',
                [$genre, 'Pending Tag ' . $i, $story, 'pending', date('c')]);
        }
        $out = $this->repo()->submit($story, 2, [$genre => 'One More Marker'], 'suggest');
        $this->assertSame(['One More Marker' => 'limit'], $out['refused']);
        $this->assertSame(20, (int) $this->db->one("SELECT COUNT(*) c FROM tag_suggestions WHERE suggested_by = 2 AND status = 'pending'")['c']);
    }

    public function test_rate_limit_stops_a_flood(): void
    {
        $story = $this->makeStory();
        $genre = $this->typeId('genre');
        $limiter = new \App\AttemptLimiter($this->db);
        for ($i = 0; $i < 30; $i++) {
            $this->assertTrue($limiter->claim('tag_suggest', '2', '10.0.0.7', 30, 60));
        }
        $out = $this->repo()->submit($story, 2, [$genre => 'Flooded Marker'], 'suggest', '10.0.0.7');
        $this->assertSame(['Flooded Marker' => 'rate'], $out['refused']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM tag_suggestions')['c']);
        // the per-key cap is per member: the same member from another ip is
        // still capped, a different member on that ip is not
        $out = $this->repo()->submit($story, 2, [$genre => 'Other Ip Marker'], 'suggest', '10.0.0.9');
        $this->assertSame(['Other Ip Marker' => 'rate'], $out['refused'], 'member 2 is at their key cap');
        $out = $this->repo()->submit($story, 1, [$genre => 'Other Member Marker'], 'suggest', '10.0.0.9');
        $this->assertSame(['Other Member Marker'], $out['pending']);
        $this->assertSame(1, (int) $this->db->one("SELECT suggested_by FROM tag_suggestions WHERE name = 'Other Member Marker'")['suggested_by']);
    }

    public function test_rejected_name_is_not_requeued_for_the_same_story(): void
    {
        $story = $this->makeStory();
        $genre = $this->typeId('genre');
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at, resolved_at, resolved_by) VALUES (?, ?, 2, ?, ?, ?, ?, 1)',
            [$genre, 'Once Rejected', $story, 'rejected', date('c'), date('c')]);
        $out = $this->repo()->submit($story, 2, [$genre => 'once rejected'], 'suggest');
        $this->assertSame(['Once Rejected' => 'rejected'], $out['refused'], 'the member sees it was already turned down');
        $row = $this->db->one("SELECT status FROM tag_suggestions WHERE name = 'Once Rejected'");
        $this->assertSame('rejected', $row['status']);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM tag_suggestions')['c']);
    }

    public function test_submit_returns_per_name_outcomes(): void
    {
        $story = $this->makeStory();
        $genre = $this->typeId('genre');
        $out = $this->repo()->submit($story, 2, [$genre => 'FANTASY, New Marker, no, <b>bad</b>'], 'suggest');
        $this->assertSame(['Fantasy'], $out['attached']);
        $this->assertSame(['New Marker', 'no'], $out['pending'], 'a two-character name is a legal name');
        $this->assertSame('name', $out['refused']['<b>bad</b>'] ?? '', 'angle-bracket names are refused with a reason');
        $this->assertSame(['attached', 'pending', 'created', 'refused'], array_keys($out));
    }

    public function test_withdraw_removes_only_the_authors_own_pending_row(): void
    {
        $story = $this->makeStory();
        $genre = $this->typeId('genre');
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, 2, ?, ?, ?)',
            [$genre, 'Mine To Pull', $story, 'pending', date('c')]);
        $mine = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, 1, ?, ?, ?)',
            [$genre, 'Theirs Stays', $story, 'pending', date('c')]);
        $theirs = (int) $this->db->lastInsertId();
        $this->repo()->withdraw(2, [$mine, $theirs]);
        $this->assertNull($this->db->one('SELECT id FROM tag_suggestions WHERE id = ?', [$mine]), 'the author withdrew their own');
        $this->assertNotNull($this->db->one('SELECT id FROM tag_suggestions WHERE id = ?', [$theirs]), 'another member row is untouched');
    }

    public function test_tag_policy_setting_is_a_valid_oneof_with_off_default(): void
    {
        $this->assertSame('oneof:off,suggest,free', \App\Settings::KEYS['tag_policy']);
        $this->assertSame('off', \App\Settings::DEFAULTS['tag_policy']);
        $this->assertTrue(\App\Settings::accepts('tag_policy', 'suggest'));
        $this->assertFalse(\App\Settings::accepts('tag_policy', 'anything'));
    }
}
