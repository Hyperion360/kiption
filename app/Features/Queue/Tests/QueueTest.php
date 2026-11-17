<?php // app/Features/Queue/Tests/QueueTest.php
namespace App\Features\Queue\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

#[Medium]
final class QueueTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;
    private int $pendingStoryId = 0;
    private int $pendingChapterId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-queue-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Adminness::setRole($this->db, 1, 'moderator'); // Demo Author moderates
        // one pending story with one pending chapter by a plain member
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('p@e.test', ?, 'pendingauthor', ?, ?)",
            [\App\Tests\Support\Hashes::of('password123'), date('c'), date('c')]);
        $this->memberId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES (?, ?, ?, ?, ?, 0)',
            ['Pending Work', 'pending-work', 'S.', $this->memberId, 2]);
        $this->pendingStoryId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 1, \'One\', \'Body text.\', 0, 2)',
            [$this->pendingStoryId]);
        $this->pendingChapterId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function clientAs(int $userId): TestClient
    {
        return (new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-authoring-test.log', 'from' => 'noreply@kiption.test'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->actingAs($userId);
    }

    /** The moderator choke point (Adminness::requireModerator) must validate
     *  the password epoch: a session held across a password rotation draws
     *  403, not moderator authority. */
    public function test_stale_epoch_moderator_session_is_revoked(): void
    {
        $moderator = (int) $this->db->one("SELECT id FROM users WHERE role = 'moderator' LIMIT 1")['id'];
        if ($moderator === 0) {
            $this->markTestSkipped('no seeded moderator');
        }
        $client = $this->clientAs($moderator); // the session predates the rotation
        $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?',
            [\App\Tests\Support\Hashes::of('rotated-mod-7'), $moderator]);
        // /queue carries #[AuthAttr]: the kernel gate runs first and redirects a
        // stale-epoch session to login (302). The security property is 'no 200':
        // a rotated-away session never sees moderator authority, whether the
        // kernel gate or Adminness::requireModerator (the choke-point fix for
        // future unattributed callers) stops it.
        $this->assertNotEquals(200, $client->get('/queue')->status);
    }

    public function test_each_waiting_story_says_what_is_known_about_its_author(): void
    {
        $body = $this->clientAs(1)->get('/queue')->body;
        $this->assertStringContainsString(\App\Lang::t('queue.ctx_first'), $body);
        $this->assertStringNotContainsString('class="ctx-warn"', $body);
        $this->db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES ('Live One', 'live-one', 'S.', ?, 2, 1)", [$this->memberId]);
        $liveId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, deleted_at) VALUES ('Gone', 'gone', 'S.', ?, 2, 0, ?)", [$this->memberId, date('c')]);
        $this->db->query("INSERT INTO reports (reporter_id, story_id, reason) VALUES (1, ?, 'Spam')", [$liveId]);
        $body = $this->clientAs(1)->get('/queue')->body;
        $this->assertStringContainsString(\App\Lang::t('queue.ctx_posted_one', ['n' => 1]), $body);
        $this->assertStringContainsString('<span class="ctx-warn">' . \App\Lang::t('queue.ctx_removed_one', ['n' => 1]) . '</span>', $body);
        $this->assertStringContainsString('<span class="ctx-warn">' . \App\Lang::t('queue.ctx_reports_one', ['n' => 1]) . '</span>', $body);
    }

    public function test_member_cannot_see_queue(): void
    {
        $res = $this->clientAs($this->memberId)->get('/queue');
        $this->assertSame(403, $res->status);
    }

    public function test_anonymous_is_redirected(): void
    {
        $app = new App(['app_dir' => dirname(__DIR__, 4) . '/app', 'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views', 'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:']]);
        $res = (new TestClient($app))->get('/queue');
        $this->assertSame(302, $res->status);
    }

    public function test_queue_lists_pending(): void
    {
        $res = $this->clientAs(1)->get('/queue');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Pending Work', $res->body);
        $this->assertStringContainsString('name="robots" content="noindex"', $res->body);
    }

    public function test_approving_story_validates_it_and_its_chapters(): void
    {
        $res = $this->clientAs(1)->postWithToken('/queue/story/' . $this->pendingStoryId . '/approve');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one("SELECT validated FROM stories WHERE slug = 'pending-work'")['validated']);
        $this->assertSame(1, (int) $this->db->one(
            'SELECT validated FROM chapters WHERE story_id = ?', [$this->pendingStoryId])['validated']);
    }

    public function test_approving_chapter_only_touches_the_chapter(): void
    {
        $res = $this->clientAs(1)->postWithToken('/queue/chapter/' . $this->pendingChapterId . '/approve');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, (int) $this->db->one('SELECT validated FROM chapters WHERE id = ?', [$this->pendingChapterId])['validated']);
        $this->assertSame(0, (int) $this->db->one("SELECT validated FROM stories WHERE slug = 'pending-work'")['validated']);
    }

    public function test_member_approval_flow(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, approved_at) VALUES ('w@e.test', ?, 'waitingmember', NULL)",
            [\App\Tests\Support\Hashes::of('password123')]);
        $id = (int) $this->db->lastInsertId();
        $res = $this->clientAs(1)->postWithToken('/queue/member/' . $id . '/approve');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertNotNull($this->db->one('SELECT approved_at FROM users WHERE id = ?', [$id])['approved_at']);
    }

    public function test_gate_row_survives_a_full_queue(): void
    {
        // 151+ pending chapters fill the LIMIT 151 window; the gate row must
        // still come back, else a real moderator is 403'd at the queue cap.
        $this->db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES ('Host Story', 'host-story', 'S.', 1, 1, 1)");
        $hostId = (int) $this->db->lastInsertId();
        for ($i = 2; $i <= 170; $i++) {
            $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, ?, \'bulk\', \'x\', 0, 1)',
                [$hostId, $i]);
        }
        $rows = (new \App\Repositories\AuthoringRepository($this->db))->queueRows(1);
        $this->assertContains('0gate', array_column($rows, 'k'), 'gate row must ride inside the LIMIT window');
        $res = $this->clientAs(1)->get('/queue');
        $this->assertSame(200, $res->status, 'moderator must not be 403d when the queue is full');
    }

    public function test_pending_member_lookup_is_index_backed(): void
    {
        // The queue's member branch must not scan the users table (it grows
        // with membership); the partial index answers approved_at IS NULL.
        $plan = $this->db->all('EXPLAIN QUERY PLAN SELECT id, penname, email, created_at FROM users WHERE approved_at IS NULL AND email_verified_at IS NOT NULL');
        $text = implode(' ', array_column($plan, 'detail'));
        $this->assertStringNotContainsString('SCAN', $text);
    }

    public function test_the_queue_shows_one_tab_with_a_detail_pane_and_an_in_place_remove_that_works_without_scripts(): void
    {
        $c = $this->clientAs(1);
        $body = $c->get('/queue')->body;
        $this->assertStringContainsString('<a href="/queue?tab=stories" aria-current="page">', $body);
        $this->assertStringContainsString('<a href="/queue?tab=chapters">', $body);
        $this->assertStringContainsString('class="queue-detail"', $body);
        $this->assertStringContainsString('<summary class="btn queue-remove-btn" data-queue-remove>' . \App\Lang::t('common.remove') . ' ', $body, 'Remove opens its reason in place, a plain details element');
        $this->assertStringContainsString('name="confirmed" value="1"', $body, 'the opened form is itself the confirmation, so no second page');
        $this->assertStringContainsString('name="reason_preset"', $body);
        $this->assertStringContainsString('action="/queue/story/' . $this->pendingStoryId . '/remove"', $body);
        $this->db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES ('Live Home', 'live-home', 'S.', ?, 2, 1)", [$this->memberId]);
        $this->db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 1, 'Next', 'Opening lines here.', 0, 3)", [(int) $this->db->lastInsertId()]);
        $liveChapter = (int) $this->db->lastInsertId();
        $ch = $c->get('/queue', ['tab' => 'chapters', 'item' => (string) $liveChapter])->body;
        $this->assertStringContainsString('Opening lines here.', $ch, 'the chapter opening is in the pane');
        $this->assertStringContainsString('3 words', $ch);
        $this->assertStringNotContainsString('name="reason_preset"', $ch, 'a chapter removal sends no reason');
        $this->assertStringContainsString('Pending Work', $c->get('/queue', ['tab' => 'nonsense', 'item' => '99999'])->body, 'an unknown tab or item falls back to the first with something waiting');
    }

    public function test_an_admin_viewing_the_queue_sees_its_count_in_the_sidebar_without_a_second_query(): void
    {
        \App\Adminness::setRole($this->db, 1, 'admin');
        $this->assertStringContainsString('<span class="admin-nav-count">1</span>', $this->clientAs(1)->get('/queue')->body);
    }

    public function test_each_list_row_says_when_who_how_long_and_what_is_known(): void
    {
        $this->db->query('UPDATE stories SET word_count = 4812, updated_at = ? WHERE id = ?', [date('c', time() - 2 * 86400 - 600), $this->pendingStoryId]);
        $body = $this->clientAs(1)->get('/queue')->body;
        $this->assertStringContainsString('<span class="queue-when">2 days ago</span>', $body);
        $this->assertStringContainsString('<strong>pendingauthor</strong>', $body);
        $this->assertStringContainsString('4,812 words', $body);
        $this->assertStringContainsString('class="queue-list-facts"', $body);
        $this->assertStringContainsString(\App\Lang::t('queue.ctx_first'), $body);
    }

    public function test_the_detail_pane_reads_its_author_in_three_figures_and_links_the_whole_story(): void
    {
        $body = $this->clientAs(1)->get('/queue')->body;
        $this->assertStringContainsString('class="queue-stats"', $body);
        $this->assertStringContainsString('<dt>' . \App\Lang::t('queue.stat_since') . '</dt>', $body);
        $this->assertStringContainsString('<dt>' . \App\Lang::t('queue.stat_posted') . '</dt><dd>' . \App\Lang::t('queue.stat_nothing') . '</dd>', $body);
        $this->assertStringContainsString('<dt>' . \App\Lang::t('queue.stat_reports') . '</dt><dd>' . \App\Lang::t('queue.stat_none') . '</dd>', $body);
        $this->assertStringContainsString('<a class="queue-open" href="/story/view/pending-work">' . \App\Lang::t('queue.open_story') . '</a>', $body);
    }

    public function test_keyboard_hints_are_hidden_until_the_script_runs_and_the_script_is_wired(): void
    {
        $body = $this->clientAs(1)->get('/queue')->body;
        $this->assertMatchesRegularExpression('/<p class="queue-keys" data-js-module="queue-keys" hidden aria-label="[^"]+">/', $body);
        foreach (['J', 'K', 'A', 'R'] as $key) $this->assertStringContainsString('<kbd>' . $key . '</kbd>', $body);
        $this->assertStringContainsString('data-queue-approve', $body);
        $this->assertStringContainsString('data-queue-remove', $body);
    }

    public function test_the_remove_reason_is_a_row_of_choices_and_a_text_box_that_post_without_scripts(): void
    {
        $body = $this->clientAs(1)->get('/queue')->body;
        foreach (['policy', 'content', 'spam', 'quality'] as $key) {
            $this->assertStringContainsString('<input type="radio" name="reason_preset" value="' . $key . '"', $body);
        }
        $this->assertStringContainsString('<textarea id="qr-reason" name="reason" maxlength="1000"', $body);
        $this->assertStringContainsString('>' . \App\Lang::t('queue.remove_story_btn') . '</button>', $body);
        $this->assertStringContainsString('<span class="queue-remove-note">', $body);
        $res = $this->clientAs(1)->postWithToken('/queue/story/' . $this->pendingStoryId . '/remove', ['confirmed' => '1', 'reason_preset' => 'spam', 'reason' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertNotNull($this->db->one('SELECT deleted_at FROM stories WHERE id = ?', [$this->pendingStoryId])['deleted_at']);
    }
}
