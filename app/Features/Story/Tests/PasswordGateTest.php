<?php // app/Features/Story/Tests/PasswordGateTest.php
namespace App\Features\Story\Tests;

use App\StaticCache\Builder;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Storage;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The post-password gate (Fictioneer parity): access_hash on story and
 *  chapter rows, checked against a session unlock list. The gate rides the
 *  rows the read path already loads (zero added queries), the pass form is
 *  the crawler's view of a locked work, and the prose side-channels (full-
 *  text feeds, search) withhold locked prose while the identity stays
 *  listed. */
final class PasswordGateTest extends TestCase
{
    private string $path = '';
    private string $uploads = '';
    private string $cacheDir = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-gate-') . '.sqlite';
        $this->uploads = sys_get_temp_dir() . '/kiption-gate-upl-' . bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir() . '/kiption-gate-cache-' . bin2hex(random_bytes(4));
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        foreach (glob($this->uploads . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->uploads);
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-gate-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->uploads],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides);
    }

    private function client(array $overrides = []): TestClient
    {
        $app = new App($this->config($overrides));
        $app->container->instance(Storage::class,
            new Storage($this->uploads, 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn(string $t, string $d): bool => copy($t, $d)));
        return new TestClient($app);
    }

    private function lockStory(): void
    {
        $this->db->query('UPDATE stories SET access_hash = ? WHERE slug = ?', [password_hash('pw', PASSWORD_DEFAULT), 'the-rabbit-hole']);
    }

    private function storyId(): int
    {
        return (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
    }

    public function test_locked_story_shows_the_form_and_never_the_toc(): void
    {
        $this->lockStory();
        $res = $this->client()->get('/story/view/the-rabbit-hole');
        $this->assertSame(200, $res->status, $res->body);
        // the gate carries identity (title, byline) and the form, never the TOC links
        $this->assertStringContainsString('name="password"', $res->body);
        $this->assertStringNotContainsString('/story/read/the-rabbit-hole/1', $res->body);
        $this->assertStringContainsString('<form method="post" action="/reader/unlock"', $res->body);
        // noindex: the gate page IS the crawler's view and must not rank
        $this->assertStringContainsString('noindex', $res->body);
    }

    public function test_locked_read_page_carries_noindex(): void
    {
        $this->lockStory();
        $res = $this->client()->get('/story/read/the-rabbit-hole/1');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<meta name="robots" content="noindex"', $res->body);
    }

    public function test_unlock_then_read_serves_the_chapter_list(): void
    {
        $this->lockStory();
        $c = $this->client();
        $id = $this->storyId();
        $res = $c->post('/reader/unlock', ['table' => 'story', 'id' => (string) $id, 'password' => 'pw', 'return_to' => '/story/view/the-rabbit-hole']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/story/view/the-rabbit-hole', (string) $res->headers['Location']);
        // TestClient re-presents the session cookie a started session issued
        // ("a client whose session holds state sends a cookie"); if a
        // framework change breaks that, drive this flow through actingAs()
        // instead: the gate consults the session either way.
        $res = $c->get('/story/view/the-rabbit-hole');
        $this->assertStringNotContainsString('name="password"', $res->body);
        $this->assertStringContainsString('/story/read/the-rabbit-hole/1', $res->body, 'the TOC is back');
    }

    public function test_unlock_works_for_a_logged_in_member_too(): void
    {
        $this->lockStory();
        $demo = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $c = $this->client();
        $c->actingAs($demo);
        $id = $this->storyId();
        $c->post('/reader/unlock', ['table' => 'story', 'id' => (string) $id, 'password' => 'pw', 'return_to' => '/']);
        $res = $c->get('/story/view/the-rabbit-hole');
        $this->assertStringNotContainsString('name="password"', $res->body);
    }

    public function test_wrong_password_stays_locked(): void
    {
        $this->lockStory();
        $c = $this->client();
        $id = $this->storyId();
        $res = $c->post('/reader/unlock', ['table' => 'story', 'id' => (string) $id, 'password' => 'nope', 'return_to' => '/story/read/the-rabbit-hole/1']);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('gate=wrong', (string) $res->headers['Location']);
        $this->assertStringContainsString('name="password"', $c->get('/story/read/the-rabbit-hole/1')->body);
    }

    public function test_unlock_rejects_bad_shapes(): void
    {
        $this->lockStory();
        foreach ([['table' => 'user', 'id' => '1', 'password' => 'pw'],
                  ['table' => 'story', 'id' => '0', 'password' => 'pw'],
                  ['table' => 'story', 'id' => '1', 'password' => '']] as $bad) {
            $c = $this->client();
            $this->assertSame(302, $c->post('/reader/unlock', $bad + ['return_to' => '/story/view/the-rabbit-hole'])->status,
                'a bad shape redirects, never errors');
            $this->assertStringContainsString('name="password"', $c->get('/story/view/the-rabbit-hole')->body, 'still locked');
        }
        $this->assertSame('2', (string) $this->db->one('SELECT COUNT(*) c FROM users')['c'], 'no users rows were touched');
    }

    public function test_chapter_level_gate_applies_to_fragments(): void
    {
        $this->db->query('UPDATE chapters SET access_hash = ? WHERE story_id = 1 AND position = 1', [password_hash('pw', PASSWORD_DEFAULT)]);
        $res = $this->client()->get('/story/fragment/the-rabbit-hole/1');
        $this->assertSame(404, $res->status, 'a fragment never renders prose nor the gate page');
    }

    public function test_chapter_level_gate_hides_one_prose_not_the_toc(): void
    {
        $this->db->query('UPDATE chapters SET access_hash = ? WHERE story_id = 1 AND position = 1', [password_hash('pw', PASSWORD_DEFAULT)]);
        $c = $this->client();
        // The locked chapter's read page answers the pass form...
        $this->assertStringContainsString('name="password"', $c->get('/story/read/the-rabbit-hole/1')->body);
        // ...its prose is gone from every surface, but the entry stays listed
        // (the gate's crawler-visible identity) and the open chapters read on.
        $whole = $c->get('/story/whole/the-rabbit-hole');
        $this->assertStringNotContainsString('shelves of nothing', $whole->body, 'the whole view withholds the locked chapter');
        $this->assertStringContainsString('garden is wrong', $whole->body, 'an open chapter of the same story still reads');
        $this->assertStringContainsString('/story/read/the-rabbit-hole/1', $c->get('/story/view/the-rabbit-hole')->body, 'the TOC entry stays');
        // The unlock rides the chapter's own row id, not the position.
        $ch1 = (int) $this->db->one("SELECT id FROM chapters WHERE story_id = 1 AND position = 1")['id'];
        $res = $c->post('/reader/unlock', ['table' => 'chapter', 'id' => (string) $ch1, 'password' => 'pw', 'return_to' => '/story/read/the-rabbit-hole/1']);
        $this->assertSame(302, $res->status);
        $this->assertStringNotContainsString('gate=wrong', (string) $res->headers['Location']);
        $this->assertStringContainsString('shelves of nothing', $c->get('/story/read/the-rabbit-hole/1')->body, 'the chapter reads after its own unlock');
    }

    public function test_locked_prose_never_rides_the_full_text_feed(): void
    {
        // feeds_full_text is a config flag (FeedController::fullText), not a
        // query param: the full-text variant is what can leak prose.
        $this->lockStory();
        foreach (['/feed', '/feed/author/demo-author', '/feed/category/general'] as $uri) {
            $res = $this->client(['feeds_full_text' => true])->get($uri);
            $this->assertSame(200, $res->status, $uri);
            $this->assertStringNotContainsString('shelves of nothing', $res->body, "$uri withheld the locked prose");
            $this->assertStringContainsString('the-rabbit-hole', $res->body, "$uri keeps the entry listed, prose withheld");
        }
    }

    public function test_locked_story_downloads_render_the_gate_not_the_prose(): void
    {
        $this->lockStory();
        $c = $this->client();
        foreach (['/story/whole/the-rabbit-hole', '/story/download/the-rabbit-hole/html', '/story/download/the-rabbit-hole/epub'] as $path) {
            $res = $c->get($path);
            $this->assertSame(200, $res->status, $path);
            $this->assertStringContainsString('name="password"', $res->body, "$path answers with the gate");
            $this->assertStringNotContainsString('shelves of nothing', $res->body, "$path never carries prose");
            $type = (string) ($res->headers['Content-Type'] ?? '');
            $this->assertStringNotContainsString('application/epub', $type, "$path never streams a file");
            $this->assertStringNotContainsString('attachment', (string) ($res->headers['Content-Disposition'] ?? ''), "$path never offers a download");
        }
    }

    public function test_locked_prose_never_matches_search(): void
    {
        // Story-level: the work leaves the search results entirely; the
        // chapter-level lock blanks only the locked chapter's arm.
        $this->lockStory();
        $c = $this->client();
        $this->assertStringNotContainsString('the-rabbit-hole', $c->get('/search', ['q' => 'shelves'])->body);
        $this->assertStringNotContainsString('the-rabbit-hole', $c->get('/search', ['q' => 'Rabbit'])->body, 'a locked work is not found by title either');
        $this->db->query('UPDATE stories SET access_hash = NULL WHERE slug = ?', ['the-rabbit-hole']);
        $this->db->query('UPDATE chapters SET access_hash = ? WHERE story_id = 1 AND position = 1', [password_hash('pw', PASSWORD_DEFAULT)]);
        $this->assertStringNotContainsString('the-rabbit-hole', $c->get('/search', ['q' => 'shelves'])->body, 'a locked chapter never makes the story match');
        $this->assertStringContainsString('the-rabbit-hole', $c->get('/search', ['q' => 'garden'])->body, 'an open chapter still matches');
    }

    public function test_author_save_sets_and_clears_the_story_password(): void
    {
        $demo = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $c = $this->client();
        $c->actingAs($demo);
        $this->assertSame(302, $c->postWithToken('/story/update/the-rabbit-hole', [
            'title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.', 'rating_id' => '2',
            'access_password' => 'pw',
        ])->status);
        $hash = $this->db->one("SELECT access_hash h FROM stories WHERE slug = 'the-rabbit-hole'")['h'];
        $this->assertIsString($hash);
        $this->assertNotSame('pw', $hash, 'the password is stored hashed, never raw');
        $this->assertTrue(password_verify('pw', (string) $hash));
        $this->assertStringContainsString('name="password"', $this->client()->get('/story/view/the-rabbit-hole')->body, 'guests meet the gate');
        // An empty password leaves the lock alone; the clear checkbox removes it.
        $this->assertSame(302, $c->postWithToken('/story/update/the-rabbit-hole', [
            'title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.', 'rating_id' => '2',
        ])->status);
        $this->assertNotNull($this->db->one("SELECT access_hash h FROM stories WHERE slug = 'the-rabbit-hole'")['h'], 'an empty field never clears the lock');
        $this->assertSame(302, $c->postWithToken('/story/update/the-rabbit-hole', [
            'title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.', 'rating_id' => '2',
            'access_clear' => '1',
        ])->status);
        $this->assertNull($this->db->one("SELECT access_hash h FROM stories WHERE slug = 'the-rabbit-hole'")['h']);
        $this->assertStringContainsString('/story/read/the-rabbit-hole/1', $this->client()->get('/story/view/the-rabbit-hole')->body, 'the TOC is back');
    }

    public function test_author_save_sets_and_clears_the_chapter_password(): void
    {
        $demo = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $c = $this->client();
        $c->actingAs($demo);
        $content = "Falling *down* the hole, past shelves of nothing.";
        $this->assertSame(302, $c->postWithToken('/chapter/update/the-rabbit-hole/1', [
            'title' => 'Down', 'content' => $content, 'access_password' => 'pw',
        ])->status);
        $hash = $this->db->one("SELECT access_hash h FROM chapters WHERE story_id = 1 AND position = 1")['h'];
        $this->assertIsString($hash);
        $this->assertTrue(password_verify('pw', (string) $hash));
        $this->assertStringContainsString('name="password"', $this->client()->get('/story/read/the-rabbit-hole/1')->body);
        $this->assertSame(302, $c->postWithToken('/chapter/update/the-rabbit-hole/1', [
            'title' => 'Down', 'content' => $content, 'access_clear' => '1',
        ])->status);
        $this->assertNull($this->db->one("SELECT access_hash h FROM chapters WHERE story_id = 1 AND position = 1")['h']);
        $this->assertStringContainsString('shelves of nothing', $this->client()->get('/story/read/the-rabbit-hole/1')->body);
    }

    public function test_locking_a_story_evicts_the_cached_public_page(): void
    {
        // The NULL-to-set transition is the delete path's forever-stale race:
        // pre-lock guest bytes must not stand after the save (the deferred
        // re-purge rider heals renders still in flight).
        $config = $this->config(['static_cache' => ['dir' => $this->cacheDir]]);
        Builder::build($config, $this->cacheDir);
        $file = $this->cacheDir . '/story/view/the-rabbit-hole/index.html';
        $this->assertFileExists($file);
        $readFile = $this->cacheDir . '/story/read/the-rabbit-hole/1/index.html';
        $this->assertFileExists($readFile);
        $demo = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $c = new TestClient(new App($config));
        $c->actingAs($demo);
        $this->assertSame(302, $c->postWithToken('/story/update/the-rabbit-hole', [
            'title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.', 'rating_id' => '2',
            'access_password' => 'pw',
        ])->status);
        $this->assertFileDoesNotExist($file, 'the cached public TOC is gone');
        $this->assertFileDoesNotExist($readFile, 'the cached public prose is gone');
    }
}
