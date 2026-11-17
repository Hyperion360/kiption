<?php // app/Features/Tags/Tests/TagSuggestionLeakTest.php
namespace App\Features\Tags\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\Tests\Support\TaxonomyCase;

/** Slice 4 Task 4.6: the negative tests that justify the separate table.
 *  Pending names never enter tags, so every existing reader of tags is
 *  correct by construction. These tests exist to prove it and to fail loudly
 *  if a later change breaks that. */
#[Medium]
final class TagSuggestionLeakTest extends TaxonomyCase
{
    private const MARKER = 'Zzyzx Marker';

    private function setPolicy(string $policy): void
    {
        $this->db->query('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', ['tag_policy', $policy]);
        \App\Features::init($this->db, []);
    }

    /** Member A posts a story carrying the pending suggestion, with the
     *  static cache enabled, and returns the story's id and slug. */
    private function suggestThroughTheForm(): array
    {
        $this->setPolicy('suggest');
        $genre = (int) $this->row("SELECT id FROM tag_types WHERE name = 'genre'")['id'];
        $memberA = $this->memberId;
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('leak-a@e.test', ?, 'leaka', ?, ?)",
            [\App\Tests\Support\Hashes::of('password123'), date('c'), date('c')]);
        $author = (int) $this->db->lastInsertId();
        $res = $this->client($author)->postWithToken('/story/create', [
            'title' => 'The Leak Probe', 'summary' => 'A probe story.', 'rating_id' => '1',
            'categories' => [(string) $this->row("SELECT id FROM categories WHERE slug = 'general'")['id']],
            'suggest' => [$genre => self::MARKER],
        ]);
        $this->assertSame(302, $res->status, (string) $res->body);
        $story = $this->row("SELECT id, slug FROM stories WHERE slug = 'the-leak-probe'");
        $this->assertNotNull($story);
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tag_suggestions WHERE name = ?", [self::MARKER]));
        return ['id' => (int) $story['id'], 'slug' => (string) $story['slug'], 'author' => $author];
    }

    public function test_a_pending_name_appears_nowhere_a_guest_or_other_member_can_look(): void
    {
        $story = $this->suggestThroughTheForm();
        $memberB = $this->adminId; // any signed-in member who is not the suggester
        $this->db->query("UPDATE users SET role = 'member', is_admin = 0 WHERE id = ?", [$memberB]);
        $paths = [
            '/story/view/' . $story['slug'],
            '/search?q=Zzyzx', '/search?tags=Zzyzx',
            '/browse/filter', '/browse/recent', '/tags',
            '/sitemap.xml', '/feed',
            '/feed/story/' . $story['slug'],
            '/user/view/leaka', '/user/stories/leaka',
        ];
        foreach ([$this->client(), $this->client($memberB)] as $i => $c) {
            foreach ($paths as $path) {
                $res = $c->get($path);
                $this->assertStringNotContainsString(self::MARKER, (string) $res->body, "viewer {$i} saw the pending name on {$path}");
            }
        }
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM tags WHERE name = ?', [self::MARKER]), 'the live tag list is untouched');
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM stories_fts WHERE stories_fts MATCH ?', ['zzyzx']), 'the search index is untouched');
    }

    public function test_pending_names_never_enter_a_cached_story_page(): void
    {
        $story = $this->suggestThroughTheForm();
        // a guest fetch lands in the static cache; the pending name must not
        // ride along on any later render of that page
        $this->client()->get('/story/view/' . $story['slug']);
        $dir = $this->cacheDir;
        $hits = [];
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (str_contains((string) file_get_contents((string) $f), self::MARKER)) { $hits[] = $f; }
        }
        $this->assertSame([], $hits, 'a cached page carries the pending name');
    }

    public function test_the_suggester_sees_it_only_on_their_own_edit_form(): void
    {
        $story = $this->suggestThroughTheForm();
        $suggesterId = (int) $this->row("SELECT id FROM users WHERE penname = 'leaka'")['id'];
        $edit = $this->client($suggesterId)->get('/story/edit/' . $story['slug'])->body;
        $this->assertStringContainsString(self::MARKER, $edit, 'the suggester sees their own waiting row on the edit form');
        // and nowhere else of their own surfaces
        foreach (['/user/view/leaka', '/user/stories/leaka', '/story/view/' . $story['slug']] as $path) {
            $this->assertStringNotContainsString(self::MARKER, $this->client($suggesterId)->get($path)->body, $path);
        }
    }

    public function test_importers_seeder_and_demo_do_not_create_suggestions_or_read_them(): void
    {
        $this->suggestThroughTheForm();
        \App\Seeder::run($this->db, true); // force: re-seed over the fixture
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM tag_suggestions'), 'the seeder never writes suggestions');
        \App\Demo::run($this->db, false, true); // dev flag: the demo refuses production databases
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM tag_suggestions'), 'the demo builder never writes suggestions');
    }

    public function test_member_cannot_read_the_queue_or_post_to_it(): void
    {
        $this->suggestThroughTheForm();
        $this->assertSame(403, $this->client($this->memberId)->get('/tagqueue')->status);
        foreach (['approve', 'merge', 'reject'] as $action) {
            $res = $this->client($this->memberId)->postWithToken('/tagqueue/' . $action, ['type_id' => '1', 'name' => self::MARKER, 'target_id' => '1']);
            $this->assertSame(403, $res->status, $action);
        }
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tag_suggestions WHERE status = 'pending'"), 'nothing changed');
    }
}
