<?php // tests/FictioneerRedirectsTest.php
namespace App\Tests;
use App\Import\LegacyRedirects;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class FictioneerRedirectsTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-fxred-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        // a mapped story, chapter, and user, the rows writeForFictioneer
        // consumes (legacy ids are site-scoped: '{host}/{id}')
        $this->db->query("INSERT INTO import_map (legacy_table, legacy_id, new_table, new_id, run_id) VALUES
            ('fcn_story', 'old.example.org/11', 'stories', 1, 1),
            ('fcn_chapter', 'old.example.org/22', 'chapters', 1, 1),
            ('fcn_user', 'old.example.org/anna', 'users', 1, 1)");
        $this->db->query("UPDATE chapters SET story_id = 1 WHERE id = 1");
        // chapter rows are written inside the import (slug data exists only there)
        $this->db->query("INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES
            ('/chapter/first-meeting', '', 'story', 1),
            ('/story/the-rabbit-hole/first-meeting', '', 'story', 1)");
        (new LegacyRedirects())->writeForFictioneer($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub behind the .sqlite suffix
    }

    public function test_story_chapter_and_author_paths_redirect(): void
    {
        $red = new LegacyRedirects();
        $this->assertSame('/story/view/the-rabbit-hole', $red->lookupPath(new Request('GET', '/story/the-rabbit-hole', [], [], []), $this->db));
        $this->assertSame('/story/view/the-rabbit-hole', $red->lookupPath(new Request('GET', '/story/the-rabbit-hole/', [], [], []), $this->db), 'trailing slash tolerates');
        $this->assertSame('/story/view/the-rabbit-hole', $red->lookupPath(new Request('GET', '/chapter/first-meeting', [], [], []), $this->db), 'chapters land on the parent story');
        $this->assertSame('/story/view/the-rabbit-hole', $red->lookupPath(new Request('GET', '/story/the-rabbit-hole/first-meeting', [], [], []), $this->db));
        $this->assertSame('/user/view/' . $this->db->one("SELECT profile_slug FROM users WHERE id = 1")['profile_slug'],
            $red->lookupPath(new Request('GET', '/author/anna', [], [], []), $this->db));
    }

    public function test_query_fallback_redirects_with_sorted_params(): void
    {
        $red = new LegacyRedirects();
        $this->assertSame('/story/view/the-rabbit-hole',
            $red->lookupPath(new Request('GET', '/', ['post_type' => 'fcn_story', 'p' => '11'], [], []), $this->db), 'param order never matters');
        $this->assertNull($red->lookupPath(new Request('GET', '/', ['p' => '999'], [], []), $this->db));
    }

    public function test_unknown_paths_and_methods_return_null(): void
    {
        $red = new LegacyRedirects();
        $this->assertNull($red->lookupPath(new Request('GET', '/story/never-imported', [], [], []), $this->db));
        $this->assertNull($red->lookupPath(new Request('POST', '/story/the-rabbit-hole', [], [], []), $this->db));
        $this->assertNull($red->lookupPath(new Request('GET', '/', [], [], []), $this->db));
    }

    public function test_writes_are_idempotent(): void
    {
        (new LegacyRedirects())->writeForFictioneer($this->db);
        $n = (int) $this->db->one("SELECT COUNT(*) c FROM legacy_urls WHERE legacy_path = '/story/the-rabbit-hole'")['c'];
        $this->assertSame(1, $n);
    }

    public function test_deleted_entity_falls_through_to_404(): void
    {
        $this->db->query('DELETE FROM stories WHERE id = 1');
        $this->assertNull((new LegacyRedirects())->lookupPath(new Request('GET', '/story/the-rabbit-hole', [], [], []), $this->db));
    }
}
