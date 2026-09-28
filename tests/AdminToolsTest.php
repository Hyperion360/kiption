<?php // tests/AdminToolsTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Task 7: the admin-only member role tool (Adminness::setRole stays the only
 *  role writer, the iff invariant pinned live), story reassignment with its
 *  coauthor cleanup (finding 14) and the full purge set, and the featured
 *  flag the home page finally consumes (finding 3). Roles are admin-only:
 *  the moderator-must-not-mint-admins pin (finding 11) rides the SQL admin
 *  gate because requireModerator would admit moderators. */
final class AdminToolsTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private string $cacheDir = '';
    private Database $db;
    private ?App $app = null;
    private int $adminUserId = 0;
    private int $memberUserId = 0;
    private int $moderatorUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-admintools-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-admintools-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->cacheDir = dirname(__DIR__) . '/public/cache';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        // admin + member + moderator fixtures, the NewsTest idiom
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('tooladmin@e.test', ?, 'tooladmin', 'admin', 1, ?, ?, 'tooladmin')",
            [$hash, date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('toolmember@e.test', ?, 'toolmember', ?, ?, 'toolmember')",
            [$hash, date('c'), date('c')]);
        $this->memberUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, email_verified_at, approved_at, profile_slug) VALUES ('toolmod@e.test', ?, 'toolmoderator', 'moderator', ?, ?, 'toolmoderator')",
            [$hash, date('c'), date('c')]);
        $this->moderatorUserId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
        // only the subtrees this suite writes inside the gitignored shared cache dir
        exec('rm -rf ' . escapeshellarg($this->cacheDir . '/story/view/the-rabbit-hole')
            . ' ' . escapeshellarg($this->cacheDir . '/user')
            . ' ' . escapeshellarg($this->cacheDir . '/browse')
            . ' ' . escapeshellarg($this->cacheDir . '/series')
            . ' ' . escapeshellarg($this->cacheDir . '/top'));
        @unlink($this->cacheDir . '/index.html');
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function adminId(): int
    {
        return $this->adminUserId;
    }

    private function memberId(): int
    {
        return $this->memberUserId;
    }

    private function moderatorId(): int
    {
        return $this->moderatorUserId;
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
        ]);
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_admin_changes_roles_through_setrole_only(): void
    {
        $admin = $this->client($this->adminId());
        $this->assertSame(302, $admin->postWithToken('/adminmembers/role/' . $this->memberId() . '/moderator')->status);
        $db = $this->db();
        $row = $db->one('SELECT role, is_admin FROM users WHERE id = ?', [$this->memberId()]);
        $this->assertSame('moderator', $row['role']);
        $this->assertSame(0, (int) $row['is_admin'], 'iff invariant');
        $this->assertSame(302, $admin->postWithToken('/adminmembers/role/' . $this->memberId() . '/admin')->status);
        $this->assertSame(1, (int) $db->one('SELECT is_admin FROM users WHERE id = ?', [$this->memberId()])['is_admin']);
        $this->assertSame(422, $admin->postWithToken('/adminmembers/role/' . $this->memberId() . '/supreme-overlord')->status);
        $this->assertSame(302, $this->client()->get('/adminmembers')->status, 'auth');
        $this->assertSame(403, $this->client($this->memberId())->post('/adminmembers/role/1/moderator')->status, 'CSRF 403 before role gate');
        $this->assertSame(403, $this->client($this->moderatorId())->postWithToken('/adminmembers/role/1/admin')->status, 'finding 11: moderators must not mint admins');
        // junk and unknown ids 404, never a 500
        $this->assertSame(404, $admin->postWithToken('/adminmembers/role/abc/member')->status);
        $this->assertSame(404, $admin->postWithToken('/adminmembers/role/99999/member')->status);
        // the bad-enum 422 left the row untouched
        $this->assertSame('admin', $db->one('SELECT role FROM users WHERE id = ?', [$this->memberId()])['role']);
    }

    public function test_story_reassignment_and_featured(): void
    {
        $admin = $this->client($this->adminId());
        $this->assertSame(302, $admin->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        $db = $this->db();
        $this->assertSame('betafriend', $db->one('SELECT penname FROM users u JOIN stories s ON s.author_id = u.id WHERE s.slug = ?', ['the-rabbit-hole'])['penname']);
        $this->assertSame(404, $admin->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'ghost'])->status);
        $this->assertSame(302, $admin->postWithToken('/adminstories/featured/the-rabbit-hole')->status);
        $this->assertSame(1, (int) $db->one("SELECT featured FROM stories WHERE slug = 'the-rabbit-hole'")['featured']);
        $home = $this->client()->get('/')->body;
        $this->assertStringContainsString('Featured', $home);
        $this->assertStringContainsString('The Rabbit Hole', $home);
    }

    public function test_reassignment_cleans_both_authors_coauthor_rows(): void
    {
        // finding 14: a target who was already a coauthor would end up author
        // AND coauthor; the rows of BOTH the old and the new author must go.
        $db = $this->db();
        $admin = $this->client($this->adminId());
        $storyId = (int) $db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $oldId = (int) $db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $newId = (int) $db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        // betafriend coauthors the story, then becomes its author: row must vanish
        $db->query('INSERT INTO coauthors (story_id, user_id) VALUES (?, ?)', [$storyId, $newId]);
        $this->assertSame(302, $admin->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM coauthors WHERE story_id = ?', [$storyId])['c'], 'new-author coauthor row removed');
        // the old author (now a legal coauthor target) coauthors, then the story
        // comes back to them: their row must vanish too (the old-author half)
        $db->query('INSERT INTO coauthors (story_id, user_id) VALUES (?, ?)', [$storyId, $oldId]);
        $this->assertSame(302, $admin->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'Demo Author'])->status);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM coauthors WHERE story_id = ?', [$storyId])['c'], 'old-author coauthor row removed');
        $this->assertSame('Demo Author', $db->one('SELECT penname FROM users u JOIN stories s ON s.author_id = u.id WHERE s.slug = ?', ['the-rabbit-hole'])['penname']);
        // reassigning to the current owner is a no-op redirect, not a write
        $this->assertSame(302, $admin->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'demo author'])->status, 'COLLATE NOCASE lookup');
        $this->assertSame($oldId, (int) $db->one("SELECT author_id FROM stories WHERE slug = 'the-rabbit-hole'")['author_id']);
    }

    public function test_reassignment_purges_the_full_surface_set(): void
    {
        $db = $this->db();
        $sentinels = [
            '/index.html' => $this->cacheDir . '/index.html',
            '/story/view' => $this->cacheDir . '/story/view/the-rabbit-hole/index.html',
            '/user/view/demo-author' => $this->cacheDir . '/user/view/demo-author/index.html',
            '/user/view/betafriend' => $this->cacheDir . '/user/view/betafriend/index.html',
            '/browse/authors' => $this->cacheDir . '/browse/authors/index.html',
        ];
        foreach ($sentinels as $file) { @mkdir(dirname($file), 0775, true); file_put_contents($file, 'stale'); }
        $this->assertSame(302, $this->client($this->adminId())->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        foreach ($sentinels as $label => $file) {
            $this->assertFileDoesNotExist($file, "{$label} cache file unlinked");
        }
        // gates and misses: member and moderator barred even with a token,
        // a guest gets the login 302 (gate before CSRF since kip b29e269),
        // unknown slugs 404
        $this->assertSame(403, $this->client($this->memberId())->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        $this->assertSame(403, $this->client($this->moderatorId())->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        $this->assertSame(302, $this->client()->post('/adminstories/reassign/the-rabbit-hole', ['penname' => 'betafriend'])->status, 'login redirect before the admin gate');
        $this->assertSame('betafriend', $db->one('SELECT penname FROM users u JOIN stories s ON s.author_id = u.id WHERE s.slug = ?', ['the-rabbit-hole'])['penname'], 'the guest POST reassigned nothing');
        $admin = $this->client($this->adminId());
        $this->assertSame(404, $admin->postWithToken('/adminstories/reassign/no-such-story', ['penname' => 'betafriend'])->status);
        $this->assertSame(404, $admin->postWithToken('/adminstories/reassign/the-rabbit-hole', [])->status, 'empty penname is an unknown member');
        // a locked target is not a full member: 404, not a reassignment
        $db->query('UPDATE users SET is_locked = 1 WHERE penname = ?', ['betafriend']);
        $this->assertSame(404, $admin->postWithToken('/adminstories/reassign/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        $this->assertSame('betafriend', $db->one('SELECT penname FROM users u JOIN stories s ON s.author_id = u.id WHERE s.slug = ?', ['the-rabbit-hole'])['penname'], 'the misses above left the reassignment from the top of the test in place');
    }

    public function test_featured_toggle_purges_home_and_flips_back(): void
    {
        $file = $this->cacheDir . '/index.html';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, 'stale');
        $admin = $this->client($this->adminId());
        $this->assertSame(302, $admin->postWithToken('/adminstories/featured/the-rabbit-hole')->status);
        $this->assertFileDoesNotExist($file, 'the home cache file unlinked (purgeStory carries /)');
        $this->assertSame(1, (int) $this->db()->one("SELECT featured FROM stories WHERE slug = 'the-rabbit-hole'")['featured']);
        // the toggle flips both ways; with it off the home has no Featured list
        $this->assertSame(302, $admin->postWithToken('/adminstories/featured/the-rabbit-hole')->status);
        $this->assertSame(0, (int) $this->db()->one("SELECT featured FROM stories WHERE slug = 'the-rabbit-hole'")['featured']);
        $this->assertStringNotContainsString('The Rabbit Hole', $this->client()->get('/')->body);
        // gates and misses
        $this->assertSame(403, $this->client($this->memberId())->postWithToken('/adminstories/featured/the-rabbit-hole')->status);
        $this->assertSame(403, $this->client($this->moderatorId())->postWithToken('/adminstories/featured/the-rabbit-hole')->status);
        // gate before CSRF since kip b29e269: the guest POST gets the login 302
        // and toggles nothing
        $this->assertSame(302, $this->client()->post('/adminstories/featured/the-rabbit-hole')->status, 'login redirect before the admin gate');
        $this->assertSame(0, (int) $this->db()->one("SELECT featured FROM stories WHERE slug = 'the-rabbit-hole'")['featured'], 'the guest POST toggled nothing');
        $this->assertSame(404, $admin->postWithToken('/adminstories/featured/no-such-story')->status);
    }

    public function test_home_featured_query_honors_guest_gates(): void
    {
        $db = $this->db();
        $home = $this->client()->get('/')->body;
        $this->assertStringNotContainsString('Featured', $home, 'nothing featured on a fresh DB');
        // restricted, unvalidated and soft-deleted stories never surface to
        // guests (adult BY RATING is not the restricted gate: such stories
        // appear in listings and meet the age gate on the story page)
        $db->query("UPDATE stories SET featured = 1, is_restricted = 1 WHERE slug IN ('after-hours')");
        $db->query("UPDATE stories SET featured = 1, validated = 0 WHERE slug = 'the-rabbit-hole'");
        $db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, featured, validated, completed, word_count, deleted_at)
                    SELECT 'Ghosted', 'ghosted', 'g.', author_id, rating_id, 1, 1, 0, 10, '2026-09-01T00:00:00Z' FROM stories WHERE slug = 'the-rabbit-hole'");
        $body = $this->client()->get('/')->body;
        $this->assertStringNotContainsString('After Hours', $body, 'restricted stories stay off the guest home');
        $this->assertStringNotContainsString('The Rabbit Hole', $body, 'unvalidated stories stay off the guest home');
        $this->assertStringNotContainsString('Ghosted', $body, 'soft-deleted stories stay off the guest home');
    }

    public function test_admin_members_index_lists_roles_and_searches(): void
    {
        $db = $this->db();
        $db->query('UPDATE users SET is_locked = 1 WHERE id = ?', [$this->memberId()]);
        $admin = $this->client($this->adminId());
        $res = $admin->get('/adminmembers');
        $this->assertSame(200, $res->status);
        $body = $res->body;
        $this->assertStringContainsString('Demo Author', $body);
        $this->assertStringContainsString('demo@example.test', $body);
        $this->assertStringContainsString('validated_author', $body, 'role badge');
        $this->assertStringContainsString('Locked', $body, 'status badge');
        $this->assertStringContainsString('action="/adminmembers/role/' . $this->memberId() . '/admin"', $body, 'role-select form');
        // self-demotion is offered with a confirm (the admin row is flagged)
        $this->assertStringContainsString('(you)', $body);
        $this->assertStringContainsString('Demote yourself', $body);
        // penname-prefix search narrows the roster
        $search = $admin->get('/adminmembers', ['q' => 'beta'])->body;
        $this->assertStringContainsString('betafriend', $search);
        $this->assertStringNotContainsString('Demo Author', $search);
        // paging: 20 filler members push the tail onto page 2 (zero-padded so
        // the lexicographic penname order matches the numeric order)
        for ($i = 0; $i < 20; $i++) {
            $db->query("INSERT INTO users (email, password_hash, penname) VALUES ('filler{$i}@e.test', ?, ?)",
                [password_hash('password123', PASSWORD_DEFAULT), sprintf('zz-filler-%02d', $i)]);
        }
        $page1 = $admin->get('/adminmembers')->body;
        $this->assertStringNotContainsString('zz-filler-19', $page1);
        $page2 = $admin->get('/adminmembers', ['page' => '2'])->body;
        $this->assertStringContainsString('zz-filler-19', $page2);
        // non-admins never see the roster
        $this->assertSame(403, $this->client($this->memberId())->get('/adminmembers')->status);
        $this->assertSame(403, $this->client($this->moderatorId())->get('/adminmembers')->status, 'moderators cannot manage roles');
    }

    public function test_controller_files_match_the_router_studly_contract(): void
    {
        // QA 10a: the router resolves /adminmembers to the studly class name
        // AdminmembersController and the PSR-4 autoloader maps that LITERALLY
        // to app/Features/<Name>/<Name>Controller.php (and, for any controller
        // not yet moved, app/src/Controllers/<name>.php). A camelCase class
        // behind a one-word URL segment (AdminMembersController serving
        // /adminmembers) resolves only through a case-INSENSITIVE filesystem:
        // the suite stays green on macOS while production Linux 404s the whole
        // surface (and pcov cannot attribute its coverage). scandir returns
        // each directory's true casing, so this check is exact everywhere; a
        // file_exists probe would be masked by APFS.
        $app = dirname(__DIR__) . '/app';
        $dirs = array_merge(
            glob($app . '/Features/*') ?: [],
            is_dir($app . '/src/Controllers') ? [$app . '/src/Controllers'] : []
        );
        $found = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir) || is_link($dir)) continue;
            $onDisk = scandir($dir) ?: [];
            foreach ($onDisk as $entry) {
                if (!str_ends_with($entry, 'Controller.php')) continue;
                $found++;
                $url = strtolower(substr($entry, 0, -strlen('Controller.php')));
                $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $url)));
                $expected = $studly . 'Controller.php';
                $this->assertContains($expected, $onDisk,
                    "{$entry} serves /{$url}; the router autoloads {$expected}, and anything else 404s on a case-sensitive filesystem");
                $this->assertStringContainsString("class {$studly}Controller", (string) file_get_contents($dir . '/' . $expected),
                    "{$expected} must declare {$studly}Controller by that exact name");
            }
        }
        $this->assertGreaterThanOrEqual(1, $found, 'no controllers found in any feature folder or the legacy layered dir');
    }
}
