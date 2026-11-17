<?php // app/Features/Adminmembers/Tests/AdminmembersTest.php
namespace App\Features\Adminmembers\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Lock, unlock and delete a member from the roster. */
#[Medium]
final class AdminmembersTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $targetId = 0;
    private int $heirId = 0;
    private int $moderatorId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-adminmembers-');
        $this->root = sys_get_temp_dir() . '/kiption-adminmembers-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->adminId = $this->user('roster-admin', 'admin');
        $this->targetId = $this->user('leaving-member', 'member');
        $this->heirId = $this->user('heir-member', 'member');
        $this->moderatorId = $this->user('roster-mod', 'moderator');
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES (?, ?, ?, ?, 2, 1)',
            ['Leaving Tale', 'leaving-tale', 'S.', $this->targetId]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function user(string $name, string $role): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ["$name@e.test", \App\Tests\Support\Hashes::of('password123'), $name, $role, $role === 'admin' ? 1 : 0, date('c'), date('c'), $name]);
        return (int) $this->db->lastInsertId();
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    private function locked(int $id): int
    {
        return (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$id])['is_locked'];
    }

    public function test_roster_offers_lock_unlock_and_delete(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertStringContainsString('action="/adminmembers/lock/' . $this->targetId . '"', $body);
        $this->assertStringContainsString('href="/adminmembers/remove/' . $this->targetId . '"', $body);
        $this->assertStringNotContainsString('action="/adminmembers/lock/' . $this->adminId . '"', $body, 'no lock button on your own row');
        $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $this->targetId);
        $this->assertStringContainsString('action="/adminmembers/unlock/' . $this->targetId . '"', $this->client($this->adminId)->get('/adminmembers')->body);
    }

    public function test_each_row_says_when_they_last_signed_in_and_what_they_have_posted(): void
    {
        $writer = $this->user('roster-writer', 'member');
        $this->db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES ('Only One', 'only-one', 'S.', ?, 2, 1)", [$writer]);
        $this->db->query("INSERT INTO reports (reporter_id, story_id, reason) VALUES (?, ?, 'Spam')", [$this->adminId, (int) $this->db->lastInsertId()]);
        $this->db->query("INSERT INTO activity_log (actor_id, actor_name, action, detail, created_at) VALUES (?, 'roster-writer', 'auth.signin', '', '2026-03-04 10:00:00')", [$writer]);
        $body = $this->client($this->adminId)->get('/adminmembers', ['q' => 'roster-w'])->body;
        $this->assertStringContainsString(\App\Lang::t('adminmembers.last_signin', ['date' => \App\Dates::format('2026-03-04 10:00:00')]), $body);
        $this->assertStringContainsString(\App\Lang::t('adminmembers.stories_one', ['n' => 1]), $body);
        $this->assertStringContainsString(\App\Lang::t('adminmembers.open_reports_one', ['n' => 1]), $body);
        $quiet = $this->client($this->adminId)->get('/adminmembers', ['q' => 'roster-admin'])->body;
        $this->assertStringContainsString(\App\Lang::t('adminmembers.no_signin'), $quiet);
    }

    public function test_roster_shows_role_names_people_can_read(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertStringContainsString('>Make trusted author</button>', $body);
        $this->assertStringContainsString('>Moderator</span>', $body);
        $this->assertStringNotContainsString('>validated_author<', $body, 'role codes never reach the page');
        $this->assertStringNotContainsString('>moderator<', $body);
    }

    public function test_your_own_row_shows_your_role_without_buttons_to_change_it(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertStringNotContainsString('action="/adminmembers/role/' . $this->adminId . '/', $body, 'no role form on your own row');
        $this->assertStringContainsString('>Admin</span>', $body, 'your role still shows');
        $this->assertStringContainsString('action="/adminmembers/role/' . $this->targetId . '/moderator"', $body, 'other rows keep theirs');
        // The page only stops offering it; the server rule is unchanged.
        \App\Adminness::setRole($this->db, $this->heirId, 'admin');
        $this->assertSame(302, $this->client($this->adminId)->postWithToken('/adminmembers/role/' . $this->adminId . '/member')->status);
    }

    public function test_locking_signs_out_a_member_who_is_already_in(): void
    {
        $other = $this->user('second-admin', 'admin');
        $session = $this->client($other);
        $this->assertSame(200, $session->get('/adminmembers')->status, 'signed in before the lock');
        $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $other);
        $res = $session->get('/adminmembers');
        $this->assertSame(302, $res->status, 'sent to sign in on the next request');
        $this->assertSame('/auth/login?locked=1', $res->headers['Location'] ?? null);
    }

    public function test_the_request_hook_bounces_a_locked_member_from_account(): void
    {
        $member = $this->client($this->targetId);
        $this->assertSame(200, $member->get('/account')->status, 'signed in before the lock');
        $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $this->targetId);
        \App\SessionGuard::enforce($this->db, $member->session); // what public/index.php runs per request
        $res = $member->get('/account');
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location'] ?? null);
    }

    public function test_deleting_signs_out_a_member_who_is_already_in(): void
    {
        \App\Adminness::setRole($this->db, $this->heirId, 'admin');
        $heir = $this->client($this->heirId);
        $this->assertSame(200, $heir->get('/adminmembers')->status);
        $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->heirId, ['stories' => 'remove']);
        $this->assertSame(302, $heir->get('/adminmembers')->status);
    }

    public function test_locked_member_cannot_sign_in_and_unlock_restores_it(): void
    {
        $admin = $this->client($this->adminId);
        $this->assertSame(302, $admin->postWithToken('/adminmembers/lock/' . $this->targetId)->status);
        $this->assertSame(1, $this->locked($this->targetId));
        $res = $this->client()->post('/auth/attempt', ['email' => 'leaving-member@e.test', 'password' => 'password123']);
        $this->assertStringContainsString('This account is locked.', $res->body);
        $this->assertSame(302, $admin->postWithToken('/adminmembers/unlock/' . $this->targetId)->status);
        $this->assertSame(0, $this->locked($this->targetId));
        $this->assertSame(302, $this->client()->post('/auth/attempt', ['email' => 'leaving-member@e.test', 'password' => 'password123'])->status);
    }

    public function test_cannot_lock_yourself_or_the_last_unlocked_admin(): void
    {
        $other = $this->user('second-admin', 'admin');
        $this->db->query('UPDATE users SET is_locked = 1 WHERE id = ?', [$other]);
        // a locked admin's old session is bounced before the lock runs
        $this->assertSame(302, $this->client($other)->postWithToken('/adminmembers/lock/' . $this->adminId)->status);
        $this->assertSame(0, $this->locked($this->adminId));
        $this->assertSame(422, $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $this->adminId)->status);
        $this->assertSame(0, $this->locked($this->adminId));
    }

    public function test_non_admins_and_missing_tokens_are_refused(): void
    {
        foreach (['/adminmembers/lock/', '/adminmembers/unlock/', '/adminmembers/destroy/'] as $p) {
            $this->assertSame(403, $this->client($this->moderatorId)->postWithToken($p . $this->targetId)->status, $p);
            $this->assertSame(403, $this->client($this->heirId)->postWithToken($p . $this->targetId)->status, $p);
            $this->assertSame(403, $this->client($this->adminId)->post($p . $this->targetId)->status, $p . ' without a token');
        }
        $this->assertSame(403, $this->client($this->moderatorId)->get('/adminmembers/remove/' . $this->targetId)->status);
        $this->assertSame(0, $this->locked($this->targetId));
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM stories WHERE author_id = ? AND deleted_at IS NULL', [$this->targetId])['c']);
    }

    public function test_delete_page_asks_what_to_do_with_the_stories(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers/remove/' . $this->targetId)->body;
        $this->assertStringContainsString('action="/adminmembers/destroy/' . $this->targetId . '"', $body);
        $this->assertStringContainsString('name="stories" value="move"', $body);
        $this->assertStringContainsString('name="stories" value="remove"', $body);
        $this->assertStringContainsString('name="penname"', $body);
        $this->assertStringContainsString('Stories by this member: 1', $body);
    }

    public function test_delete_and_keep_moves_the_stories_to_another_member(): void
    {
        $res = $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => 'heir-member']);
        $this->assertSame(302, $res->status, $res->body);
        $story = $this->db->one("SELECT author_id, deleted_at FROM stories WHERE slug = 'leaving-tale'");
        $this->assertSame($this->heirId, (int) $story['author_id']);
        $this->assertNull($story['deleted_at']);
        $gone = $this->db->one('SELECT email, penname, is_locked, role FROM users WHERE id = ?', [$this->targetId]);
        $this->assertNull($gone['penname']);
        $this->assertSame(1, (int) $gone['is_locked']);
        $this->assertStringNotContainsString('leaving-member@e.test', $gone['email']);
        $this->assertSame(200, $this->client()->get('/story/view/leaving-tale')->status);
        $this->assertStringContainsString('Wrong email or password', $this->client()->post('/auth/attempt', ['email' => 'leaving-member@e.test', 'password' => 'password123'])->body);
    }

    public function test_delete_and_remove_soft_deletes_the_stories(): void
    {
        $res = $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'remove']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertNotNull($this->db->one("SELECT deleted_at FROM stories WHERE slug = 'leaving-tale'")['deleted_at']);
        $this->assertSame(404, $this->client()->get('/story/view/leaving-tale')->status);
        $this->assertNull($this->db->one('SELECT penname FROM users WHERE id = ?', [$this->targetId])['penname']);
    }

    public function test_delete_refuses_bad_choices_and_changes_nothing(): void
    {
        $admin = $this->client($this->adminId);
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->adminId, ['stories' => 'remove'])->status, 'not yourself');
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => 'no-such-person'])->status);
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => 'leaving-member'])->status, 'not to themselves');
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'sideways'])->status);
        $this->assertSame('leaving-member', $this->db->one('SELECT penname FROM users WHERE id = ?', [$this->targetId])['penname']);
        $this->assertSame(404, $admin->postWithToken('/adminmembers/destroy/99999', ['stories' => 'remove'])->status);
    }

    public function test_delete_refuses_the_last_working_admin(): void
    {
        $other = $this->user('second-admin', 'admin');
        $this->db->query('UPDATE users SET is_locked = 1 WHERE id = ?', [$other]);
        // the sole unlocked admin cannot delete themselves, and a locked admin's old session is bounced before any change
        $this->assertSame(422, $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->adminId, ['stories' => 'remove'])->status);
        $res = $this->client($other)->postWithToken('/adminmembers/destroy/' . $this->adminId, ['stories' => 'remove']);
        $this->assertSame(302, $res->status);
        $row = $this->db->one('SELECT penname, is_locked FROM users WHERE id = ?', [$this->adminId]);
        $this->assertSame('roster-admin', $row['penname']);
        $this->assertSame(0, (int) $row['is_locked']);
    }

    public function test_a_member_with_no_stories_can_be_deleted_without_a_choice(): void
    {
        $res = $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->heirId, ['stories' => 'remove']);
        $this->assertSame(302, $res->status);
        $this->assertNull($this->db->one('SELECT penname FROM users WHERE id = ?', [$this->heirId])['penname']);
    }

    private function seedPending(int $id, string $email): string
    {
        $token = bin2hex(random_bytes(32));
        $exp = date('c', time() + 3600);
        $epoch = substr((string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash'], 0, \Kip\Auth::EPOCH_LEN);
        $this->db->query('INSERT INTO email_changes (user_id, new_email, token_hash, pwd_epoch, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, 'taken-over@e.test', hash('sha256', $token), $epoch, $exp, date('c')]);
        $this->db->query('INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)',
            [$email, hash('sha256', 'r' . $token), $exp]);
        $this->db->query('INSERT INTO email_verifications (email, token_hash, expires_at) VALUES (?, ?, ?)',
            [$email, hash('sha256', 'v' . $token), $exp]);
        (new \App\LoginLinks($this->db))->mint($id, 1800);
        return $token;
    }

    private function rows(string $table, string $col, string|int $v): int
    {
        return (int) $this->db->one("SELECT COUNT(*) c FROM $table WHERE $col = ?", [$v])['c'];
    }

    public function test_deleting_a_member_drops_their_pending_tokens_and_a_late_email_change_does_nothing(): void
    {
        $token = $this->seedPending($this->targetId, 'leaving-member@e.test');
        $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'remove']);
        $this->assertSame(0, $this->rows('email_changes', 'user_id', $this->targetId));
        $this->assertSame(0, $this->rows('login_links', 'user_id', $this->targetId));
        $this->assertSame(0, $this->rows('password_resets', 'email', 'leaving-member@e.test'));
        $this->assertSame(0, $this->rows('email_verifications', 'email', 'leaving-member@e.test'));
        $this->client()->postWithToken('/account/emailapply', ['t' => $token]);
        $row = $this->db->one('SELECT email, is_locked FROM users WHERE id = ?', [$this->targetId]);
        $this->assertSame('deleted-' . $this->targetId . '@deleted.invalid', $row['email']);
        $this->assertSame(1, (int) $row['is_locked']);
    }

    public function test_locking_a_member_drops_their_pending_email_change_and_sign_in_links(): void
    {
        $this->seedPending($this->targetId, 'leaving-member@e.test');
        $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $this->targetId);
        $this->assertSame(0, $this->rows('email_changes', 'user_id', $this->targetId));
        $this->assertSame(0, $this->rows('login_links', 'user_id', $this->targetId));
    }

    public function test_locking_a_locked_member_and_unlocking_an_active_one_change_nothing(): void
    {
        $admin = $this->client($this->adminId);
        $admin->postWithToken('/adminmembers/lock/' . $this->targetId);
        $admin->postWithToken('/adminmembers/lock/' . $this->targetId);
        $this->assertSame(1, $this->locked($this->targetId));
        $admin->postWithToken('/adminmembers/unlock/' . $this->heirId);
        $this->assertSame(0, $this->locked($this->heirId));
    }

    public function test_a_deleted_member_stays_locked_and_scrubbed_through_lock_and_unlock(): void
    {
        $admin = $this->client($this->adminId);
        $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'remove']);
        $before = $this->db->one('SELECT * FROM users WHERE id = ?', [$this->targetId]);
        $admin->postWithToken('/adminmembers/lock/' . $this->targetId);
        $admin->postWithToken('/adminmembers/unlock/' . $this->targetId);
        $after = $this->db->one('SELECT * FROM users WHERE id = ?', [$this->targetId]);
        $this->assertSame(1, (int) $after['is_locked']);
        $this->assertSame($before, $after);
    }

    public function test_deleting_a_locked_member_scrubs_it_and_deleting_again_changes_nothing(): void
    {
        $admin = $this->client($this->adminId);
        $admin->postWithToken('/adminmembers/lock/' . $this->targetId);
        $this->assertSame(302, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'remove'])->status);
        $before = $this->db->one('SELECT * FROM users WHERE id = ?', [$this->targetId]);
        $this->assertNull($before['penname']);
        $this->assertSame('deleted-' . $this->targetId . '@deleted.invalid', $before['email']);
        $res = $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'remove']);
        $this->assertSame(422, $res->status);
        $this->assertSame($before, $this->db->one('SELECT * FROM users WHERE id = ?', [$this->targetId]));
    }

    public function test_stories_cannot_be_handed_to_a_locked_or_unapproved_member(): void
    {
        $admin = $this->client($this->adminId);
        $this->db->query('UPDATE users SET is_locked = 1 WHERE id = ?', [$this->heirId]);
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => 'heir-member'])->status, 'locked');
        $this->db->query('UPDATE users SET is_locked = 0, approved_at = NULL WHERE id = ?', [$this->heirId]);
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => 'heir-member'])->status, 'unapproved');
        $this->db->query('UPDATE users SET approved_at = ?, email_verified_at = NULL WHERE id = ?', [date('c'), $this->heirId]);
        $this->assertSame(422, $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => 'heir-member'])->status, 'unverified');
        $this->assertSame($this->targetId, (int) $this->db->one("SELECT author_id FROM stories WHERE slug = 'leaving-tale'")['author_id']);
        $this->assertSame('leaving-member', $this->db->one('SELECT penname FROM users WHERE id = ?', [$this->targetId])['penname']);
    }

    public function test_the_new_owner_is_found_ignoring_case_and_padding_and_inherits_series_without_a_duplicate_coauthor(): void
    {
        $this->db->query('INSERT INTO series (title, slug, owner_id) VALUES (?, ?, ?)', ['Saga', 'saga', $this->targetId]);
        $storyId = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'leaving-tale'")['id'];
        $this->db->query('INSERT INTO coauthors (story_id, user_id) VALUES (?, ?)', [$storyId, $this->heirId]);
        $res = $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'move', 'penname' => '  HEIR-Member ']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame($this->heirId, (int) $this->db->one("SELECT owner_id FROM series WHERE slug = 'saga'")['owner_id']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM coauthors WHERE story_id = ? AND user_id = ?', [$storyId, $this->heirId])['c'],
            'the new owner is no longer listed as a coauthor of their own story');
    }

    public function test_unknown_members_get_a_404_and_a_deleted_one_cannot_get_a_new_role(): void
    {
        $admin = $this->client($this->adminId);
        foreach (['lock', 'unlock'] as $action) {
            $this->assertSame(404, $admin->postWithToken('/adminmembers/' . $action . '/99999')->status, $action);
        }
        $this->assertSame(404, $admin->get('/adminmembers/remove/99999')->status);
        $admin->postWithToken('/adminmembers/destroy/' . $this->targetId, ['stories' => 'remove']);
        $this->assertSame(422, $admin->postWithToken('/adminmembers/role/' . $this->targetId . '/moderator')->status);
        $this->assertSame('member', $this->db->one('SELECT role FROM users WHERE id = ?', [$this->targetId])['role']);
    }

    public function test_a_cross_site_delete_is_refused_and_nothing_changes(): void
    {
        $res = $this->client($this->adminId)->post('/adminmembers/destroy/' . $this->targetId, ['stories' => 'remove'], ['sec-fetch-site' => 'cross-site']);
        $this->assertSame(403, $res->status);
        $this->assertSame('leaving-member', $this->db->one('SELECT penname FROM users WHERE id = ?', [$this->targetId])['penname']);
    }

    public function test_delete_removes_the_members_avatar_file_too(): void
    {
        $dir = $this->root . '/upl';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/ghost.png', 'x');
        $this->db->query("UPDATE users SET avatar_path = 'ghost.png' WHERE id = ?", [$this->heirId]);
        $this->assertFileExists($dir . '/ghost.png');
        $this->assertSame(302, $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->heirId, ['stories' => 'remove'])->status);
        $this->assertFileDoesNotExist($dir . '/ghost.png', 'the picture is scrubbed with the account');
    }
}
