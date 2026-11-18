<?php // app/Features/Queue/Tests/QueueDecisionMailTest.php
namespace App\Features\Queue\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Approving or rejecting a story or a member mails them a decision. */
#[Medium]
final class QueueDecisionMailTest extends TestCase
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
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-queuemail-');
        $this->root = sys_get_temp_dir() . '/kiption-queuemail-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->adminId = $this->user('roster-admin', 'admin');
        $this->targetId = $this->user('leaving-member', 'member');
        $this->heirId = $this->user('heir-member', 'member');
        $this->moderatorId = $this->user('roster-mod', 'moderator');
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

    private function pendingStory(string $slug = 'pending-tale'): int
    {
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES (?, ?, ?, ?, 2, 0)', ['Pending Tale', $slug, 'S.', $this->targetId]);
        return (int) $this->db->lastInsertId();
    }

    private function applicant(): int
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('hopeful@e.test', ?, 'hopeful', ?, NULL)", [\App\Tests\Support\Hashes::of('password123'), date('c')]);
        return (int) $this->db->lastInsertId();
    }

    private function mail(): string
    {
        return is_file($this->root . '/mail.log') ? (string) file_get_contents($this->root . '/mail.log') : '';
    }

    public function test_approving_a_story_mails_story_accepted(): void
    {
        $sid = $this->pendingStory();
        $this->assertSame(302, $this->client($this->moderatorId)->postWithToken('/queue/story/' . $sid . '/approve')->status);
        $mail = $this->mail();
        $this->assertStringContainsString('leaving-member@e.test', $mail);
        $this->assertStringContainsString('Your story Pending Tale was approved', $mail);
        $this->assertStringContainsString('https://archive.example/story/view/pending-tale', $mail);
    }

    public function test_rejecting_a_story_mails_the_reason_or_a_default(): void
    {
        $sid = $this->pendingStory();
        $this->client($this->moderatorId)->postWithToken('/queue/story/' . $sid . '/remove', ['confirmed' => '1', 'reason' => 'Needs a content warning']);
        $this->assertNotNull($this->db->one('SELECT deleted_at FROM stories WHERE id = ?', [$sid])['deleted_at']);
        $this->assertStringContainsString('Your story Pending Tale was not approved', $this->mail());
        $this->assertStringContainsString('Needs a content warning', $this->mail());
        @unlink($this->root . '/mail.log');
        $second = $this->pendingStory('pending-two');
        $this->client($this->moderatorId)->postWithToken('/queue/story/' . $second . '/remove', ['confirmed' => '1', 'reason' => '']);
        $this->assertStringContainsString('content policy', $this->mail());
    }

    public function test_the_no_script_confirm_page_keeps_the_chosen_reason_preset(): void
    {
        $sid = $this->pendingStory();
        $page = $this->client($this->moderatorId)->postWithToken('/queue/story/' . $sid . '/remove', ['reason' => '', 'reason_preset' => 'spam']);
        $this->assertSame(200, $page->status);
        $this->assertMatchesRegularExpression('/name="reason_preset"[^>]*value="spam"|value="spam"[^>]*name="reason_preset"/', $page->body);
        $this->client($this->moderatorId)->postWithToken('/queue/story/' . $sid . '/remove', ['confirmed' => '1', 'reason' => '', 'reason_preset' => 'spam']);
        $this->assertStringContainsString(\App\Lang::t('queue.preset_spam'), $this->mail());
    }

    public function test_removing_a_story_twice_mails_once(): void
    {
        $sid = $this->pendingStory();
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        $this->assertNotNull($repo->removeStory($sid));
        $this->assertNull($repo->removeStory($sid), 'the second removal changes nothing');
        $this->db->query('UPDATE stories SET deleted_at = NULL WHERE id = ?', [$sid]);
        $c = $this->client($this->moderatorId);
        $c->postWithToken('/queue/story/' . $sid . '/remove', ['confirmed' => '1', 'reason' => '']);
        $c->postWithToken('/queue/story/' . $sid . '/remove', ['confirmed' => '1', 'reason' => '']);
        $this->assertSame(1, substr_count($this->mail(), 'was not approved'));
    }

    public function test_rejecting_a_member_locks_them_and_mails_the_reason(): void
    {
        $id = $this->applicant();
        $this->assertSame(302, $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/reject', ['confirmed' => '1', 'reason' => 'We could not verify you']) ->status);
        $this->assertSame(1, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$id])['is_locked']);
        $mail = $this->mail();
        $this->assertStringContainsString('hopeful@e.test', $mail);
        $this->assertStringContainsString('About your membership request', $mail);
        $this->assertStringContainsString('We could not verify you', $mail);
    }

    public function test_rejecting_a_non_pending_or_non_member_id_changes_nothing(): void
    {
        $this->assertSame(302, $this->client($this->moderatorId)->postWithToken('/queue/member/' . $this->adminId . '/reject', ['confirmed' => '1', 'reason' => 'x'])->status);
        $this->assertSame(0, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$this->adminId])['is_locked']);
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $this->targetId . '/reject', ['confirmed' => '1', 'reason' => 'x']);
        $this->assertSame(0, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$this->targetId])['is_locked']);
        $this->assertSame('', $this->mail());
    }

    public function test_an_empty_member_reason_sends_a_polite_default(): void
    {
        $id = $this->applicant();
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/reject', ['confirmed' => '1']);
        $this->assertStringContainsString('requirements for new members', $this->mail());
    }

    public function test_an_edited_template_is_used(): void
    {
        $this->db->query("INSERT INTO mail_templates (name, subject, body) VALUES ('member_rejected', 'Sorry {penname}', 'No. {reason}')");
        $id = $this->applicant();
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/reject', ['confirmed' => '1', 'reason' => 'Full up']);
        $this->assertStringContainsString('Sorry hopeful', $this->mail());
        $this->assertStringContainsString('No. Full up', $this->mail());
    }

    public function test_approving_a_member_sends_no_rejection_and_the_forms_offer_a_reason_box(): void
    {
        $id = $this->applicant();
        $this->pendingStory();
        $c = $this->client($this->moderatorId);
        $this->assertSame(1, substr_count($c->get('/queue', ['tab' => 'stories'])->body, 'name="reason"'), 'a reason box on the story');
        $this->assertSame(1, substr_count($c->get('/queue', ['tab' => 'members'])->body, 'name="reason"'), 'a reason box on the member');
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/approve');
        $this->assertSame('', $this->mail());
    }

    public function test_members_cannot_decide(): void
    {
        $sid = $this->pendingStory();
        $this->assertSame(403, $this->client($this->heirId)->postWithToken('/queue/story/' . $sid . '/approve')->status);
        $this->assertSame('', $this->mail());
    }

    public function test_a_decision_without_a_token_changes_nothing_and_mails_nobody(): void
    {
        $sid = $this->pendingStory();
        $applicant = $this->applicant();
        foreach (['/queue/story/' . $sid . '/approve', '/queue/story/' . $sid . '/reject', '/queue/member/' . $applicant . '/reject'] as $url) {
            $this->assertSame(403, $this->client($this->moderatorId)->post($url)->status, $url);
        }
        $this->assertSame(0, (int) $this->db->one('SELECT validated FROM stories WHERE id = ?', [$sid])['validated']);
        $this->assertNull($this->db->one('SELECT deleted_at FROM stories WHERE id = ?', [$sid])['deleted_at']);
        $this->assertSame(0, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$applicant])['is_locked']);
        $this->assertSame('', $this->mail());
    }
    public function test_an_unknown_decision_is_refused_and_removes_nothing(): void
    {
        $sid = $this->pendingStory();
        $this->db->query("INSERT INTO chapters (story_id, position, title, content, validated) VALUES (?, 1, 'One', 'Text.', 0)", [$sid]);
        $cid = (int) $this->db->lastInsertId();
        $this->assertSame(404, $this->client($this->moderatorId)->postWithToken('/queue/story/' . $sid . '/bogus')->status);
        $this->assertSame(404, $this->client($this->moderatorId)->postWithToken('/queue/chapter/' . $cid . '/bogus')->status);
        $this->assertNull($this->db->one('SELECT deleted_at FROM stories WHERE id = ?', [$sid])['deleted_at']);
        $this->assertNotNull($this->db->one('SELECT id FROM chapters WHERE id = ?', [$cid]));
        $this->assertSame('', $this->mail());
    }

    public function test_removing_a_confirmed_chapter_deletes_it_and_keeps_the_story(): void
    {
        // The state-matrix cell chapter x remove: a hard delete that must
        // leave the story and its other chapters in place, log exactly once,
        // and survive a repeated click.
        $sid = $this->pendingStory();
        foreach ([1, 2, 3] as $pos) {
            $this->db->query('INSERT INTO chapters (story_id, position, title, content, validated) VALUES (?, ?, ?, ?, 0)',
                [$sid, $pos, 'Chapter ' . $pos, 'Text.']);
        }
        $mid = (int) $this->db->one('SELECT id FROM chapters WHERE story_id = ? AND position = 2', [$sid])['id'];
        $c = $this->client($this->moderatorId);
        $this->assertSame(302, $c->postWithToken('/queue/chapter/' . $mid . '/remove', ['confirmed' => '1', 'reason' => ''])->status);
        $this->assertNull($this->db->one('SELECT id FROM chapters WHERE id = ?', [$mid]), 'the chapter is gone');
        $this->assertNull($this->db->one('SELECT deleted_at FROM stories WHERE id = ?', [$sid])['deleted_at'], 'the story stays');
        $this->assertSame(
            [1, 2],
            array_map('intval', array_column($this->db->all('SELECT position FROM chapters WHERE story_id = ? ORDER BY position', [$sid]), 'position')),
            'later chapters slide down'
        );
        $c->postWithToken('/queue/chapter/' . $mid . '/remove', ['confirmed' => '1', 'reason' => '']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM activity_log WHERE action = 'queue.chapter_remove'")['c'], 'one log line, repeat clicks add none');
    }

    public function test_rejecting_a_pending_staff_member_changes_nothing(): void
    {
        // Staff rows can appear in the member queue with approved_at NULL;
        // reject only ever settles members.
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at) VALUES ('mod@e.test', ?, 'modhopeful', 'moderator', 0, ?, NULL)",
            [\App\Tests\Support\Hashes::of('password123'), date('c')]);
        $id = (int) $this->db->lastInsertId();
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/reject', ['confirmed' => '1', 'reason' => 'x']);
        $row = $this->db->one('SELECT is_locked, approved_at, role FROM users WHERE id = ?', [$id]);
        $this->assertSame(0, (int) $row['is_locked']);
        $this->assertNull($row['approved_at']);
        $this->assertSame('moderator', $row['role']);
        $this->assertSame('', $this->mail());
    }

    public function test_approving_a_pending_staff_member_dates_them_and_keeps_the_role(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at) VALUES ('mod2@e.test', ?, 'modhopeful2', 'moderator', 0, ?, NULL)",
            [\App\Tests\Support\Hashes::of('password123'), date('c')]);
        $id = (int) $this->db->lastInsertId();
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/approve');
        $row = $this->db->one('SELECT approved_at, role FROM users WHERE id = ?', [$id]);
        $this->assertNotNull($row['approved_at']);
        $this->assertSame('moderator', $row['role']);
    }

    public function test_approving_an_already_approved_member_is_a_no_op(): void
    {
        $id = $this->applicant();
        $this->db->query('UPDATE users SET approved_at = ? WHERE id = ?', ['2020-01-01T00:00:00Z', $id]);
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/approve');
        $this->assertSame('2020-01-01T00:00:00Z', $this->db->one('SELECT approved_at FROM users WHERE id = ?', [$id])['approved_at']);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM activity_log WHERE action = 'queue.member_approve'")['c'], 'a no-op approve logs nothing');
    }

    public function test_a_chosen_preset_reason_is_mailed_in_the_languages_words_and_typed_text_wins(): void
    {
        $sid = $this->pendingStory();
        $this->client($this->moderatorId)->postWithToken('/queue/story/' . $sid . '/remove', ['confirmed' => '1', 'reason_preset' => 'spam']);
        $this->assertStringContainsString(\App\Lang::t('queue.preset_spam'), $this->mail());
        $sid = $this->pendingStory('pending-two');
        $this->client($this->moderatorId)->postWithToken('/queue/story/' . $sid . '/remove', ['confirmed' => '1', 'reason_preset' => 'spam', 'reason' => 'Own words here']);
        $this->assertStringContainsString('Own words here', $this->mail());
        $sid = $this->pendingStory('pending-three');
        $this->client($this->moderatorId)->postWithToken('/queue/story/' . $sid . '/remove', ['confirmed' => '1', 'reason_preset' => '<script>']);
        $this->assertStringContainsString(\App\Lang::t('queue.default_story_reason'), $this->mail(), 'an unknown preset key is ignored, never echoed');
    }
}
