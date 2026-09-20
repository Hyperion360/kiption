<?php // tests/LegacyRedirectsTest.php
namespace App\Tests;
use App\Import\LegacyRedirects;
use Kip\Database;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

final class LegacyRedirectsTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-301-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        // a committed import's map state, minimal: story 7 -> our seeded story
        $this->db->query("INSERT INTO import_map (legacy_table, legacy_id, new_table, new_id, run_id) VALUES ('fanfiction_stories', '7', 'stories', (SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), 1)");
        $this->db->query("INSERT INTO import_map (legacy_table, legacy_id, new_table, new_id, run_id) VALUES ('fanfiction_authors', '1', 'users', (SELECT id FROM users WHERE penname = 'Demo Author'), 1)");
        (new LegacyRedirects())->writeForImport($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub behind the .sqlite suffix
    }

    public function test_every_legacy_shape_redirects_to_current_slugs(): void
    {
        $l = new LegacyRedirects();
        $this->assertSame('/story/view/the-rabbit-hole', $l->lookup(new Request('GET', '/viewstory.php', ['sid' => '7'], [], []), $this->db));
        $this->assertSame('/user/view/demo-author', $l->lookup(new Request('GET', '/viewuser.php', ['uid' => '1'], [], []), $this->db));
        $this->assertNull($l->lookup(new Request('GET', '/viewstory.php', ['sid' => '999'], [], []), $this->db));
        $this->assertNull($l->lookup(new Request('GET', '/browse.php', ['catid' => '1'], [], []), $this->db), 'category shapes need the category map');
        $this->assertNull($l->lookup(new Request('GET', '/story/view/x', [], [], []), $this->db), 'non-legacy paths never consult');
    }

    public function test_reviews_shape_uses_item_and_type_order_insensitively(): void
    {
        $l = new LegacyRedirects();
        $this->assertSame('/story/view/the-rabbit-hole',
            $l->lookup(new Request('GET', '/reviews.php', ['type' => 'ST', 'item' => '7'], [], []), $this->db));
        $this->assertSame('/story/view/the-rabbit-hole',
            $l->lookup(new Request('GET', '/reviews.php', ['item' => '7', 'type' => 'ST'], [], []), $this->db));
        $this->assertNull($l->lookup(new Request('GET', '/reviews.php', ['sid' => '7'], [], []), $this->db), 'the old single-param shape never matched real eFiction links');
    }
}
