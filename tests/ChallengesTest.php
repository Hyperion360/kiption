<?php // tests/ChallengesTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Task 2: the challenge CRUD surface with prompt management (ListsTest
 * idiom, the ListsRepository method-for-method mirror). Task 3 appends the
 * public page, membership, and notification tests here.
 *
 * Features::init/reset discipline: the class inits in setUp and resets in
 * tearDown so no later suite inherits a memo pointing at this unlinking
 * temp DB. */
final class ChallengesTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-chal-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
        \App\Features::init($this->db, []);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function newApp(): App
    {
        return new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-chal-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-chal-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    /** The factory keeps the App instance as $this->app: TestClient drives
     *  App::handle directly, which bypasses the static cache entirely (the
     *  SeriesTest finding); drive $this->app->handle(new Request(...)) when a
     *  request needs explicit cookies or a cache roundtrip. */
    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    public function test_crud_prompts_and_gates(): void
    {
        $me = $this->client($this->memberId());
        $this->assertSame(302, $me->postWithToken('/challenges/create',
            ['title' => 'First Line Challenge', 'summary' => 'Open a story with this line.', 'membership' => 'open'])->status);
        $db = $this->db();
        $this->assertNotNull($db->one("SELECT * FROM challenges WHERE slug = 'first-line-challenge' AND membership = 'open'"));
        // junk membership 422; title required; hidden/guest gates
        $this->assertSame(422, $me->postWithToken('/challenges/create', ['title' => 'X', 'summary' => '', 'membership' => 'sneaky'])->status);
        $this->assertSame(422, $me->postWithToken('/challenges/create', ['title' => '', 'summary' => '', 'membership' => 'open'])->status);
        $this->assertSame(302, $this->client()->get('/challenges/new')->status, 'auth redirect');
        $this->assertSame(403, $this->client($this->authorId())->post('/challenges/create', ['title' => 'X', 'summary' => '', 'membership' => 'open'])->status, 'CSRF-first');
        $this->assertSame(404, $this->client($this->authorId())->postWithToken('/challenges/update/first-line-challenge', ['title' => 'X', 'summary' => '', 'membership' => 'open'])->status, 'ownership with a valid token');
        // prompts: add, list on the edit form, remove
        $this->assertSame(302, $me->postWithToken('/challenges/prompt/first-line-challenge', ['prompt_text' => 'It was the best of times, approximately.'])->status);
        $this->assertSame(302, $me->postWithToken('/challenges/prompt/first-line-challenge', ['prompt_text' => 'Call me whenever.'])->status);
        $this->assertSame(2, (int) $db->one("SELECT COUNT(*) c FROM challenge_prompts")['c']);
        $this->assertSame(422, $me->postWithToken('/challenges/prompt/first-line-challenge', ['prompt_text' => ''])->status);
    }

    public function test_edit_form_lists_prompts_with_move_and_remove(): void
    {
        $me = $this->client($this->memberId());
        $me->postWithToken('/challenges/create', ['title' => 'Prompt Bowl', 'summary' => '', 'membership' => 'moderated']);
        $me->postWithToken('/challenges/prompt/prompt-bowl', ['prompt_text' => 'First.']);
        $me->postWithToken('/challenges/prompt/prompt-bowl', ['prompt_text' => 'Second.']);
        $me->postWithToken('/challenges/prompt/prompt-bowl', ['prompt_text' => 'Third.']);
        $db = $this->db();

        // the owner's edit form lists every prompt with its management forms
        $body = $me->get('/challenges/edit/prompt-bowl')->body;
        $this->assertStringContainsString('First.', $body);
        $this->assertStringContainsString('Third.', $body);
        $this->assertStringContainsString('/challenges/promptremove/prompt-bowl/', $body);
        $this->assertStringContainsString('/challenges/promptmove/prompt-bowl/', $body);
        // a stranger never sees the form (own()'s 404, not 403)
        $this->assertSame(404, $this->client($this->authorId())->get('/challenges/edit/prompt-bowl')->status);

        // the swap: moving the third prompt up trades positions with the second
        $ids = array_map('intval', array_column($db->all("SELECT id FROM challenge_prompts ORDER BY position"), 'id'));
        $this->assertSame(302, $me->postWithToken("/challenges/promptmove/prompt-bowl/{$ids[2]}/up", [])->status);
        $texts = array_column($db->all("SELECT prompt_text FROM challenge_prompts ORDER BY position"), 'prompt_text');
        $this->assertSame(['First.', 'Third.', 'Second.'], $texts);
        // junk direction coerces, a boundary move is a calm no-op, unknown prompt 404s
        $this->assertSame(302, $me->postWithToken("/challenges/promptmove/prompt-bowl/{$ids[2]}/sideways", [])->status);
        $this->assertSame(302, $me->postWithToken("/challenges/promptmove/prompt-bowl/{$ids[2]}/up", [])->status, 'top-of-list move up is a no-op');
        $this->assertSame(404, $me->postWithToken('/challenges/promptmove/prompt-bowl/999999/up', [])->status);

        // remove drops the row; every prompt op is owner-gated
        $this->assertSame(302, $me->postWithToken("/challenges/promptremove/prompt-bowl/{$ids[1]}", [])->status);
        $this->assertSame(2, (int) $db->one("SELECT COUNT(*) c FROM challenge_prompts")['c']);
        $this->assertSame(404, $me->postWithToken('/challenges/promptremove/prompt-bowl/999999', [])->status);
        $author = $this->client($this->authorId());
        $this->assertSame(404, $author->postWithToken('/challenges/prompt/prompt-bowl', ['prompt_text' => 'Not mine.'])->status);
        $this->assertSame(404, $author->postWithToken("/challenges/promptremove/prompt-bowl/{$ids[0]}", [])->status);
        $this->assertSame(404, $author->postWithToken("/challenges/promptmove/prompt-bowl/{$ids[0]}/down", [])->status);
        $this->assertSame(404, $author->postWithToken('/challenges/prompt/ghost-challenge', ['prompt_text' => 'X.'])->status, 'unknown slug');
    }

    public function test_update_delete_and_clamps(): void
    {
        $me = $this->client($this->memberId());
        $me->postWithToken('/challenges/create', ['title' => 'Rewrite Rondeau', 'summary' => str_repeat('s', 2500), 'membership' => 'closed']);
        $db = $this->db();
        // summary clamped to 2000 at rest
        $this->assertSame(2000, strlen((string) $db->one("SELECT summary FROM challenges WHERE slug = 'rewrite-rondeau'")['summary']));

        // update round-trips title and membership
        $this->assertSame(302, $me->postWithToken('/challenges/update/rewrite-rondeau',
            ['title' => 'Rondeau Redux', 'summary' => 'Again.', 'membership' => 'moderated'])->status);
        $row = $db->one("SELECT title, membership FROM challenges WHERE slug = 'rewrite-rondeau'");
        $this->assertSame('Rondeau Redux', $row['title']);
        $this->assertSame('moderated', $row['membership']);
        $this->assertStringContainsString('Rondeau Redux', $me->get('/challenges/edit/rewrite-rondeau')->body);
        // an over-long title 422s like the series form
        $this->assertSame(422, $me->postWithToken('/challenges/update/rewrite-rondeau',
            ['title' => str_repeat('T', 121), 'summary' => '', 'membership' => 'open'])->status);
        $this->assertSame('Rondeau Redux', $db->one("SELECT title FROM challenges WHERE slug = 'rewrite-rondeau'")['title']);

        // delete: prompts ride the FK cascade; strangers 404 first
        $me->postWithToken('/challenges/prompt/rewrite-rondeau', ['prompt_text' => 'Seventeen syllables exactly.']);
        $this->assertSame(404, $this->client($this->authorId())->postWithToken('/challenges/delete/rewrite-rondeau', [])->status);
        $this->assertSame(302, $me->postWithToken('/challenges/delete/rewrite-rondeau', [])->status);
        $this->assertNull($db->one("SELECT * FROM challenges WHERE slug = 'rewrite-rondeau'"));
        $this->assertSame(0, (int) $db->one("SELECT COUNT(*) c FROM challenge_prompts")['c']);
        $this->assertSame(404, $me->postWithToken('/challenges/delete/ghost-challenge', [])->status);
    }

    public function test_flag_off_404s_the_module(): void
    {
        $me = $this->client($this->memberId());
        $this->assertSame(302, $me->postWithToken('/challenges/create', ['title' => 'Before Off', 'summary' => '', 'membership' => 'open'])->status);
        \App\Features::toggle('challenges', false);
        $this->assertSame(404, $me->get('/challenges/new')->status);
        $this->assertSame(404, $me->get('/challenges/edit/before-off')->status);
        $this->assertSame(404, $me->postWithToken('/challenges/create', ['title' => 'X', 'summary' => '', 'membership' => 'open'])->status);
        $this->assertSame(404, $me->postWithToken('/challenges/prompt/before-off', ['prompt_text' => 'X.'])->status);
    }
}
