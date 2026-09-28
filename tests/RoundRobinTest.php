<?php // tests/RoundRobinTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * The round-robin workflow (Task 5): on stories marked round_robin, the
 * chapter gate opens to any FULL member (approved + verified + unlocked,
 * the directory gate) behind the roundrobin flag. The expansion is
 * creation-only by construction: chapters carry no contributor attribution
 * (001's chapters table has no author column), so a member editing "their"
 * chapter cannot be told apart from one editing a stranger's, and the safe
 * ruling keeps chapter edit/delete and every story form story-side (author,
 * coauthor, admin). Contributors reach /chapter/new/{slug} directly (the
 * README row records the URL); their chapters land validated=0 in the
 * queue like every member write.
 */
final class RoundRobinTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rr-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        \App\Features::init($this->db, []);
    }

    protected function tearDown(): void
    {
        // Flag state is global: reset() drops the memo and the DB handle so no
        // later suite in this single phpunit process inherits this unlinking
        // temp DB (the FeatureGatesTest finding 2 idiom).
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-rr-mail-'), 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => tempnam(sys_get_temp_dir(), 'kiption-rr-upl-')],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    /** betafriend: the seeded FULL member (approved + verified + unlocked), not the author. */
    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    public function test_any_member_adds_chapters_to_rr_stories(): void
    {
        $this->db->query("UPDATE stories SET round_robin = 1 WHERE slug = 'the-rabbit-hole'");
        $member = $this->client($this->memberId());
        $res = $member->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'A stranger chapter', 'content' => 'The crowd writes.']);
        $this->assertSame(302, $res->status, 'rr opens the gate to full members');
        $this->assertStringEndsWith('/story/view/the-rabbit-hole', $res->headers['Location'],
            'a contributor lands on the story view, never the owner-gated edit');
        $row = $this->db->one("SELECT story_id, validated FROM chapters WHERE title = 'A stranger chapter'");
        $this->assertSame((int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'],
            (int) $row['story_id'], 'the chapter landed under the rr story');
        $this->assertSame(0, (int) $row['validated'], 'a member chapter arrives in the validation queue');
        // Still gated: a NON-rr story rejects the same member.
        $this->assertSame(404, $member->postWithToken('/chapter/create/after-hours', ['title' => 'X', 'content' => 'Words.'])->status);
        // A pending member (approved_at NULL) is rejected even on the rr story.
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at) VALUES ('rrpending@e.test', ?, 'rrpending', ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c')]);
        $pending = (int) $this->db->lastInsertId();
        $this->assertSame(404, $this->client($pending)->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'P', 'content' => 'Words.'])->status, 'pending members never pass the directory gate');
        // The flag off closes the expansion: the same member 404s again.
        \App\Features::toggle('roundrobin', false);
        $this->assertSame(404, $member->postWithToken('/chapter/create/the-rabbit-hole', ['title' => 'Y', 'content' => 'Words.'])->status);
    }

    public function test_contributor_surface_is_add_only_and_owner_paths_stay_unchanged(): void
    {
        $this->db->query("UPDATE stories SET round_robin = 1 WHERE slug = 'the-rabbit-hole'");
        $member = $this->client($this->memberId());
        // The add-chapter form opens through chapterFormData's gate; contributors
        // reach /chapter/new/{slug} directly.
        $form = $member->get('/chapter/new/the-rabbit-hole');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('<form', $form->body);
        // Story edit/delete stay owner-gated by construction: formData gains no
        // rr clause, and StoryController never passes the flag.
        $this->assertSame(404, $member->get('/story/edit/the-rabbit-hole')->status);
        $this->assertSame(404, $member->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'rating_id' => 2, 'categories' => [1]])->status);
        // A member chapter lands so the edit/delete pins swing at a row the
        // member themselves contributed.
        $this->assertSame(302, $member->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'Crowd entry', 'content' => 'The crowd wrote this.'])->status);
        $pos = (int) $this->db->one("SELECT position FROM chapters WHERE title = 'Crowd entry'")['position'];
        // Chapter edit/delete stay story-side only: chapters carry no
        // contributor attribution, so the rr clause never reaches them.
        $this->assertSame(404, $member->get('/chapter/edit/the-rabbit-hole/' . $pos)->status);
        $this->assertSame(404, $member->postWithToken('/chapter/update/the-rabbit-hole/' . $pos,
            ['title' => 'Crowd entry', 'content' => 'Rewritten.'])->status);
        $this->assertSame(404, $member->postWithToken('/chapter/delete/the-rabbit-hole/' . $pos)->status);
        $this->assertSame('The crowd wrote this.',
            $this->db->one("SELECT content FROM chapters WHERE title = 'Crowd entry'")['content'],
            'no member edit or delete fired');
        // The story-side actor keeps every path and lands on the edit form.
        $author = $this->client($this->authorId());
        $this->assertSame(200, $author->get('/chapter/edit/the-rabbit-hole/' . $pos)->status);
        $up = $author->postWithToken('/chapter/update/the-rabbit-hole/' . $pos,
            ['title' => 'Crowd entry', 'content' => 'The author smoothed it.']);
        $this->assertSame(302, $up->status, $up->body);
        $this->assertStringEndsWith('/story/edit/the-rabbit-hole', $up->headers['Location']);
        $this->assertSame('The author smoothed it.',
            $this->db->one("SELECT content FROM chapters WHERE title = 'Crowd entry'")['content']);
    }
}
