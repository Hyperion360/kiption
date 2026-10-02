<?php // app/Features/Wrangling/Tests/WranglingTest.php
namespace App\Features\Wrangling\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Task 4: the first tag surfaces (the story form's checkboxes and the story
 *  view's canonical-resolved blob) plus the admin wrangling surface (the
 *  guarded transactional merge and the unmerge). Every seeded tag id is
 *  resolved BY NAME (the seed grew demo tags for the comp chip row, so
 *  literal ids would silently re-point these scenarios at the wrong tags),
 *  every test-created tag captures lastInsertId, and every story_tags count
 *  is scoped to the-rabbit-hole (the seed's second story carries tags). */
final class WranglingTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private Database $db;
    private int $memberRowId = 0;
    private int $authorRowId = 0;
    private int $adminRowId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-wrap-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-wrap-mail-') . '.log';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->memberRowId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $this->authorRowId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        // The wrangling operator (the FeatureGatesTest admin idiom).
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('wrapadmin@e.test', ?, 'wrapadmin', 'admin', 1, ?, ?, 'wrapadmin')",
            [$hash, date('c'), date('c')]);
        $this->adminRowId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        // The FeaturesTest discipline: reset the global resolver state.
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function memberId(): int
    {
        return $this->memberRowId;
    }

    private function authorId(): int
    {
        return $this->authorRowId;
    }

    private function adminId(): int
    {
        return $this->adminRowId;
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-wrap-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    /** Seeded tags resolve by name; test-created tags capture their id. */
    private function tagId(string $name): int
    {
        return (int) $this->db->one('SELECT id FROM tags WHERE name = ?', [$name])['id'];
    }

    private function typeId(string $name): int
    {
        return (int) $this->db->one('SELECT id FROM tag_types WHERE name = ?', [$name])['id'];
    }

    private function insertTag(int $typeId, string $name): int
    {
        $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$typeId, $name]);
        return (int) $this->db->lastInsertId();
    }

    /** The slug-scoped count every scenario asserts against. */
    private function storyTagCount(int $tagId): int
    {
        return (int) $this->db->one(
            'SELECT COUNT(*) c FROM story_tags WHERE story_id = (SELECT id FROM stories WHERE slug = ?) AND tag_id = ?',
            ['the-rabbit-hole', $tagId])['c'];
    }

    public function test_story_form_tags_and_the_canonical_view_blob(): void
    {
        // the seeded Fantasy/Adventure genre tags: checkboxes on the form, checked state round-trips
        $fantasy = $this->tagId('Fantasy');
        $adventure = $this->tagId('Adventure');
        $me = $this->client($this->authorId());
        $this->assertSame(302, $me->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'rating_id' => 2, 'categories' => [1], 'tags' => [$fantasy]])->status);
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Fantasy', $body);           // the tag renders (canonical-resolved)
        $this->assertStringNotContainsString('Adventure', $body);     // unselected stays off
        // the edit form's checked state round-trips, and the create form carries the checkboxes too
        $edit = $me->get('/story/edit/the-rabbit-hole')->body;
        $this->assertStringContainsString('name="tags[]" value="' . $fantasy . '" checked', $edit);
        $this->assertStringNotContainsString('name="tags[]" value="' . $adventure . '" checked', $edit);
        $new = $me->get('/story/new')->body;
        $this->assertStringContainsString('name="tags[]" value="' . $fantasy . '"', $new);
        // unchecking clears: an update without the tags key empties story_tags
        $this->assertSame(302, $me->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'rating_id' => 2, 'categories' => [1]])->status);
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM story_tags WHERE story_id = (SELECT id FROM stories WHERE slug = ?)', ['the-rabbit-hole'])['c']);
        // forged ids never trip the FK (the categories idiom): unknown ids drop
        $this->assertSame(302, $me->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'rating_id' => 2, 'categories' => [1], 'tags' => [$fantasy, 99]])->status);
        $this->assertSame(1, (int) $this->db()->one('SELECT COUNT(*) c FROM story_tags WHERE story_id = (SELECT id FROM stories WHERE slug = ?)', ['the-rabbit-hole'])['c'], 'the forged id dropped, the real one kept');
    }

    public function test_merge_repoints_and_retires(): void
    {
        $db = $this->db();
        $fantasy = $this->tagId('Fantasy');
        $adventure = $this->tagId('Adventure');
        $syn = $this->insertTag($this->typeId('genre'), 'Fantasyish');
        $db->query('INSERT INTO story_tags (story_id, tag_id) VALUES ((SELECT id FROM stories WHERE slug = ?), ?)', ['the-rabbit-hole', $syn]);
        $admin = $this->client($this->adminId());
        $this->assertSame(302, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $syn, 'canonical_id' => $fantasy])->status);
        // story_tags re-pointed; the synonym retired with canonical_id set
        $this->assertSame(0, $this->storyTagCount($syn));
        $this->assertSame(1, $this->storyTagCount($fantasy));
        $this->assertSame($fantasy, (int) $db->one('SELECT canonical_id FROM tags WHERE id = ?', [$syn])['canonical_id']);
        // self-merge 422, and the guard fires BEFORE the transaction: the
        // canonical's story_tags rows survive the rejected attempt (the
        // unguarded shape would wipe them, plan review finding 6).
        $this->assertSame(422, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $fantasy, 'canonical_id' => $fantasy])->status);
        $this->assertSame(1, $this->storyTagCount($fantasy));
        // cross-type merge 422: a content-type tag can never merge into a genre tag
        $fluff = $this->insertTag($this->typeId('content'), 'Fluff');
        $this->assertSame(422, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $fluff, 'canonical_id' => $fantasy])->status);
        // merging INTO a retired synonym 422: canonical resolution stays single-level
        $this->assertSame(422, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $adventure, 'canonical_id' => $syn])->status);
        // unknown ids 404 (forged or stale input)
        $this->assertSame(404, $admin->postWithToken('/wrangling/merge', ['synonym_id' => 999, 'canonical_id' => $fantasy])->status);
        $this->assertSame(404, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $adventure, 'canonical_id' => 999])->status);
        // idempotent re-merge lands clean: the already-retired pair re-merges 302
        // and nothing moves (INSERT OR IGNORE + empty DELETE + no-op UPDATEs)
        $this->assertSame(302, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $syn, 'canonical_id' => $fantasy])->status);
        $this->assertSame(1, $this->storyTagCount($fantasy));
        $this->assertSame($fantasy, (int) $db->one('SELECT canonical_id FROM tags WHERE id = ?', [$syn])['canonical_id']);
        // guest/member draw the SQL admin gate's 403 on every action
        $this->assertSame(403, $this->client()->get('/wrangling')->status);
        $this->assertSame(403, $this->client($this->memberId())->get('/wrangling')->status);
        $this->assertSame(403, $this->client()->postWithToken('/wrangling/merge', ['synonym_id' => $adventure, 'canonical_id' => $fantasy])->status);
        $this->assertSame(403, $this->client($this->memberId())->postWithToken('/wrangling/unmerge/' . $syn)->status);
    }

    public function test_merge_refuses_a_canonical_retired_mid_transaction(): void
    {
        // F3: the reject guards probe a PRE-transaction snapshot, so a second
        // admin retiring the chosen canonical inside that window must be
        // refused by the in-transaction conditional retire, not chained into
        // a two-level resolution. The onQuery tap slips admin B's write in
        // AFTER the snapshot but BEFORE admin A's first write takes the lock
        // (the tap fires pre-execution; begin() is deferred).
        $db = $this->db();
        $fantasy = $this->tagId('Fantasy');
        $adventure = $this->tagId('Adventure');
        $epic = $this->insertTag($this->typeId('genre'), 'Epic');
        $db->query('INSERT INTO story_tags (story_id, tag_id) VALUES ((SELECT id FROM stories WHERE slug = ?), ?)', ['the-rabbit-hole', $adventure]);
        $app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-wrap-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $tap = $app->container->make(Database::class);
        $tap->onQuery(function (string $sql) use ($db, $epic, $fantasy): void {
            if (str_contains($sql, 'INSERT OR IGNORE INTO story_tags')) {
                $db->query('UPDATE tags SET canonical_id = ? WHERE id = ?', [$epic, $fantasy]); // admin B's retire commits first
            }
        });
        $admin = (new TestClient($app))->actingAs($this->adminId());
        $res = $admin->postWithToken('/wrangling/merge', ['synonym_id' => $adventure, 'canonical_id' => $fantasy]);
        $tap->onQuery(fn () => null); // detach (the App.php request-local idiom)
        $this->assertSame(422, $res->status, 'the raced retire refuses instead of chaining');
        $this->assertNull($db->one('SELECT canonical_id FROM tags WHERE id = ?', [$adventure])['canonical_id'], 'Adventure never retired');
        $this->assertSame(1, $this->storyTagCount($adventure), 'the story row never moved');
    }

    /** A merge of a CANONICAL that already carries synonyms must re-point the
     *  whole chain (plan review finding 6's fourth statement): merging
     *  Adventure into Fantasy, then Fantasy into Epic, lands Adventure on
     *  Epic, not on the retired Fantasy. */
    public function test_a_later_merge_repoints_the_whole_chain(): void
    {
        $db = $this->db();
        $fantasy = $this->tagId('Fantasy');
        $adventure = $this->tagId('Adventure');
        $epic = $this->insertTag($this->typeId('genre'), 'Epic');
        $admin = $this->client($this->adminId());
        $this->assertSame(302, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $adventure, 'canonical_id' => $fantasy])->status);
        $this->assertSame(302, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $fantasy, 'canonical_id' => $epic])->status);
        $this->assertSame($epic, (int) $db->one('SELECT canonical_id FROM tags WHERE id = ?', [$fantasy])['canonical_id'], 'Fantasy retired into Epic');
        $this->assertSame($epic, (int) $db->one('SELECT canonical_id FROM tags WHERE id = ?', [$adventure])['canonical_id'], 'the chain re-pointed Adventure onto Epic');
        // the story view resolves through whatever row story_tags holds
        $db->query('INSERT INTO story_tags (story_id, tag_id) VALUES ((SELECT id FROM stories WHERE slug = ?), ?)', ['the-rabbit-hole', $fantasy]);
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Epic', $body);
        $this->assertStringNotContainsString('Fantasyish', $body);
    }

    public function test_unmerge_restores_the_synonym_but_not_the_moved_rows(): void
    {
        $db = $this->db();
        $fantasy = $this->tagId('Fantasy');
        $syn = $this->insertTag($this->typeId('genre'), 'Fantasyish');
        $db->query('INSERT INTO story_tags (story_id, tag_id) VALUES ((SELECT id FROM stories WHERE slug = ?), ?)', ['the-rabbit-hole', $syn]);
        $admin = $this->client($this->adminId());
        $this->assertSame(302, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $syn, 'canonical_id' => $fantasy])->status);
        // unmerge: the retirement clears, the moved story_tags rows stay on the
        // canonical (the documented data-loss boundary)
        $this->assertSame(302, $admin->postWithToken('/wrangling/unmerge/' . $syn)->status);
        $this->assertNull($db->one('SELECT canonical_id FROM tags WHERE id = ?', [$syn])['canonical_id']);
        $this->assertSame(1, $this->storyTagCount($fantasy));
        $this->assertSame(0, $this->storyTagCount($syn));
        // an unknown tag id 404s
        $this->assertSame(404, $admin->postWithToken('/wrangling/unmerge/999')->status);
    }

    public function test_a_synonym_id_written_on_the_story_form_lands_as_the_canonical(): void
    {
        // writeTags normalizes on write: a retired synonym id posted through
        // the story form stores the canonical row (COALESCE on the write).
        $db = $this->db();
        $fantasy = $this->tagId('Fantasy');
        $syn = $this->insertTag($this->typeId('genre'), 'Fantasyish');
        $this->assertSame(302, $this->client($this->adminId())->postWithToken('/wrangling/merge', ['synonym_id' => $syn, 'canonical_id' => $fantasy])->status);
        $me = $this->client($this->authorId());
        $this->assertSame(302, $me->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'rating_id' => 2, 'categories' => [1], 'tags' => [$syn]])->status);
        $this->assertSame(0, $this->storyTagCount($syn));
        $this->assertSame(1, $this->storyTagCount($fantasy));
    }

    public function test_the_wrangling_index_lists_types_tags_and_counts(): void
    {
        $fantasy = $this->tagId('Fantasy');
        $adventure = $this->tagId('Adventure');
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES ((SELECT id FROM stories WHERE slug = ?), ?)', ['the-rabbit-hole', $fantasy]);
        $body = $this->client($this->adminId())->get('/wrangling')->body;
        $this->assertStringContainsString('Fantasy', $body);
        $this->assertStringContainsString('Adventure', $body);
        $this->assertStringContainsString('genre', $body);
        $this->assertStringContainsString('href="/wrangling/merge?synonym=' . $adventure . '"', $body, 'each tag row links to its merge form');
    }

    public function test_the_merge_form_constrains_candidates_to_the_same_type(): void
    {
        $fantasy = $this->tagId('Fantasy');
        $adventure = $this->tagId('Adventure');
        $fluff = $this->insertTag($this->typeId('content'), 'Fluff');
        $admin = $this->client($this->adminId());
        // the step-one form (no synonym chosen yet): a synonym select over every tag
        $step = $admin->get('/wrangling/merge')->body;
        $this->assertStringContainsString('name="synonym"', $step);
        // the step-two form: the canonical select carries the same-type active
        // tags minus the synonym itself, never the other type's tags
        $form = $admin->get('/wrangling/merge', ['synonym' => $adventure])->body;
        $this->assertStringContainsString('name="canonical_id"', $form);
        $this->assertStringContainsString('<option value="' . $fantasy . '">Fantasy</option>', $form);
        $this->assertStringNotContainsString('<option value="' . $fluff . '">', $form, 'the content-type tag never offers as a genre canonical');
        // an unknown synonym 404s
        $this->assertSame(404, $admin->get('/wrangling/merge', ['synonym' => 999])->status);
    }

    /** The transaction's rollback arm (the ListsTest RAISE idiom): a trigger
     *  aborts the quadruple mid-flight, after the story_tags re-point and
     *  delete but before the retirement lands. The controller rolls back,
     *  rethrows (the 500 the kernel's error page answers), and nothing
     *  strands: the synonym keeps its rows, the canonical gains nothing, the
     *  retirement never fires. With the fault cleared the same merge lands. */
    public function test_merge_rollback_leaves_nothing_behind_when_the_quadruple_fails(): void
    {
        $db = $this->db();
        $fantasy = $this->tagId('Fantasy');
        $syn = $this->insertTag($this->typeId('genre'), 'Fantasyish');
        $db->query('INSERT INTO story_tags (story_id, tag_id) VALUES ((SELECT id FROM stories WHERE slug = ?), ?)', ['the-rabbit-hole', $syn]);
        $db->query("CREATE TRIGGER merge_boom BEFORE UPDATE ON tags FOR EACH ROW
                    WHEN NEW.canonical_id IS NOT NULL AND OLD.canonical_id IS NULL
                    BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $admin = $this->client($this->adminId());
        $this->assertSame(500, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $syn, 'canonical_id' => $fantasy])->status,
            'the aborted merge rethrows through the kernel error page');
        $this->assertSame(1, $this->storyTagCount($syn), 'the synonym keeps its rows');
        $this->assertSame(0, $this->storyTagCount($fantasy), 'the canonical gained nothing');
        $this->assertNull($db->one('SELECT canonical_id FROM tags WHERE id = ?', [$syn])['canonical_id'], 'the retirement never landed');
        // with the fault cleared the table is healthy and the same merge goes through
        $db->query('DROP TRIGGER merge_boom');
        $this->assertSame(302, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $syn, 'canonical_id' => $fantasy])->status);
        $this->assertSame(0, $this->storyTagCount($syn));
        $this->assertSame($fantasy, (int) $db->one('SELECT canonical_id FROM tags WHERE id = ?', [$syn])['canonical_id']);
    }

    /** Moderators are not wranglers: the SQL admin gate (role = 'admin') 403s
     *  them on every action beside members and guests, and nothing writes. */
    public function test_moderators_draw_the_admin_gate_too(): void
    {
        \App\Adminness::setRole($this->db(), $this->memberId(), 'moderator');
        $fantasy = $this->tagId('Fantasy');
        $adventure = $this->tagId('Adventure');
        $mod = $this->client($this->memberId());
        $this->assertSame(403, $mod->get('/wrangling')->status);
        $this->assertSame(403, $mod->postWithToken('/wrangling/merge', ['synonym_id' => $adventure, 'canonical_id' => $fantasy])->status);
        $this->assertSame(403, $mod->postWithToken('/wrangling/unmerge/' . $adventure)->status);
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM story_tags WHERE story_id = (SELECT id FROM stories WHERE slug = ?)', ['the-rabbit-hole'])['c'], 'no re-point fired');
        $this->assertNull($this->db()->one('SELECT canonical_id FROM tags WHERE id = ?', [$adventure])['canonical_id'], 'no retirement fired');
    }
}
