<?php // app/Features/Queue/Tests/QueueDecisionMailTest.php
namespace App\Features\Queue\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Approving or rejecting a story or a member mails them a decision. */
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
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-queuemail-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-queuemail-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
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
            ["$name@e.test", password_hash('password123', PASSWORD_DEFAULT), $name, $role, $role === 'admin' ? 1 : 0, date('c'), date('c'), $name]);
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
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('hopeful@e.test', ?, 'hopeful', ?, NULL)", [password_hash('password123', PASSWORD_DEFAULT), date('c')]);
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
        $this->client($this->moderatorId)->postWithToken('/queue/story/' . $sid . '/remove', ['reason' => 'Needs a content warning']);
        $this->assertNotNull($this->db->one('SELECT deleted_at FROM stories WHERE id = ?', [$sid])['deleted_at']);
        $this->assertStringContainsString('Your story Pending Tale was not approved', $this->mail());
        $this->assertStringContainsString('Needs a content warning', $this->mail());
        @unlink($this->root . '/mail.log');
        $second = $this->pendingStory('pending-two');
        $this->client($this->moderatorId)->postWithToken('/queue/story/' . $second . '/remove', ['reason' => '']);
        $this->assertStringContainsString('content policy', $this->mail());
    }

    public function test_rejecting_a_member_locks_them_and_mails_the_reason(): void
    {
        $id = $this->applicant();
        $this->assertSame(302, $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/reject', ['reason' => 'We could not verify you']) ->status);
        $this->assertSame(1, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$id])['is_locked']);
        $mail = $this->mail();
        $this->assertStringContainsString('hopeful@e.test', $mail);
        $this->assertStringContainsString('About your membership request', $mail);
        $this->assertStringContainsString('We could not verify you', $mail);
    }

    public function test_rejecting_a_non_pending_or_non_member_id_changes_nothing(): void
    {
        $this->assertSame(302, $this->client($this->moderatorId)->postWithToken('/queue/member/' . $this->adminId . '/reject', ['reason' => 'x'])->status);
        $this->assertSame(0, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$this->adminId])['is_locked']);
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $this->targetId . '/reject', ['reason' => 'x']);
        $this->assertSame(0, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$this->targetId])['is_locked']);
        $this->assertSame('', $this->mail());
    }

    public function test_an_empty_member_reason_sends_a_polite_default(): void
    {
        $id = $this->applicant();
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/reject');
        $this->assertStringContainsString('requirements for new members', $this->mail());
    }

    public function test_an_edited_template_is_used(): void
    {
        $this->db->query("INSERT INTO mail_templates (name, subject, body) VALUES ('member_rejected', 'Sorry {penname}', 'No. {reason}')");
        $id = $this->applicant();
        $this->client($this->moderatorId)->postWithToken('/queue/member/' . $id . '/reject', ['reason' => 'Full up']);
        $this->assertStringContainsString('Sorry hopeful', $this->mail());
        $this->assertStringContainsString('No. Full up', $this->mail());
    }

    public function test_approving_a_member_sends_no_rejection_and_the_forms_offer_a_reason_box(): void
    {
        $id = $this->applicant();
        $this->pendingStory();
        $body = $this->client($this->moderatorId)->get('/queue')->body;
        $this->assertSame(2, substr_count($body, 'name="reason"'), 'one reason box on the story, one on the member');
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
}
