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

    public function test_each_row_has_stories_and_last_signed_in_columns_and_the_email_under_the_name(): void
    {
        $writer = $this->user('roster-writer', 'member');
        $this->db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES ('Only One', 'only-one', 'S.', ?, 2, 1)", [$writer]);
        $this->db->query("INSERT INTO reports (reporter_id, story_id, reason) VALUES (?, ?, 'Spam')", [$this->adminId, (int) $this->db->lastInsertId()]);
        $this->db->query("INSERT INTO activity_log (actor_id, actor_name, action, detail, created_at) VALUES (?, 'roster-writer', 'auth.signin', '', '2026-03-04 10:00:00')", [$writer]);
        $body = $this->client($this->adminId)->get('/adminmembers', ['q' => 'roster-w'])->body;
        $this->assertMatchesRegularExpression('#<th scope="col"[^>]*>Stories</th><th scope="col"[^>]*>Last Signed In</th>#', $body);
        $this->assertStringNotContainsString('<th scope="col">Email</th>', $body, 'the email sits under the name now');
        $this->assertStringContainsString('<td class="member-num" data-label="Stories">1</td>', $body);
        $this->assertStringContainsString('>' . \App\Dates::format('2026-03-04 10:00:00', false) . '</td>', $body);
        $this->assertStringContainsString('<span class="member-reports">' . \App\Lang::t('adminmembers.open_reports_one', ['n' => 1]) . '</span>', $body);
        $this->assertMatchesRegularExpression('#<span class="member-name">roster-writer</span>.*<span class="member-email">roster-writer@e.test</span>#s', $body);
        $quiet = $this->client($this->adminId)->get('/adminmembers', ['q' => 'roster-admin'])->body;
        $this->assertStringContainsString(\App\Lang::t('adminmembers.never_signed_in'), $quiet);
    }

    public function test_a_recent_sign_in_reads_today_or_yesterday(): void
    {
        $this->db->query("INSERT INTO activity_log (actor_id, actor_name, action, detail, created_at) VALUES (?, 'x', 'auth.signin', '', ?)", [$this->targetId, date('Y-m-d H:i:s')]);
        $this->db->query("INSERT INTO activity_log (actor_id, actor_name, action, detail, created_at) VALUES (?, 'x', 'auth.signin', '', ?)", [$this->heirId, date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertMatchesRegularExpression('#leaving-member.*?data-label="Last Signed In">Today</td>#s', $body);
        $this->assertMatchesRegularExpression('#heir-member.*?data-label="Last Signed In">Yesterday</td>#s', $body);
    }

    public function test_roster_shows_role_names_people_can_read(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertStringContainsString('>Trusted Author</option>', $body, 'the role select names roles in words');
        $this->assertStringContainsString('>Moderator</option>', $body);
        $this->assertStringNotContainsString('>validated_author<', $body, 'role codes never reach the page');
        $this->assertStringNotContainsString('>moderator<', $body);
    }

    public function test_your_own_row_shows_your_role_without_buttons_to_change_it(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertStringNotContainsString('action="/adminmembers/role/' . $this->adminId . '/', $body, 'no role form on your own row');
        $this->assertStringContainsString('>Admin</span>', $body, 'your role still shows');
        $this->assertStringNotContainsString('action="/adminmembers/setrole/' . $this->adminId . '"', $body);
        $this->assertStringContainsString('action="/adminmembers/setrole/' . $this->targetId . '"', $body, 'other rows keep theirs');
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
        $this->assertStringContainsString('<h1>Delete leaving-member?</h1>', $body, 'the title names who is going');
        $this->assertStringContainsString('leaving-member@e.test · 1 story · 0 open reports · member since ', $body, 'the facts come first');
        $this->assertStringContainsString('What should happen to their 1 story?', $body);
        $this->assertStringContainsString('>Delete leaving-member</button>', $body, 'the button says what it deletes');
        $this->assertStringContainsString('href="/adminmembers">Cancel</a>', $body);
    }

    public function test_delete_page_counts_what_removing_the_stories_would_take(): void
    {
        $storyId = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'leaving-tale'")['id'];
        foreach ([1, 2] as $n) {
            $this->db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, ?, 'C', 'x', 1, 1)", [$storyId, $n]);
        }
        $this->db->query("INSERT INTO reports (reporter_id, story_id, reason) VALUES (?, ?, 'Spam')", [$this->heirId, $storyId]);
        $body = $this->client($this->adminId)->get('/adminmembers/remove/' . $this->targetId)->body;
        $this->assertStringContainsString('1 story, 2 chapters and their reviews leave the archive.', $body);
        $this->assertStringContainsString('1 open report', $body);
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

    public function test_the_role_select_posts_the_chosen_role_through_the_same_checks(): void
    {
        $c = $this->client($this->adminId);
        $this->assertSame(302, $c->postWithToken('/adminmembers/setrole/' . $this->targetId, ['role' => 'moderator'])->status);
        $this->assertSame('moderator', $this->db->one('SELECT role FROM users WHERE id = ?', [$this->targetId])['role']);
        $this->assertSame(422, $c->postWithToken('/adminmembers/setrole/' . $this->targetId, ['role' => 'root'])->status, 'an unknown role is refused, never applied');
        $this->assertSame(403, $this->client($this->moderatorId)->postWithToken('/adminmembers/setrole/' . $this->targetId, ['role' => 'admin'])->status, 'moderators still cannot mint admins');
    }

    public function test_filters_carry_counts_and_narrow_the_list_and_the_total(): void
    {
        $this->db->query("UPDATE users SET approved_at = NULL WHERE id = ?", [$this->heirId]);
        $this->db->query("UPDATE users SET is_locked = 1 WHERE id = ?", [$this->targetId]);
        $c = $this->client($this->adminId);
        $body = $c->get('/adminmembers')->body;
        $this->assertStringContainsString('Moderators <span class="queue-tab-n">1</span>', $body);
        $this->assertStringContainsString('Locked <span class="queue-tab-n">1</span>', $body);
        $this->assertStringContainsString('Waiting <span class="queue-tab-n">1</span>', $body);
        $locked = $c->get('/adminmembers', ['filter' => 'locked'])->body;
        $this->assertStringContainsString('leaving-member', $locked);
        $this->assertStringNotContainsString('roster-mod@', $locked);
        $this->assertStringContainsString('class="member-range">1' . "\u{2013}" . '1 of 1<', $locked, 'the total follows the filter');
        $this->assertStringContainsString('<a href="/adminmembers?filter=locked" aria-current="page">', $locked);
        $this->assertStringContainsString('roster-admin@', $c->get('/adminmembers', ['filter' => 'bogus'])->body, 'an unknown filter means everyone');
    }

    public function test_the_chips_are_all_admins_moderators_trusted_authors_waiting_locked_and_email_unverified(): void
    {
        $trusted = $this->user('roster-trusted', 'validated_author');
        $unverified = $this->user('roster-unverified', 'member');
        $this->db->query('UPDATE users SET email_verified_at = NULL, approved_at = NULL WHERE id = ?', [$unverified]);
        $c = $this->client($this->adminId);
        $body = $c->get('/adminmembers')->body;
        preg_match('#<nav class="member-filters".*?</nav>#s', $body, $nav);
        preg_match_all('#<a href="[^"]*"(?: aria-current="page")?>([^<]*) <span class="queue-tab-n">([^<]*)</span></a>#', $nav[0], $m, PREG_SET_ORDER);
        $this->assertSame(['All', 'Admins', 'Moderators', 'Trusted Authors', 'Waiting', 'Locked', 'Email Unverified'], array_column($m, 1));
        $this->assertSame(['8', '1', '1', '2', '0', '0', '1'], array_column($m, 2));
        $only = $c->get('/adminmembers', ['filter' => 'validated_author'])->body;
        $this->assertStringContainsString('roster-trusted@', $only);
        $this->assertStringNotContainsString('roster-mod@', $only);
        $this->assertStringContainsString('<a href="/adminmembers?filter=validated_author" aria-current="page">', $only);
        $un = $c->get('/adminmembers', ['filter' => 'unverified'])->body;
        $this->assertStringContainsString('roster-unverified@', $un);
        $this->assertStringNotContainsString('roster-trusted@', $un);
    }

    public function test_the_header_shows_the_total_beside_the_title_and_the_two_owner_shortcuts(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertStringContainsString('<h1>Members</h1> <span class="member-count">6</span>', $body);
        $this->assertStringContainsString('<a class="btn" href="/invites">Invite Codes</a>', $body);
        $this->assertStringContainsString('<a class="btn" href="/mailusers">Email All Members</a>', $body);
    }

    public function test_the_pager_names_the_range_and_disables_the_ends(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertStringContainsString('class="member-range">1' . "\u{2013}" . '6 of 6<', $body);
        $this->assertStringContainsString('<span class="pager-off" aria-disabled="true">Previous</span>', $body);
        $this->assertStringContainsString('<span class="pager-off" aria-disabled="true">Next</span>', $body);
    }

    public function test_search_finds_a_member_by_the_start_of_their_email_and_keeps_the_filter(): void
    {
        $c = $this->client($this->adminId);
        $body = $c->get('/adminmembers', ['q' => 'heir-member@'])->body;
        $this->assertStringContainsString('heir-member@e.test', $body);
        $this->assertStringNotContainsString('leaving-member@e.test', $body);
        $this->assertStringContainsString('<input type="hidden" name="filter" value="member">', $c->get('/adminmembers', ['q' => 'heir', 'filter' => 'member'])->body);
    }

    public function test_each_account_shows_one_plain_status_word_and_a_menu_that_works_without_scripts(): void
    {
        $this->db->query("UPDATE users SET is_locked = 1 WHERE id = ?", [$this->targetId]);
        $this->db->query("UPDATE users SET approved_at = NULL WHERE id = ?", [$this->heirId]);
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        $this->assertStringContainsString('<span class="member-status is-locked">Locked</span>', $body);
        $this->assertStringContainsString('<span class="member-status is-active">Active</span>', $body);
        $this->assertStringContainsString('<span class="member-status is-unapproved">Waiting</span>', $body);
        $this->assertStringContainsString('<a class="member-approve" href="/queue?tab=members">Approve</a>', $body);
        $this->assertStringContainsString('<details class="member-menu">', $body);
        $this->assertStringContainsString('action="/adminmembers/unlock/' . $this->targetId . '"', $body, 'inside the menu, a plain form');
    }

    public function test_the_row_menu_holds_profile_reports_lock_and_delete_and_nothing_loose_beside_it(): void
    {
        $writer = $this->user('menu-writer', 'member');
        $this->db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES ('Menu Tale', 'menu-tale', 'S.', ?, 2, 1)", [$writer]);
        $this->db->query("INSERT INTO reports (reporter_id, story_id, reason) VALUES (?, ?, 'Spam')", [$this->adminId, (int) $this->db->lastInsertId()]);
        $body = $this->client($this->adminId)->get('/adminmembers', ['q' => 'menu-writer'])->body;
        preg_match('#<details class="member-menu">.*?</details>#s', $body, $menu);
        $this->assertStringContainsString('<a href="/user/view/menu-writer">View Profile</a>', $menu[0]);
        $this->assertStringContainsString('<a href="/queue?tab=reports">See Their 1 Open Report</a>', $menu[0]);
        $this->assertStringContainsString('<button type="submit">Lock Account</button>', $menu[0]);
        $this->assertStringContainsString('name="_token"', $menu[0], 'the lock form carries the CSRF token');
        $this->assertStringContainsString('<a href="/adminmembers/remove/' . $writer . '" class="member-delete">Delete Member&hellip;</a>', str_replace('…', '&hellip;', $menu[0]));
        $outside = preg_replace('#<details class="member-menu">.*?</details>#s', '', $body);
        preg_match('#<table.*?</table>#s', $outside, $table);
        $this->assertStringNotContainsString('>More<', $table[0] ?? $outside, 'the old More control is gone from the roster');
        $this->assertStringNotContainsString('class="member-facts"', $body);
        $quiet = $this->client($this->adminId)->get('/adminmembers', ['q' => 'roster-mod'])->body;
        $this->assertStringNotContainsString('Open Report', $quiet, 'no reports, no reports entry');
    }

    public function test_your_own_row_and_deleted_accounts_have_no_menu(): void
    {
        $own = $this->client($this->adminId)->get('/adminmembers', ['q' => 'roster-admin'])->body;
        $this->assertStringNotContainsString('<details class="member-menu">', $own);
        $this->assertStringContainsString('<span class="member-you">(you)</span>', $own);
    }

    public function test_moderators_cannot_open_the_roster_or_use_its_menu_actions(): void
    {
        $mod = $this->client($this->moderatorId);
        $this->assertSame(403, $mod->get('/adminmembers')->status);
        $this->assertSame(403, $mod->get('/adminmembers', ['filter' => 'locked'])->status);
        $this->assertSame(403, $mod->postWithToken('/adminmembers/setrole/' . $this->targetId, ['role' => 'moderator'])->status);
        $this->assertSame('member', $this->db->one('SELECT role FROM users WHERE id = ?', [$this->targetId])['role']);
    }

    public function test_every_mutating_form_on_the_roster_carries_the_token_and_posts(): void
    {
        $body = $this->client($this->adminId)->get('/adminmembers')->body;
        preg_match_all('#<form method="post"[^>]*>.*?</form>#s', $body, $forms);
        $this->assertNotEmpty($forms[0]);
        foreach ($forms[0] as $f) $this->assertStringContainsString('name="_token"', $f);
        $this->assertSame(403, $this->client($this->adminId)->post('/adminmembers/setrole/' . $this->targetId, ['role' => 'moderator'])->status, 'no token, no change');
    }

    public function test_a_filtered_search_still_costs_one_content_query(): void
    {
        $n = 0;
        \App\Tests\Support\QueryTap::on($this->db, function (string $sql) use (&$n): void {
            if ($sql !== \App\SessionGuard::SQL && $sql !== 'SELECT password_hash FROM users WHERE id = ?') $n++;
        });
        $this->assertSame(200, $this->client($this->adminId)->get('/adminmembers', ['filter' => 'validated_author', 'q' => 'a', 'page' => '1'])->status);
        $this->assertLessThanOrEqual(1, $n, 'counts, rows, stories, reports and sign-ins arrive in one statement');
    }
}
