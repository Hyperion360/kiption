<?php // app/Features/Story/Tests/AuthoringTest.php
namespace App\Features\Story\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

#[Medium]
final class AuthoringTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-auth2-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-authoring-test.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-authoring-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides);
    }

    private function clientAs(int $userId, array $overrides = []): TestClient
    {
        return (new TestClient(new App($this->config($overrides))))->actingAs($userId);
    }

    /** A second, plain member (goes through the queue). */
    private function memberId(): int
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('m2@e.test', ?, 'plainmember', ?, ?)",
            [\App\Tests\Support\Hashes::of('password123'), date('c'), date('c')]);
        return (int) $this->db->lastInsertId();
    }

    private function ratingId(): int
    {
        return (int) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
    }

    private function categoryId(): int
    {
        return (int) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id'];
    }

    public function test_story_form_renders_for_author(): void
    {
        $res = $this->clientAs(1)->get('/story/new');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<form', $res->body);
        $this->assertStringContainsString('name="title"', $res->body);
        $this->assertStringContainsString('name="rating_id"', $res->body); // ratings reach the form
        $this->assertStringContainsString('name="categories[]"', $res->body);
    }

    public function test_story_requires_login(): void
    {
        $res = (new TestClient(new App($this->config())))->get('/story/new');
        $this->assertSame(303, $res->status, 'a guest is sent to sign in, with the reason');
        $this->assertSame('/auth/login?to=post', $res->headers['Location']);
    }

    public function test_validated_author_bypasses_queue(): void
    {
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'Fresh Work', 'summary' => 'A new tale.', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringEndsWith('/story/edit/fresh-work', $res->headers['Location']);
        $row = $this->db->one("SELECT * FROM stories WHERE title = 'Fresh Work'");
        $this->assertSame(1, (int) $row['validated']);
        $this->assertSame('fresh-work', $row['slug']);
        $this->assertSame(1, (int) $this->db->one(
            'SELECT COUNT(*) c FROM story_categories WHERE story_id = ?', [$row['id']])['c']);
    }

    public function test_create_keeps_the_completed_box(): void
    {
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'Done Already', 'summary' => 'Finished before posting.', 'rating_id' => (string) $this->ratingId(),
             'categories' => [(string) $this->categoryId()], 'completed' => '1']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one("SELECT completed FROM stories WHERE title = 'Done Already'")['completed']);
    }

    /** Purge-pinning tests plant cache files here, never in the repo's real
     *  public/cache: a failed run must leave nothing the preview server
     *  could serve as a stale hit. */
    private function cacheDir(): string
    {
        return sys_get_temp_dir() . '/kiption-authoring-cache-' . md5($this->path);
    }

    /** A self-published story changes the authors directory's story counts,
     *  so create purges the cached directory like delete does (basic-review:
     *  the counts otherwise stay stale until an unrelated write). */
    public function test_create_purges_the_authors_directory(): void
    {
        $cacheDir = $this->cacheDir();
        @mkdir($cacheDir . '/browse/authors/d', 0775, true);
        file_put_contents($cacheDir . '/browse/authors/d/index.html', 'stale');
        $res = $this->clientAs(1, ['static_cache' => ['enabled' => true, 'dir' => $cacheDir]])->postWithToken('/story/create',
            ['title' => 'Counted Work', 'summary' => 'S.', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertFileDoesNotExist($cacheDir . '/browse/authors/d/index.html');
    }

    public function test_member_goes_through_queue(): void
    {
        $res = $this->clientAs($this->memberId())->postWithToken('/story/create',
            ['title' => 'Queued Work', 'summary' => 'S.', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT * FROM stories WHERE title = 'Queued Work'");
        $this->assertSame(0, (int) $row['validated']);
        $res = (new TestClient(new App($this->config())))->get('/story/view/queued-work'); // anonymous
        $this->assertSame(404, $res->status);
    }

    public function test_slug_deduplication(): void
    {
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'The Rabbit Hole', 'summary' => 'x', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringEndsWith('/story/edit/the-rabbit-hole-2', $res->headers['Location']);
    }

    public function test_edit_requires_ownership(): void
    {
        $other = $this->memberId();
        $client = $this->clientAs($other);
        $this->assertSame(404, $client->get('/story/edit/the-rabbit-hole')->status);
        $this->assertSame(404, $client->postWithToken('/story/delete/the-rabbit-hole')->status);
        $this->assertNull($this->db->one("SELECT deleted_at FROM stories WHERE slug = 'the-rabbit-hole'")['deleted_at']);
    }

    public function test_update_swaps_categories_and_touches_updated_at(): void
    {
        $before = $this->db->one("SELECT updated_at FROM stories WHERE slug = 'the-rabbit-hole'")['updated_at'];
        sleep(1); // distinct seconds make the timestamp assertion legible
        $res = $this->clientAs(1)->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.',
             'rating_id' => (string) $this->ratingId(), 'categories' => [], 'completed' => '1']);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT completed, updated_at FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(1, (int) $row['completed']);
        $this->assertNotSame($before, $row['updated_at']);
        $this->assertSame(0, (int) $this->db->one(
            'SELECT COUNT(*) c FROM story_categories WHERE story_id = (SELECT id FROM stories WHERE slug = \'the-rabbit-hole\')')['c']);
        // restore the category for later tasks/tests
        $this->db->query('INSERT INTO story_categories (story_id, category_id)
                          SELECT id, ? FROM stories WHERE slug = \'the-rabbit-hole\'', [$this->categoryId()]);
    }

    public function test_saving_an_edit_says_saved_to_the_author(): void
    {
        $c = $this->clientAs(1);
        $res = $c->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.',
             'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('saved=1', (string) ($res->headers['Location'] ?? ''));
        $page = $c->get('/story/edit/the-rabbit-hole', ['saved' => '1']);
        $this->assertStringContainsString('Saved.', $page->body);
        $this->assertStringNotContainsString('Saved.', $c->get('/story/edit/the-rabbit-hole')->body);
    }

    public function test_the_edit_page_prints_saved_only_for_the_plain_saved_code(): void
    {
        $c = $this->clientAs(1);
        foreach (['removed', 'bogus', '<b>x</b>'] as $code) {
            $body = $c->get('/story/edit/the-rabbit-hole', ['saved' => $code])->body;
            $this->assertStringNotContainsString('Saved.', $body, "?saved={$code} says nothing");
            $this->assertStringNotContainsString('<b>x</b>', $body);
        }
    }

    public function test_delete_is_soft_and_purges(): void
    {
        // the purge pins plant their cache files in a configured temp
        // directory: a failed run must leave nothing the preview server
        // could serve as a stale hit (the create-purge test's same rule)
        $cacheDir = $this->cacheDir();
        @mkdir($cacheDir . '/story/view/the-rabbit-hole', 0775, true);
        file_put_contents($cacheDir . '/story/view/the-rabbit-hole/index.html', 'stale');
        $res = $this->clientAs(1, ['static_cache' => ['enabled' => true, 'dir' => $cacheDir]])->postWithToken('/story/delete/the-rabbit-hole');
        $this->assertSame(302, $res->status);
        $this->assertNotNull($this->db->one("SELECT deleted_at FROM stories WHERE slug = 'the-rabbit-hole'")['deleted_at']);
        $this->assertFileDoesNotExist($cacheDir . '/story/view/the-rabbit-hole/index.html');
        // restore for other tests
        $this->db->query("UPDATE stories SET deleted_at = NULL WHERE slug = 'the-rabbit-hole'");
    }

    /** The delete-path cache race: a guest render already in flight when
     *  the purge ran finishes after it and re-stores pre-delete bytes, and
     *  no later write ever purges a deleted story's own files. The delete
     *  defers a second purge that runs after the response is sent (the
     *  front controller's runDeferred), which heals exactly that window.
     *  Drives handle() directly because TestClient drains the deferred
     *  queue itself; the ordering here is production's: purge in the
     *  action, the in-flight render lands, THEN the front controller runs
     *  the queue. */
    public function test_delete_defers_a_repurge_for_the_inflight_render_window(): void
    {
        $cacheDir = $this->cacheDir();
        $req = new \Kip\Http\Request('GET', '/story/view/the-rabbit-hole', [], [], []);
        $plant = fn () => (new \App\StaticCache\Cache($cacheDir))->maybeStore($req, new \Kip\Http\Response('stale bytes', 200));
        $plant();
        $this->assertNotNull((new \App\StaticCache\Cache($cacheDir))->serve($req), 'the stale page serves before the delete');
        $app = new App($this->config(['static_cache' => ['enabled' => true, 'dir' => $cacheDir]]));
        $session = $app->session;
        $session->set('user_id', 1);
        $hash = (string) $this->db->one('SELECT password_hash FROM users WHERE id = 1')['password_hash'];
        $session->set('pwd_epoch', substr($hash, 0, \Kip\Auth::EPOCH_LEN));
        $form = ['_token' => $session->csrfToken()];
        $res = $app->handle(new \Kip\Http\Request('POST', '/story/delete/the-rabbit-hole', [], $form, ['kip_session' => 'x'], '127.0.0.1'));
        $this->assertSame(302, $res->status);
        $this->assertNull((new \App\StaticCache\Cache($cacheDir))->serve($req), 'the immediate purge removed it');
        $plant(); // the in-flight render lands between the purge and the queue drain
        $this->assertNotNull((new \App\StaticCache\Cache($cacheDir))->serve($req), 'the race window exists');
        $app->runDeferred(); // what the front controller does after send()
        $this->assertNull((new \App\StaticCache\Cache($cacheDir))->serve($req), 'the deferred re-purge healed the window');
        // restore for other tests
        $this->db->query("UPDATE stories SET deleted_at = NULL WHERE slug = 'the-rabbit-hole'");
    }

    /** Throwaway story for chapter tests. */
    private function chapterFixture(string $slug): void
    {
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, word_count) VALUES (?, ?, ?, 1, ?, 1, 200)',
            ['Fixture ' . $slug, $slug, 's.', $this->ratingId()]);
        $storyId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 1, \'One\', \'One hundred words pretend.\', 1, 100)', [$storyId]);
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 2, \'Two\', \'Also pretend words here.\', 1, 100)', [$storyId]);
    }

    public function test_chapter_create_appends_and_counts_words(): void
    {
        $this->chapterFixture('ch-create');
        $res = $this->clientAs(1)->postWithToken('/chapter/create/ch-create',
            ['title' => 'Sideways', 'content' => 'Four *short* words.', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $ch = $this->db->one("SELECT * FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-create') AND position = 3");
        $this->assertNotNull($ch);
        $this->assertSame(3, (int) $ch['word_count']); // markers excluded
        $story = $this->db->one("SELECT word_count FROM stories WHERE slug = 'ch-create'");
        $this->assertSame(203, (int) $story['word_count']);
        $this->assertSame(1, (int) $ch['validated']); // validated_author bypass
    }

    public function test_chapter_requires_ownership(): void
    {
        $this->chapterFixture('ch-own');
        $member = $this->clientAs($this->memberId());
        $this->assertSame(404, $member->get('/chapter/new/ch-own')->status);
        // Write actions must fail like the story ones (404), never as unhandled 500s:
        // ownStory() throws RuntimeException on a non-owned or unknown slug.
        $this->assertSame(404, $member->postWithToken('/chapter/create/ch-own',
            ['title' => 'X', 'content' => 'stolen words', 'notes_before' => '', 'notes_after' => ''])->status);
        $this->assertSame(404, $member->postWithToken('/chapter/update/ch-own/1',
            ['title' => 'X', 'content' => 'stolen words', 'notes_before' => '', 'notes_after' => ''])->status);
        $this->assertSame(404, $member->postWithToken('/chapter/delete/ch-own/1')->status);
        $this->assertSame(404, $this->clientAs(1)->postWithToken('/chapter/delete/no-such-story/1')->status);
        $ch = $this->db->one("SELECT title FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-own') AND position = 1");
        $this->assertSame('One', $ch['title']); // untouched
        $this->assertSame(2, (int) $this->db->one(
            "SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-own')")['c']);
    }

    public function test_chapter_update_recounts(): void
    {
        $this->chapterFixture('ch-upd');
        $client = $this->clientAs(1);
        $revision = $this->writerRevision($client->get('/chapter/edit/ch-upd/1')->body);
        $res = $client->postWithToken('/chapter/update/ch-upd/1',
            ['title' => 'One', 'content' => 'Now five whole words here.', 'notes_before' => '', 'notes_after' => '', 'base_revision' => $revision]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('/chapter/edit/ch-upd/1?saved=1', $res->headers['Location']);
        $saved = $this->clientAs(1)->get('/chapter/edit/ch-upd/1', ['saved' => '1']);
        $this->assertSame(200, $saved->status);
        $this->assertStringContainsString('Chapter saved.', $saved->body);
        $ch = $this->db->one("SELECT word_count FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-upd') AND position = 1");
        $this->assertSame(5, (int) $ch['word_count']);
        $story = $this->db->one("SELECT word_count FROM stories WHERE slug = 'ch-upd'");
        $this->assertSame(105, (int) $story['word_count']);
    }

    public function test_chapter_delete_resequences(): void
    {
        $this->chapterFixture('ch-del');
        $res = $this->clientAs(1)->postWithToken('/chapter/delete/ch-del/1');
        $this->assertSame(302, $res->status, $res->body);
        $positions = array_map('intval', array_column($this->db->all(
            "SELECT position FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-del') ORDER BY position"), 'position'));
        $this->assertSame([1], $positions);
        $story = $this->db->one("SELECT word_count FROM stories WHERE slug = 'ch-del'");
        $this->assertSame(100, (int) $story['word_count']);
    }

    public function test_chapter_empty_content_rejected(): void
    {
        $this->chapterFixture('ch-empty');
        $res = $this->clientAs(1)->postWithToken('/chapter/create/ch-empty',
            ['title' => 'X', 'content' => '   ', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(422, $res->status);
        $this->assertSame(2, (int) $this->db->one(
            "SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'ch-empty')")['c']);
        $res = $this->clientAs(1)->postWithToken('/chapter/update/ch-empty/1',
            ['title' => 'Revised Title', 'content' => '   ', 'notes_before' => 'Opening note.', 'notes_after' => 'Ending note.']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Revised Title', $res->body);
        $this->assertStringContainsString('Opening note.', $res->body);
        $this->assertStringContainsString('Ending note.', $res->body);
        $this->assertSame('One hundred words pretend.', $this->db->one(
            "SELECT content FROM chapters WHERE story_id=(SELECT id FROM stories WHERE slug='ch-empty') AND position=1"
        )['content']);
    }

    public function test_invalid_chapter_schedule_keeps_submitted_prose(): void
    {
        $this->chapterFixture('keep-input');
        foreach (['/chapter/update/keep-input/1', '/chapter/create/keep-input'] as $path) {
            foreach (['1', ['1']] as $accessClear) {
                $r = $this->clientAs(1)->postWithToken($path, [
                    'title' => 'My Revised Title', 'content' => "New <script>draft</script>\n\n**Words**",
                    'notes_before' => 'A new opening note.', 'notes_after' => 'A new ending note.',
                    'publish_at' => '2099-13-45T99:99:59', 'access_clear' => $accessClear,
                    'access_password' => 'never-show-this-password',
                ]);
                $this->assertSame(422, $r->status);
                $this->assertStringContainsString('My Revised Title', $r->body);
                $this->assertStringContainsString('New &lt;script&gt;draft&lt;/script&gt;', $r->body);
                $this->assertStringContainsString('**Words**', $r->body);
                $this->assertStringContainsString('A new opening note.', $r->body);
                $this->assertStringContainsString('A new ending note.', $r->body);
                $this->assertStringContainsString('value="2099-13-45T99:99:59"', $r->body);
                $this->assertStringNotContainsString('never-show-this-password', $r->body);
                if ($accessClear === '1') {
                    $this->assertStringContainsString('name="access_clear" value="1" checked', $r->body);
                } else {
                    $this->assertStringNotContainsString('name="access_clear" value="1" checked', $r->body);
                }
            }
        }
        $this->assertSame('One hundred words pretend.', $this->db->one(
            "SELECT content FROM chapters WHERE story_id=(SELECT id FROM stories WHERE slug='keep-input') AND position=1"
        )['content']);
        $this->assertSame(2, (int) $this->db->one(
            "SELECT COUNT(*) c FROM chapters WHERE story_id=(SELECT id FROM stories WHERE slug='keep-input')"
        )['c']);
    }

    public function test_invalid_chapter_update_does_not_expose_another_authors_text(): void
    {
        $this->chapterFixture('private-input');
        $member = $this->clientAs($this->memberId());
        foreach ([['content' => 'Draft', 'publish_at' => 'invalid'], ['content' => '']] as $input) {
            $r = $member->postWithToken('/chapter/update/private-input/1', $input);
            $this->assertSame(404, $r->status);
            $this->assertStringNotContainsString('One hundred words pretend.', $r->body);
        }
    }

    public function test_story_rejects_unknown_rating(): void
    {
        // A forged or stale rating_id must be a 422 form error, never an
        // uncaught FK violation (generic 500).
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'Bad Rating', 'summary' => 'x', 'rating_id' => '9999', 'categories' => []]);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('rating', $res->body);
        $this->assertNull($this->db->one("SELECT id FROM stories WHERE title = 'Bad Rating'"));
        $res = $this->clientAs(1)->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.',
             'rating_id' => '9999', 'categories' => []]);
        $this->assertSame(422, $res->status);
        $rating = (int) $this->db->one("SELECT rating_id FROM stories WHERE slug = 'the-rabbit-hole'")['rating_id'];
        $this->assertSame($this->ratingId(), $rating); // row untouched, still the seeded Teen rating
    }

    public function test_story_drops_unknown_categories(): void
    {
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'Odd Categories', 'summary' => 'x', 'rating_id' => (string) $this->ratingId(), 'categories' => ['8888', (string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT * FROM stories WHERE title = 'Odd Categories'");
        $this->assertSame(1, (int) $this->db->one(
            'SELECT COUNT(*) c FROM story_categories WHERE story_id = ?', [$row['id']])['c']); // only the real id stored
    }
    private function writerRevision(string $body, string $field = 'base_revision'): string
    {
        return \App\Tests\Support\ChapterForm::revision($body, $field);
    }

    public function test_stale_save_requires_explicit_replacement_of_the_shown_version(): void
    {
        $this->chapterFixture('revision-flow');
        $client = $this->clientAs(1);
        $base = $this->writerRevision($client->get('/chapter/edit/revision-flow/1')->body);
        $a = ['title'=>'Title', 'content'=>'Version A', 'base_revision'=>$base];
        $this->assertSame(302, $client->postWithToken('/chapter/update/revision-flow/1', $a)->status);
        $b = ['title'=>'Title', 'content'=>'Version B <script>literal</script>', 'base_revision'=>$base];
        $rejected = $client->postWithToken('/chapter/update/revision-flow/1', $b);
        $this->assertSame(409, $rejected->status);
        $this->assertSame($base, $this->writerRevision($rejected->body));
        $this->assertStringContainsString('Version A', $rejected->body);
        $this->assertStringContainsString('Version B &lt;script&gt;literal&lt;/script&gt;', $rejected->body);
        $shown = $this->writerRevision($rejected->body, 'accept_revision');
        $this->assertNotSame($base, $shown);
        $this->assertSame(409, $client->postWithToken('/chapter/update/revision-flow/1', $b)->status);
        $this->assertSame(302, $client->postWithToken('/chapter/update/revision-flow/1', $b + ['accept_revision'=>$shown])->status);
        $row = $this->db->one('SELECT ch.content FROM chapters ch JOIN stories s ON s.id=ch.story_id WHERE s.slug=? AND ch.position=1', ['revision-flow']);
        $this->assertSame($b['content'], $row['content']);
        $this->assertSame(409, $client->postWithToken('/chapter/update/revision-flow/1', $a + ['accept_revision'=>$shown])->status);
    }

    public function test_http_revision_validation_has_no_missing_or_array_bypass(): void
    {
        $this->chapterFixture('revision-shape');
        $client = $this->clientAs(1);
        foreach ([null, '', 'wrong', ['forged'], strtoupper(str_repeat('a', 64))] as $revision) {
            $post = ['title'=>'Title', 'content'=>'Uncommitted'];
            if ($revision !== null) $post['base_revision'] = $revision;
            $response = $client->postWithToken('/chapter/update/revision-shape/1', $post);
            $this->assertSame(409, $response->status);
            $this->assertStringContainsString('Uncommitted', $response->body);
            $this->assertStringContainsString('name="base_revision" value=""', $response->body);
            $this->assertStringNotContainsString('name="accept_revision"', $response->body);
        }
        $valid = $this->writerRevision($client->get('/chapter/edit/revision-shape/1')->body);
        $this->assertSame(409, $client->postWithToken('/chapter/update/revision-shape/1', [
            'content' => 'Not committed', 'base_revision' => $valid, 'accept_revision' => ['forged'],
        ])->status);
        $this->assertSame(404, $client->postWithToken('/chapter/update/not-a-story/1', ['content'=>'Text'])->status);
    }

    public function test_repository_wal_upgrade_cannot_overwrite_a_newer_writer(): void
    {
        $this->chapterFixture('wal-conflict');
        $this->db->query('PRAGMA journal_mode=WAL');
        $this->db->query('PRAGMA busy_timeout=0');
        $other = new \PDO('sqlite:' . $this->path);
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->exec('PRAGMA busy_timeout=0');
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        $row = $repo->chapterFormData('wal-conflict', 1, 1);
        $revision = \App\ChapterRevision::of($row);
        $ran = false;
        $this->db->onQuery(function (string $sql) use (&$ran, $other): void {
            if (!$ran && preg_match('/^UPDATE chapters SET title/', $sql)) {
                $ran = true;
                $other->exec("UPDATE chapters SET content='Concurrent winner' WHERE story_id=(SELECT id FROM stories WHERE slug='wal-conflict') AND position=1");
            }
        });
        try {
            $repo->updateChapter('wal-conflict', 1, 1, 'Title', 'Stale writer', '', '', null, null, false, $revision);
            $this->fail('The stale read transaction must not overwrite the concurrent writer.');
        } catch (\PDOException $e) {
            $this->assertContains(((int) ($e->errorInfo[1] ?? 0)) & 0xff, [5, 6]);
        } finally {
            $this->db->onQuery(static function (string $sql): void {});
        }
        $this->assertTrue($ran);
        $this->assertSame('Concurrent winner', $this->db->one("SELECT ch.content FROM chapters ch JOIN stories s ON s.id=ch.story_id WHERE s.slug='wal-conflict' AND ch.position=1")['content']);
    }

    public function test_conflict_comparison_uses_the_write_transaction_snapshot(): void
    {
        \App\Tests\Support\QueryTap::on($this->db, static function (string $sql): void {});
        \App\Tests\Support\QueryTap::off($this->db);
        $this->chapterFixture('snapshot-race');
        $base = $this->writerRevision($this->clientAs(1)->get('/chapter/edit/snapshot-race/1')->body);
        $other = new \PDO('sqlite:' . $this->path);
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $armed = false; $changed = false; $formReads = 0;
        \App\Tests\Support\QueryTap::on($this->db, function (string $sql) use (&$armed, &$changed, &$formReads, $other): void {
            if (str_contains($sql, 's.title AS story_title')) { $armed = true; $formReads++; }
            if ($armed && !$changed && str_contains($sql, 'SELECT id, title, author_id, access_hash FROM stories')) {
                $changed = true;
                $other->exec("UPDATE chapters SET content='Won after form read' WHERE story_id=(SELECT id FROM stories WHERE slug='snapshot-race') AND position=1");
            }
        });
        $app = new App($this->config());
        $app->container->instance(Database::class, $this->db);
        $client = (new TestClient($app))->actingAs(1);
        try {
            $response = $client->postWithToken('/chapter/update/snapshot-race/1', ['title'=>'Title', 'content'=>'Losing draft', 'base_revision'=>$base]);
        } finally {
            \App\Tests\Support\QueryTap::off($this->db);
        }
        $this->assertTrue($changed);
        $this->assertSame(1, $formReads, 'redisplay reuses the ownership-qualified form query');
        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('Won after form read', $response->body);
        $current = (new \App\Repositories\AuthoringRepository($this->db))->chapterFormData('snapshot-race', 1, 1);
        $this->assertSame(\App\ChapterRevision::of($current), $this->writerRevision($response->body, 'accept_revision'));
        $this->assertSame($base, $this->writerRevision($response->body));
    }
    public function test_deleted_chapters_and_resequenced_ids_cannot_receive_an_old_save(): void
    {
        $this->chapterFixture('revision-identity');
        $client = $this->clientAs(1);
        $first = $this->writerRevision($client->get('/chapter/edit/revision-identity/1')->body);
        $second = $this->writerRevision($client->get('/chapter/edit/revision-identity/2')->body);
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        $repo->deleteChapter('revision-identity', 1, 1);
        $this->assertSame(409, $client->postWithToken('/chapter/update/revision-identity/1', [
            'title' => 'Old first chapter', 'content' => 'Never replace the second chapter.', 'base_revision' => $first,
        ])->status);
        $this->assertSame(404, $client->get('/chapter/edit/revision-identity/2')->status);
        $this->assertSame(404, $client->postWithToken('/chapter/update/revision-identity/2', [
            'content' => 'Deleted chapter draft', 'base_revision' => $second,
        ])->status);
        $this->assertSame('Also pretend words here.', $this->db->one(
            "SELECT content FROM chapters WHERE story_id=(SELECT id FROM stories WHERE slug='revision-identity') AND position=1"
        )['content']);
    }

    public function test_a_coauthor_uses_the_same_revision_protection(): void
    {
        $this->chapterFixture('coauthor-revision');
        $coauthor = $this->memberId();
        $this->db->query('INSERT INTO coauthors (story_id, user_id) VALUES ((SELECT id FROM stories WHERE slug=?), ?)', ['coauthor-revision', $coauthor]);
        $client = $this->clientAs($coauthor);
        $base = $this->writerRevision($client->get('/chapter/edit/coauthor-revision/1')->body);
        $post = ['title' => 'Coauthored', 'content' => 'Agreed draft.', 'base_revision' => $base];
        $this->assertSame(302, $client->postWithToken('/chapter/update/coauthor-revision/1', $post)->status);
        $this->assertSame(409, $client->postWithToken('/chapter/update/coauthor-revision/1', $post)->status);
    }

    public function test_form_recovery_protection_covers_story_and_chapter_without_exposing_hashes(): void
    {
        $this->chapterFixture('protected-revision');
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        foreach ([[null, null, 0], ['story-private-hash', null, 1], [null, 'chapter-private-hash', 1], ['story-private-hash', 'chapter-private-hash', 1]] as [$storyHash, $chapterHash, $protected]) {
            $this->db->query('UPDATE stories SET access_hash=? WHERE slug=?', [$storyHash, 'protected-revision']);
            $this->db->query('UPDATE chapters SET access_hash=? WHERE story_id=(SELECT id FROM stories WHERE slug=?) AND position=1', [$chapterHash, 'protected-revision']);
            $this->assertSame($protected, (int) $repo->chapterFormData('protected-revision', 1, 1)['recovery_protected']);
            $html = $this->clientAs(1)->get('/chapter/edit/protected-revision/1')->body;
            $this->assertStringNotContainsString('story-private-hash', $html);
            $this->assertStringNotContainsString('chapter-private-hash', $html);
            $this->assertNotSame('', $this->writerRevision($html));
        }
    }

    public function test_busy_writer_preserves_input_and_returns_a_retryable_response(): void
    {
        \App\Tests\Support\QueryTap::on($this->db, static function (string $sql): void {});
        \App\Tests\Support\QueryTap::off($this->db);
        $this->db->exec('PRAGMA busy_timeout=0');
        $this->chapterFixture('busy-save');
        $base = $this->writerRevision($this->clientAs(1)->get('/chapter/edit/busy-save/1')->body);
        $other = new \PDO('sqlite:' . $this->path);
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->exec('PRAGMA busy_timeout=0');
        $held = false;
        \App\Tests\Support\QueryTap::on($this->db, function (string $sql) use (&$held, $other): void {
            if (!$held && str_starts_with($sql, 'UPDATE chapters SET title')) {
                $other->exec('BEGIN IMMEDIATE');
                $held = true;
            }
        });
        $app = new App($this->config());
        $app->container->instance(Database::class, $this->db);
        try {
            $response = (new TestClient($app))->actingAs(1)->postWithToken('/chapter/update/busy-save/1', [
                'title' => 'Retained Title', 'content' => 'Unsaved <draft>', 'notes_before' => 'Opening note.',
                'notes_after' => 'Ending note.', 'base_revision' => $base,
            ]);
        } finally {
            \App\Tests\Support\QueryTap::off($this->db);
            if ($held) $other->exec('ROLLBACK');
        }
        $this->assertTrue($held);
        $this->assertSame(503, $response->status);
        $this->assertSame('1', $response->headers['Retry-After']);
        foreach (['Retained Title', 'Unsaved &lt;draft&gt;', 'Opening note.', 'Ending note.'] as $retained) {
            $this->assertStringContainsString($retained, $response->body);
        }
        $this->assertSame($base, $this->writerRevision($response->body));
        $this->assertSame(0, $this->db->transactionDepth());
        $this->assertSame('One hundred words pretend.', $this->db->one(
            "SELECT content FROM chapters WHERE story_id=(SELECT id FROM stories WHERE slug='busy-save') AND position=1"
        )['content']);
    }

    public function test_extended_busy_codes_retry_but_other_database_errors_do_not_masquerade_as_missing_chapters(): void
    {
        \App\Tests\Support\QueryTap::on($this->db, static function (string $sql): void {});
        \App\Tests\Support\QueryTap::off($this->db);
        $this->chapterFixture('error-classification');
        $base = $this->writerRevision($this->clientAs(1)->get('/chapter/edit/error-classification/1')->body);
        foreach ([517 => 503, 6 => 503, 1 => 500] as $code => $expected) {
            \App\Tests\Support\QueryTap::on($this->db, static function (string $sql) use ($code): void {
                if (!str_starts_with($sql, 'UPDATE chapters SET title')) return;
                $e = new \PDOException('test write failure');
                $e->errorInfo = ['HY000', $code, 'test write failure'];
                throw $e;
            });
            $app = new App($this->config());
            $app->container->instance(Database::class, $this->db);
            try {
                $response = (new TestClient($app))->actingAs(1)->postWithToken('/chapter/update/error-classification/1', [
                    'title' => 'Title', 'content' => 'Retained draft', 'base_revision' => $base,
                ]);
            } finally {
                \App\Tests\Support\QueryTap::off($this->db);
            }
            $this->assertSame($expected, $response->status, (string) $code);
            $this->assertSame(0, $this->db->transactionDepth());
            if ($expected === 503) $this->assertStringContainsString('Retained draft', $response->body);
            else $this->assertStringNotContainsString('test write failure', $response->body);
        }
    }
    public function test_internal_updates_keep_optional_revision_compatibility_and_reject_missing_rows(): void
    {
        $this->chapterFixture('internal-revision');
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        $repo->updateChapter('internal-revision', 1, 1, 'Internal', 'Internal caller text.', '', '');
        $this->assertSame('Internal caller text.', $repo->chapterFormData('internal-revision', 1, 1)['content']);
        try {
            $repo->updateChapter('internal-revision', 99, 1, 'Missing', 'Never saved.', '', '');
            $this->fail('A missing chapter cannot report a successful update.');
        } catch (\RuntimeException $e) {
            $this->assertSame('not found', $e->getMessage());
        }
        $this->assertSame(0, $this->db->transactionDepth());
    }
}
