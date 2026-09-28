<?php // tests/SeriesRepositoryTest.php
namespace App\Tests;
use App\Repositories\SeriesRepository;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class SeriesRepositoryTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private SeriesRepository $repo;
    private int $ownerId = 1;
    private int $authorId = 0;
    private int $adminUserId = 0;

    private function adminId(): int
    {
        return $this->adminUserId;
    }

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-ser-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->repo = new SeriesRepository($this->db);
        $this->ownerId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('other@e.test', ?, 'otherwriter', ?, ?, 'otherwriter')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->authorId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('admin@e.test', ?, 'siteadmin', 'admin', 1, ?, ?, 'siteadmin')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated) VALUES ('Other Tale', 'other-tale', ?, (SELECT id FROM ratings LIMIT 1), 1)", [$this->authorId]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    public function test_create_makes_slug_and_defaults(): void
    {
        $slug = $this->repo->create($this->ownerId, 'Winter Songs', 'A cycle.', 'closed');
        $this->assertSame('winter-songs', $slug);
        $row = $this->db->one('SELECT membership, owner_id FROM series WHERE slug = ?', [$slug]);
        $this->assertSame('closed', $row['membership']);
        $this->assertSame($this->ownerId, (int) $row['owner_id']);
    }

    public function test_add_item_rules_by_membership(): void
    {
        $slug = $this->repo->create($this->ownerId, 'Closed Cycle', '', 'closed');
        $this->expectException(\RuntimeException::class);
        $this->repo->addItem($slug, 'other-tale', $this->authorId, false);
    }

    public function test_add_item_moderated_pends_for_non_owner(): void
    {
        $slug = $this->repo->create($this->ownerId, 'Mod Cycle', '', 'moderated');
        $this->assertSame('pending', $this->repo->addItem($slug, 'other-tale', $this->authorId, false));
        // owner re-adding the pending submission CONFIRMS it (coordinator ruling)
        $this->assertSame('confirmed', $this->repo->addItem($slug, 'other-tale', $this->ownerId, false));
        // duplicate insert is swallowed (already confirmed now)
        $this->assertSame('duplicate', $this->repo->addItem($slug, 'other-tale', $this->ownerId, false));
    }

    public function test_add_item_open_confirms_and_rejects_foreign_story(): void
    {
        $slug = $this->repo->create($this->ownerId, 'Open Shelf', '', 'open');
        $this->assertSame('added', $this->repo->addItem($slug, 'other-tale', $this->authorId, false));
        // A third member who is neither owner nor the story's author cannot add it.
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('x@e.test', ?, 'strangerx', ?, ?, 'strangerx')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $stranger = (int) $this->db->lastInsertId();
        $this->expectException(\RuntimeException::class);
        $this->repo->addItem($slug, 'the-rabbit-hole', $stranger, false);
    }

    public function test_page_fold_hides_pending_and_unvalidated_from_guests(): void
    {
        $slug = $this->repo->create($this->ownerId, 'Visible', '', 'open');
        $this->repo->addItem($slug, 'the-rabbit-hole', $this->ownerId, false);
        $this->repo->addItem($slug, 'other-tale', $this->authorId, false);
        // guest: the-rabbit-hole visible, no owner-only noise
        $guest = $this->repo->seriesPage($slug, 0);
        $this->assertSame('Visible', $guest['series']['title']);
        $this->assertCount(2, $guest['items']); // both stories are validated + confirmed=open
        $this->assertSame('the-rabbit-hole', $guest['items'][0]['slug']); // position 1 first
        // moderated series: guest sees only confirmed
        $mod = $this->repo->create($this->ownerId, 'Modded', '', 'moderated');
        $this->repo->addItem($mod, 'other-tale', $this->authorId, false); // pending
        $this->repo->addItem($mod, 'the-rabbit-hole', $this->ownerId, false); // confirmed
        $page = $this->repo->seriesPage($mod, 0);
        $this->assertCount(1, $page['items']);
        $this->assertSame(1, (int) $page['items'][0]['confirmed']);
        $ownerView = $this->repo->seriesPage($mod, $this->ownerId);
        $this->assertCount(2, $ownerView['items']);
        // pending was added first (position 1), confirmed second (position 2)
        $this->assertSame(0, (int) $ownerView['items'][0]['confirmed']); // pending flagged for owner
        $this->assertSame(1, (int) $ownerView['items'][1]['confirmed']);
        // admin (non-owner, non-author) also sees pending items (plan review finding 19)
        $adminView = $this->repo->seriesPage($mod, $this->adminId());
        $this->assertCount(2, $adminView['items']);
        $this->assertSame(1, $adminView['series']['is_admin']);
    }

    public function test_move_down_swaps_one_slot(): void
    {
        $slug = $this->repo->create($this->ownerId, 'Downhill', '', 'open');
        $this->repo->addItem($slug, 'the-rabbit-hole', $this->ownerId, false);
        $this->repo->addItem($slug, 'other-tale', $this->authorId, false);
        $items = $this->repo->seriesPage($slug, $this->ownerId)['items'];
        $this->repo->move($slug, (int) $items[0]['item_id'], 'down', $this->ownerId, false);
        // one slot down, not a jump to the front
        $moved = $this->repo->seriesPage($slug, $this->ownerId)['items'];
        $this->assertSame('other-tale', $moved[0]['slug']);
        $this->assertSame('the-rabbit-hole', $moved[1]['slug']);
        // positions swap cleanly and neither item strands on the sentinel
        $rows = $this->db->all('SELECT position FROM series_items WHERE series_id = (SELECT id FROM series WHERE slug = ?) ORDER BY position', [$slug]);
        $this->assertSame([1, 2], array_map(fn (array $r): int => (int) $r['position'], $rows));
    }

    public function test_remove_move_confirm(): void
    {
        $slug = $this->repo->create($this->ownerId, 'Ordered', '', 'open');
        $this->repo->addItem($slug, 'the-rabbit-hole', $this->ownerId, false);
        $this->repo->addItem($slug, 'other-tale', $this->authorId, false);
        $items = $this->repo->seriesPage($slug, $this->ownerId)['items'];
        $this->repo->move($slug, (int) $items[1]['item_id'], 'up', $this->ownerId, false);
        $moved = $this->repo->seriesPage($slug, $this->ownerId)['items'];
        $this->assertSame('other-tale', $moved[0]['slug']);
        // story author may remove their own story from another owner's series
        $this->assertTrue($this->repo->removeItem($slug, 'other-tale', $this->authorId, false));
        $this->assertCount(1, $this->repo->seriesPage($slug, $this->ownerId)['items']);
    }
}
