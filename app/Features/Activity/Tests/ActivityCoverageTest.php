<?php // app/Features/Activity/Tests/ActivityCoverageTest.php
namespace App\Features\Activity\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Every action that changes something leaves a line in the activity log:
 *  admin and moderator actions, account and security events, member content.
 *  Nothing private (passwords, tokens, addresses, message text) is stored. */
#[Medium]
final class ActivityCoverageTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $modId = 0;
    private int $memberId = 0;
    private int $otherId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-actall-');
        $this->root = sys_get_temp_dir() . '/kiption-actall-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->adminId = $this->user('log-admin', 'admin');
        $this->modId = $this->user('log-mod', 'moderator');
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $this->otherId = $this->user('log-other', 'member');
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        \App\Lang::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function user(string $name, string $role): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ["$name@e.test", \App\Tests\Support\Hashes::of('password123'), $name, $role, $role === 'admin' ? 1 : 0, date('c'), date('c'), $name]);
        return (int) $this->db->lastInsertId();
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []); // the entrypoint seam, as the settings tests do
        $c = new TestClient(new App(\App\Settings::apply($extra + [
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
            'site_text_dir' => $this->root . '/site-text',
            'registration_mode' => 'open', 'signup_min_seconds' => 0,
        ])));
        return $as === null ? $c : $c->actingAs($as);
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return $this->db->all('SELECT actor_id, actor_name, action, detail FROM activity_log ORDER BY id');
    }

    private function actions(): array
    {
        return array_column($this->rows(), 'action');
    }

    private function last(): array
    {
        $rows = $this->rows();
        $this->assertNotSame([], $rows, 'nothing was logged');
        return $rows[array_key_last($rows)];
    }

    private function storyId(): int
    {
        return (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
    }

    // ---- group 1: admin and moderator actions ----

    public function test_member_lock_and_unlock_are_logged(): void
    {
        $admin = $this->client($this->adminId);
        $admin->postWithToken('/adminmembers/lock/' . $this->otherId);
        $admin->postWithToken('/adminmembers/unlock/' . $this->otherId);
        $this->assertSame(['members.lock', 'members.unlock'], $this->actions());
        $this->assertSame('member ' . $this->otherId, $this->rows()[0]['detail']);
        $this->assertSame('log-admin', $this->rows()[0]['actor_name']);
    }

    public function test_mass_mail_logs_the_count_and_never_the_recipients_or_text(): void
    {
        $res = $this->client($this->adminId)->postWithToken('/mailusers/send', ['subject' => 'Secret subject', 'body' => 'Secret body text.']);
        $this->assertSame(200, $res->status, $res->body);
        $row = $this->last();
        $this->assertSame('mailusers.send', $row['action']);
        $this->assertMatchesRegularExpression('/^\d+ of \d+ sent$/', $row['detail']);
        $this->assertStringNotContainsString('@', $row['detail']);
        $this->assertStringNotContainsString('Secret', $row['detail']);
    }

    public function test_settings_word_filter_and_site_text_saves_are_logged(): void
    {
        $admin = $this->client($this->adminId);
        $r = $admin->postWithToken('/settings/save', ['site_name' => 'Renamed Archive', 'registration_mode' => 'open', 'items_per_page' => '20']); $this->assertSame(302, $r->status, $r->body);
        $this->assertSame('settings.save', $this->last()['action']);
        $this->assertStringContainsString('site_name', $this->last()['detail']);
        $this->assertStringNotContainsString('Renamed Archive', $this->last()['detail'], 'names only, never values');
        $admin->postWithToken('/wordfilter/save', ['words' => "alpha\nbeta"]);
        $this->assertSame(['wordfilter.save', '2 words'], [$this->last()['action'], $this->last()['detail']]);
        $admin->postWithToken('/text/save', ['lang' => 'en', 'key' => 'activity.heading', 'value' => 'The log']);
        $this->assertSame('text.save', $this->last()['action']);
    }

    public function test_taxonomy_news_and_nav_changes_are_logged(): void
    {
        $admin = $this->client($this->adminId);
        $admin->postWithToken('/categories/create', ['name' => 'Logged Category']);
        $admin->postWithToken('/ratings/create', ['label' => 'Logged Rating']);
        $admin->postWithToken('/tags/addtype', ['name' => 'Logged Type']);
        $admin->postWithToken('/characters/create', ['name' => 'Logged Character']);
        $admin->postWithToken('/news/create', ['title' => 'Logged News', 'body' => 'Words.']);
        $admin->postWithToken('/nav/create', ['label' => 'Logged', 'url' => '/page/about']);
        $this->assertSame(['categories.create', 'ratings.create', 'tags.addtype', 'characters.create', 'news.create', 'nav.create'], $this->actions());
        $this->assertSame('Logged News', $this->rows()[4]['detail']);
    }

    public function test_story_admin_actions_and_the_queue_are_logged(): void
    {
        $admin = $this->client($this->adminId);
        $admin->postWithToken('/adminstories/featured/the-rabbit-hole');
        $this->assertSame(['adminstories.featured', 'the-rabbit-hole'], [$this->last()['action'], $this->last()['detail']]);
        $this->db->query('UPDATE stories SET validated = 0 WHERE id = ?', [$this->storyId()]);
        $this->client($this->modId)->postWithToken('/queue/story/' . $this->storyId() . '/approve');
        $this->assertSame('queue.story_approve', $this->last()['action']);
        $this->assertSame('log-mod', $this->last()['actor_name']);
    }

    public function test_report_dismissal_and_takedown_are_logged(): void
    {
        $this->db->query('INSERT INTO reports (reporter_id, story_id, reason) VALUES (?, ?, ?)', [$this->otherId, $this->storyId(), 'Spam']);
        $first = (int) $this->db->lastInsertId();
        $this->client($this->modId)->postWithToken('/report/resolve/' . $first . '/dismiss');
        $this->assertSame('reports.dismiss', $this->last()['action']);
        $this->db->query('INSERT INTO reports (reporter_id, story_id, reason) VALUES (?, ?, ?)', [$this->otherId, $this->storyId(), 'Worse']);
        $second = (int) $this->db->lastInsertId();
        $this->client($this->modId)->postWithToken('/report/takedown/' . $second, ['reason' => 'Plagiarised text']);
        $this->assertSame('reports.takedown', $this->last()['action']);
        $this->assertStringNotContainsString('Plagiarised', $this->last()['detail']);
    }

    public function test_a_refused_admin_action_leaves_no_line(): void
    {
        $this->client($this->memberId)->postWithToken('/categories/create', ['name' => 'Nope']);
        $this->client($this->adminId)->postWithToken('/categories/create', ['name' => '']);
        $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $this->adminId); // never yourself
        $this->assertSame([], $this->rows());
    }

    // ---- group 2: account and security events ----

    public function test_sign_in_success_failure_and_sign_out_are_logged_without_the_typed_address(): void
    {
        $c = $this->client();
        $c->postWithToken('/auth/attempt', ['email' => 'someone-typed@e.test', 'password' => 'wrong-password']);
        $fail = $this->last();
        $this->assertSame('auth.signin_failed', $fail['action']);
        $this->assertNull($fail['actor_id']);
        $this->assertStringNotContainsString('someone-typed', $fail['detail']);
        $this->assertStringNotContainsString('wrong-password', $fail['detail']);

        $c->postWithToken('/auth/attempt', ['email' => 'log-other@e.test', 'password' => 'password123']);
        $this->assertSame(['auth.signin', $this->otherId], [$this->last()['action'], (int) $this->last()['actor_id']]);
        $c->postWithToken('/auth/logout');
        $this->assertSame(['auth.signin_failed', 'auth.signin', 'auth.signout'], $this->actions());
    }

    public function test_registration_and_password_reset_request_are_logged(): void
    {
        $c = $this->client();
        $r = $c->postWithToken('/auth/store', ['penname' => 'newcomer', 'email' => 'newcomer@e.test', 'password' => 'password123', 'confirm' => 'password123']); $this->assertSame(302, $r->status, strip_tags($r->body));
        $this->assertSame('auth.register', $this->last()['action']);
        $this->assertSame('newcomer', $this->last()['actor_name']);
        $this->assertStringNotContainsString('newcomer@e.test', $this->last()['detail']);
        $c->postWithToken('/auth/remind', ['email' => 'newcomer@e.test']);
        $c->postWithToken('/auth/remind', ['email' => 'nobody-at-all@e.test']);
        $this->assertSame(['auth.register', 'auth.reset_requested'], $this->actions(), 'an unknown address logs nothing');
    }

    public function test_sign_in_link_request_and_use_are_logged_without_the_link(): void
    {
        $c = $this->client();
        $c->postWithToken('/auth/send', ['email' => 'log-other@e.test']);
        $this->assertSame('auth.link_requested', $this->last()['action']);
        $token = (new \App\LoginLinks($this->db))->mint($this->otherId, 600);
        $c->postWithToken('/auth/enter', ['t' => $token]);
        $this->assertSame(['auth.link_requested', 'auth.link_used'], $this->actions());
        foreach ($this->rows() as $row) $this->assertStringNotContainsString($token, $row['detail']);
    }

    public function test_password_change_is_logged_without_the_password(): void
    {
        $this->client($this->otherId)->postWithToken('/account/password',
            ['current_password' => 'password123', 'new_password' => 'brand-new-pass', 'confirm_password' => 'brand-new-pass']);
        $row = $this->last();
        $this->assertSame('account.password', $row['action']);
        $this->assertSame('', $row['detail']);
    }

    public function test_deleting_your_own_account_is_logged_with_who_it_was(): void
    {
        $this->client($this->otherId)->postWithToken('/account/destroy', ['stories' => 'keep', 'current_password' => 'password123']);
        $row = $this->last();
        $this->assertSame('account.delete', $row['action']);
        $this->assertStringContainsString('log-other', $row['detail']);
    }

    // ---- group 3: member content ----

    public function test_story_chapter_review_and_message_actions_are_logged(): void
    {
        $rating = (string) $this->db->one("SELECT id FROM ratings LIMIT 1")['id'];
        $cat = (string) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id'];
        $me = $this->client($this->memberId);
        $me->postWithToken('/story/create', ['title' => 'Logged Story', 'summary' => 's', 'rating_id' => $rating, 'categories' => [$cat]]);
        $this->assertSame(['story.create', 'Logged Story'], [$this->last()['action'], $this->last()['detail']]);
        $slug = (string) $this->db->one("SELECT slug FROM stories WHERE title = 'Logged Story'")['slug'];
        $me->postWithToken('/chapter/create/' . $slug, ['title' => 'One', 'content' => 'Some words here.']);
        $this->assertSame('chapter.create', $this->last()['action']);
        $this->client($this->otherId)->postWithToken('/review/add/' . $slug, ['body' => 'Lovely read.', 'rating' => '', 'guest_name' => '']);
        $this->assertSame('review.add', $this->last()['action']);
        $this->assertStringNotContainsString('Lovely', $this->last()['detail']);
        $rid = (int) $this->db->one('SELECT id FROM reviews ORDER BY id DESC LIMIT 1')['id'];
        $me->postWithToken('/review/delete/' . $rid);
        $this->assertSame('review.delete', $this->last()['action']);
        $me->postWithToken('/story/update/' . $slug, ['title' => 'Logged Story', 'summary' => 't', 'rating_id' => $rating, 'categories' => [$cat]]);
        $this->assertSame('story.update', $this->last()['action']);
        $me->postWithToken('/story/delete/' . $slug);
        $this->assertSame('story.delete', $this->last()['action']);
    }

    public function test_a_private_message_logs_that_it_was_sent_and_never_what_it_said(): void
    {
        $this->client($this->otherId)->postWithToken('/messages/send/demo-author', ['body' => 'My very private words.']);
        $row = $this->last();
        $this->assertSame('messages.send', $row['action']);
        $this->assertSame('to member ' . $this->memberId, $row['detail']);
        $this->assertStringNotContainsString('private', $row['detail']);
    }

    // ---- everything else: member-owned features, preferences, forms ----

    public function test_deleting_a_member_is_logged_with_the_story_choice(): void
    {
        $this->client($this->adminId)->postWithToken('/adminmembers/destroy/' . $this->otherId, ['stories' => 'remove']);
        $row = $this->last();
        $this->assertSame('members.delete', $row['action']);
        $this->assertSame('log-admin', $row['actor_name']);
        $this->assertSame('member ' . $this->otherId . ', stories removed', $row['detail']);
        $this->assertStringNotContainsString('@', $row['detail']);
    }

    public function test_series_lists_and_challenges_are_logged(): void
    {
        $me = $this->client($this->memberId);
        $me->postWithToken('/series/create', ['title' => 'Logged Series', 'summary' => '', 'membership' => 'open']);
        $this->assertSame('series.create', $this->last()['action']);
        $slug = (string) $this->db->one("SELECT slug FROM series WHERE title = 'Logged Series'")['slug'];
        $me->postWithToken('/series/add/' . $slug, ['story_slug' => 'the-rabbit-hole']);
        $this->assertSame('series.add', $this->last()['action']);
        $me->postWithToken('/series/remove/' . $slug . '/the-rabbit-hole');
        $this->assertSame('series.remove', $this->last()['action']);
        $me->postWithToken('/lists/create', ['title' => 'Logged List', 'summary' => '', 'is_public' => '']);
        $this->assertSame('lists.create', $this->last()['action']);
        $me->postWithToken('/challenges/create', ['title' => 'Logged Challenge', 'summary' => '', 'membership' => 'open']);
        $this->assertSame('challenges.create', $this->last()['action']);
        $this->assertSame(['series.create', 'series.add', 'series.remove', 'lists.create', 'challenges.create'], $this->actions());
    }

    public function test_follow_kudos_favorite_mute_recommend_and_bookmark_are_logged_as_ids_only(): void
    {
        $fan = $this->client($this->otherId);
        $fan->postWithToken('/follow/author/' . $this->memberId);
        $fan->postWithToken('/kudos/add/the-rabbit-hole');
        $fan->postWithToken('/favorites/toggle/the-rabbit-hole');
        $fan->postWithToken('/favorites/toggle/the-rabbit-hole');
        $fan->postWithToken('/mute/add/demo-author');
        $fan->postWithToken('/recommend/add/the-rabbit-hole', ['note' => 'A private opinion.']);
        $fan->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', ['note' => 'my secret note']);
        $fan->postWithToken('/reader/bookmarkremove/the-rabbit-hole/1');
        $fan->postWithToken('/follow/stop/' . $this->memberId);
        $this->assertSame(['follows.add', 'kudos.add', 'favorites.add', 'favorites.remove', 'mutes.add', 'recommendations.add',
            'bookmarks.add', 'bookmarks.remove', 'follows.remove'], $this->actions());
        foreach ($this->rows() as $row) {
            $this->assertStringNotContainsString('private opinion', $row['detail']);
            $this->assertStringNotContainsString('secret note', $row['detail']);
            $this->assertSame('log-other', $row['actor_name']);
        }
    }

    public function test_preferences_profile_fields_and_notifications_are_logged_without_values(): void
    {
        $me = $this->client($this->otherId);
        $me->postWithToken('/account/prefs', ['bio' => 'Secret bio words', 'default_sort' => 'recent']);
        $this->assertSame(['account.prefs', ''], [$this->last()['action'], $this->last()['detail']]);
        $me->postWithToken('/account/support', ['support_url' => 'https://ko-fi.example/me']);
        $this->assertSame(['account.support', ''], [$this->last()['action'], $this->last()['detail']]);
        $me->postWithToken('/notifications/read');
        $this->assertSame('notifications.read_all', $this->last()['action']);
        $me->post('/reader/settings', ['theme' => 'dark', 'return_to' => '/']);
        $this->assertSame('reader.settings', $this->last()['action']);
    }

    public function test_contact_form_age_gate_and_reports_are_logged_without_the_text_or_address(): void
    {
        $guest = $this->client();
        $guest->postWithToken('/contact/send', ['name' => 'Visitor', 'email' => 'visitor@e.test', 'message' => 'Hello there friend', 'website' => '']);
        $row = $this->last();
        $this->assertSame(['contact.send', null], [$row['action'], $row['actor_id']]);
        $this->assertStringNotContainsString('visitor', $row['detail']);
        $this->assertStringNotContainsString('Hello', $row['detail']);
        $guest->post('/warning/accept', ['return_to' => '/']);
        $this->assertSame('agegate.accept', $this->last()['action']);
        $this->client($this->otherId)->postWithToken('/report/story/the-rabbit-hole', ['reason' => 'Private grievance text']);
        $this->assertSame(['reports.file_story', 'the-rabbit-hole'], [$this->last()['action'], $this->last()['detail']]);
    }

    public function test_a_throttled_sign_in_is_logged_with_the_reason_only(): void
    {
        $c = $this->client();
        for ($i = 0; $i < 6; $i++) $c->postWithToken('/auth/attempt', ['email' => 'target@e.test', 'password' => 'wrong']);
        $rows = array_values(array_filter($this->rows(), fn ($r) => $r['action'] === 'auth.signin_throttled'));
        $this->assertNotSame([], $rows);
        $this->assertNull($rows[0]['actor_id']);
        $this->assertSame('too many attempts', $rows[0]['detail']);
        $this->assertStringNotContainsString('target', json_encode($this->rows()));
    }

    public function test_a_burst_of_throttled_sign_ins_leaves_one_line(): void
    {
        $c = $this->client();
        for ($i = 0; $i < 15; $i++) $c->postWithToken('/auth/attempt', ['email' => 'target@e.test', 'password' => 'wrong']);
        $rows = array_filter($this->rows(), fn ($r) => $r['action'] === 'auth.signin_throttled');
        $this->assertCount(1, $rows, 'a bot hammering the form must not flood the log');
    }

    public function test_entries_older_than_a_year_are_pruned_and_the_page_says_so(): void
    {
        $this->db->query("INSERT INTO activity_log (actor_id, actor_name, action, detail, created_at) VALUES (NULL, '', 'auth.signout', 'old', datetime('now', '-400 days'))");
        $this->db->query("INSERT INTO activity_log (actor_id, actor_name, action, detail, created_at) VALUES (NULL, '', 'auth.signout', 'recent', datetime('now', '-300 days'))");
        \App\ActivityLog::prune($this->db);
        $details = array_column($this->rows(), 'detail');
        $this->assertNotContains('old', $details);
        $this->assertContains('recent', $details);
        $this->assertStringContainsString('older than one year', $this->client($this->adminId)->get('/activity')->body);
    }

    // ---- the viewer ----

    public function test_every_logged_action_has_a_readable_label_and_the_page_pages(): void
    {
        $this->client($this->adminId)->postWithToken('/adminmembers/lock/' . $this->otherId);
        $this->client()->postWithToken('/auth/attempt', ['email' => 'x@e.test', 'password' => 'nope']);
        $body = $this->client($this->adminId, ['items_per_page' => 1])->get('/activity')->body;
        $this->assertStringNotContainsString('activity.a.', $body, 'no raw lookup keys on the page');
        $this->assertStringContainsString('Failed sign-in', $body);
        $this->assertStringContainsString('rel="next"', $body);
    }

    public function test_every_action_code_in_the_code_has_a_label(): void
    {
        $en = require dirname(__DIR__, 3) . '/lang/en.php';
        $root = dirname(__DIR__, 3);
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!str_ends_with((string) $f, '.php') || str_contains((string) $f, '/Tests/')) continue;
            foreach (file((string) $f) as $line) {
                if (!str_contains($line, 'ActivityLog::') && !str_contains($line, 'logFor(')) continue;
                if (preg_match_all("/'([a-z_]+\\.[a-z_]+)'/", $line, $m)) array_push($found, ...$m[1]);
            }
        }
        $this->assertNotSame([], $found);
        foreach (array_unique($found) as $code) {
            if (str_ends_with($code, '_')) continue; // built at run time, e.g. queue.story_ + approve; labelled by hand
            $this->assertArrayHasKey('activity.a.' . $code, $en, "no label for {$code}");
        }
    }
}
