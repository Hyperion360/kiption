<?php // tests/NewsTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class NewsTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private ?App $app = null;
    private int $adminUserId = 0;
    private int $memberUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-news-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-news-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db); // plants the Welcome news row (id 1)
        // admin + member fixtures, the SeriesRepositoryTest idiom
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('newsadmin@e.test', ?, 'newsadmin', 'admin', 1, ?, ?, 'newsadmin')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('newsmember@e.test', ?, 'newsmember', ?, ?, 'newsmember')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->memberUserId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
        exec('rm -rf ' . escapeshellarg(dirname(__DIR__) . '/public/cache/news'));
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function navFile(): string
    {
        return $this->root . '/nav.json';
    }

    private function adminId(): int
    {
        return $this->adminUserId;
    }

    private function memberId(): int
    {
        return $this->memberUserId;
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->navFile(),
        ]);
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_news_index_and_view_with_comments(): void
    {
        $idx = $this->client()->get('/news');
        $this->assertSame(200, $idx->status);
        $this->assertStringContainsString('Welcome', $idx->body);
        $this->assertStringContainsString('href="/news/view/1"', $idx->body, 'listing links the item');
        $view = $this->client()->get('/news/view/1');
        $this->assertSame(200, $view->status);
        $this->assertStringContainsString('First post.', $view->body);
        $this->assertStringContainsString('Comments (0)', $view->body);
        // guests see no comment form (member-only surface); members do
        $this->assertStringNotContainsString('action="/news/comment/1"', $view->body);
        // member comments; guest and flood barred
        $member = $this->client($this->memberId());
        $memberView = $member->get('/news/view/1');
        $this->assertStringContainsString('action="/news/comment/1"', $memberView->body);
        $this->assertSame(302, $member->postWithToken('/news/comment/1', ['body' => 'Nice!'])->status);
        $this->assertStringContainsString('Nice!', $this->client()->get('/news/view/1')->body);
        $this->assertStringContainsString('1 comment', $this->client()->get('/news')->body, 'the index comment-count scalar moved');
        $this->assertSame(422, $member->postWithToken('/news/comment/1', ['body' => '   '])->status, 'empty body rejected');
        // gate before CSRF since kip b29e269: a guest gets the login redirect
        // whatever the token, so the status no longer confirms the route exists
        $this->assertSame(302, $this->client()->post('/news/comment/1', ['body' => 'x'])->status, 'guest tokenless POST gets the login redirect');
        $this->assertSame(1, (int) $this->db()->one('SELECT COUNT(*) c FROM news_comments WHERE news_id = 1')['c'], 'the guest POST wrote no comment');
        $this->assertSame(429, $member->postWithToken('/news/comment/1', ['body' => 'again'])->status, 'one comment per member per item per hour');
        $this->assertSame(429, $member->postWithToken('/news/comment/1', ['body' => 'flood'])->status);
        // junk and unknown ids 404
        $this->assertSame(404, $this->client()->get('/news/view/0')->status);
        $this->assertSame(404, $this->client()->get('/news/view/1abc')->status);
        $this->assertSame(404, $this->client()->get('/news/view/99999')->status);
        $this->assertSame(404, $member->postWithToken('/news/comment/99999', ['body' => 'x'])->status);
    }

    public function test_news_admin_forms_validate_and_purge(): void
    {
        $db = $this->db();
        $admin = $this->client($this->adminId());
        $this->assertSame(200, $admin->get('/news/new')->status);
        $this->assertSame(302, $admin->postWithToken('/news/create', ['title' => 'Second', 'body' => 'More *news*.'])->status);
        $id = (int) $db->one("SELECT id FROM news WHERE title = 'Second'")['id'];
        $this->assertStringContainsString('Second', $this->client()->get('/news')->body, 'the new post headlines the index');
        $this->assertStringContainsString('<em>news</em>', $this->client()->get('/news/view/' . $id)->body, 'markdown body renders on the item page');
        $edit = $admin->get('/news/edit/' . $id);
        $this->assertSame(200, $edit->status);
        $this->assertStringContainsString('More *news*.', $edit->body, 'edit prefills the stored markdown');
        // shape rejects: title bounds and empty body
        $this->assertSame(422, $admin->postWithToken('/news/create', ['title' => '', 'body' => 'x'])->status);
        $this->assertSame(422, $admin->postWithToken('/news/create', ['title' => str_repeat('x', 256), 'body' => 'x'])->status);
        $this->assertSame(422, $admin->postWithToken('/news/create', ['title' => 'X', 'body' => ''])->status);
        // update rewrites and purges both surfaces
        $file = dirname(__DIR__) . '/public/cache/news/index.html';
        $item = dirname(__DIR__) . '/public/cache/news/view/1/index.html';
        foreach ([$file, $item] as $f) { @mkdir(dirname($f), 0775, true); file_put_contents($f, 'stale'); }
        $this->assertSame(302, $admin->postWithToken('/news/update/1', ['title' => 'Welcome!', 'body' => 'Edited.'])->status);
        $this->assertFileDoesNotExist($file, 'update unlinked the /news cache file');
        $this->assertFileDoesNotExist($item, 'update unlinked the item cache file');
        $fresh = $this->client()->get('/news/view/1');
        $this->assertSame(200, $fresh->status);
        $this->assertStringContainsString('Edited.', $fresh->body);
        $this->assertSame('Welcome!', $db->one('SELECT title FROM news WHERE id = 1')['title']);
        // gates: guest auth-redirects; members barred even with a valid token
        $this->assertSame(302, $this->client()->get('/news/new')->status, 'auth redirect');
        $this->assertSame(403, $this->client($this->memberId())->get('/news/new')->status);
        $this->assertSame(403, $this->client($this->memberId())->postWithToken('/news/create', ['title' => 'X', 'body' => 'x'])->status);
        // unknown ids 404 on the admin surface too
        $this->assertSame(404, $admin->get('/news/edit/99999')->status);
        $this->assertSame(404, $admin->postWithToken('/news/update/99999', ['title' => 'X', 'body' => 'x'])->status);
    }

    public function test_news_comment_purges_item_and_index_cache(): void
    {
        // A comment changes BOTH surfaces: the item's comment list and the
        // index's comment-count scalar; both files must go.
        $file = dirname(__DIR__) . '/public/cache/news/index.html';
        $item = dirname(__DIR__) . '/public/cache/news/view/1/index.html';
        foreach ([$file, $item] as $f) { @mkdir(dirname($f), 0775, true); file_put_contents($f, 'stale'); }
        $member = $this->client($this->memberId());
        $this->assertSame(302, $member->postWithToken('/news/comment/1', ['body' => 'Nice!'])->status);
        $this->assertFileDoesNotExist($item, 'comment unlinked the item cache file');
        $this->assertFileDoesNotExist($file, 'comment unlinked the /news cache file (the count moved)');
    }

    public function test_news_surfaces_are_static_cacheable(): void
    {
        $this->client(); // boots $this->app
        $dir = sys_get_temp_dir() . '/kiption-newscache-' . uniqid('', true);
        $cache = new \App\StaticCache\Cache($dir);
        foreach (['/news', '/news/view/1'] as $p) {
            $req = new \Kip\Http\Request('GET', $p, [], [], []);
            $cache->maybeStore($req, $this->app->handle($req));
            $this->assertNotNull($cache->serve($req), "{$p} filled the static layer");
        }
        // admin auth surfaces never enter the whitelist
        $req = new \Kip\Http\Request('GET', '/news/new', [], [], []);
        $cache->maybeStore($req, $this->app->handle($req));
        $this->assertNull($cache->serve($req), '/news/new is not cacheable');
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function test_layout_operator_block_is_admin_gated(): void
    {
        // the recorded scope: home and queue pass isAdmin cheaply; other pages
        // show the block when their controllers eventually pass the datum.
        $guestHome = $this->client()->get('/')->body;
        $this->assertStringNotContainsString('href="/admin"', $guestHome);
        $this->assertStringNotContainsString('href="/queue"', $guestHome);
        $memberHome = $this->client($this->memberId())->get('/')->body;
        $this->assertStringNotContainsString('href="/admin"', $memberHome);
        $adminHome = $this->client($this->adminId())->get('/')->body;
        $this->assertStringContainsString('href="/admin"', $adminHome);
        $this->assertStringContainsString('href="/queue"', $adminHome);
        $this->assertStringContainsString('href="/news/new"', $adminHome, 'news admin linked for admins');
        // the queue gate row already carries the viewer's role
        $this->assertStringContainsString('href="/admin"', $this->client($this->adminId())->get('/queue')->body);
    }
}
