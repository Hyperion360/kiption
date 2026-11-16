<?php // app/Features/Coauthor/Tests/CoauthorTest.php
namespace App\Features\Coauthor\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class CoauthorTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private Database $db;
    private int $otherUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-coa-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-coa-mail-') . '.log';
        touch($this->mailLog);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        // a full member to attach as coauthor (approved, verified, unlocked)
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('ow@e.test', ?, 'otherwriter', ?, ?, 'otherwriter')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->otherUserId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-coa-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ] + $extra);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    private function otherId(): int
    {
        return $this->otherUserId;
    }

    public function test_owner_adds_coauthor_who_gains_authoring_rights(): void
    {
        $owner = $this->client($this->authorId());
        $res = $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $this->assertSame(302, $res->status, $res->body);
        // byline shows both
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('href="/user/view/otherwriter"', $body);
        // the coauthor can now open the chapter form (previously RuntimeException -> 404)
        $co = $this->client($this->otherId());
        $this->assertSame(200, $co->get('/chapter/new/the-rabbit-hole')->status);
        // and can edit the story form
        $this->assertSame(200, $co->get('/story/edit/the-rabbit-hole')->status);
    }

    public function test_add_validates_penname_and_membership(): void
    {
        $owner = $this->client($this->authorId());
        $this->assertSame(422, $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'ghost'])->status);
        $this->assertSame(422, $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'Demo Author'])->status); // self
        // non-owner cannot add
        $co = $this->client($this->otherId());
        $this->assertSame(404, $co->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'betafriend'])->status);
    }

    public function test_coauthor_cannot_add_other_coauthors(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        // coauthors gain editing rights, NOT coauthor-management rights; the raw
        // POST must fail exactly like the hidden form section (defense in depth)
        $co = $this->client($this->otherId());
        $this->assertSame(404, $co->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM coauthors')['c'], 'guarded intake must store nothing');
    }

    public function test_coauthor_may_leave_and_owner_may_remove(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $co = $this->client($this->otherId());
        $this->assertSame(302, $co->postWithToken('/coauthor/leave/the-rabbit-hole')->status);
        $this->assertSame(404, $co->get('/chapter/new/the-rabbit-hole')->status);
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $this->assertSame(302, $owner->postWithToken('/coauthor/remove/the-rabbit-hole/' . $this->otherId())->status);
    }

    public function test_review_and_kudos_notify_author_and_coauthor(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $fan = $this->client($this->memberId());
        $fan->postWithToken('/kudos/add/the-rabbit-hole');
        $fan->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Both of you, bravo.', 'rating' => '', 'guest_name' => '']);
        $ownerInbox = $owner->get('/notifications')->body;
        $coInbox = $this->client($this->otherId())->get('/notifications')->body;
        $this->assertStringContainsString('left kudos', $ownerInbox);
        $this->assertStringContainsString('left kudos', $coInbox);
        $this->assertStringContainsString('reviewed', $coInbox);
    }

    public function test_added_coauthor_gets_email_and_notification(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $inbox = $this->client($this->otherId())->get('/notifications')->body;
        $this->assertStringContainsString('coauthor', $inbox);
        // unread is announced, not only drawn as an accent square (qa-full /a11y, WCAG 1.4.1)
        $this->assertStringContainsString('<span class="visually-hidden">Unread: </span>', $inbox);
        $mail = file_get_contents($this->mailLog);
        $this->assertStringContainsString('coauthor', $mail);
        $this->assertStringContainsString('The Rabbit Hole', $mail);
    }

    /** Task B6: a coauthor who is never emailed does not know they were
     *  added, so the refusal writes one null-actor row (the story title in
     *  the detail, never the address). The add itself still succeeds. */
    public function test_a_refused_coauthor_add_writes_one_activity_row(): void
    {
        $owner = $this->client($this->authorId(), ['mail_daily_cap' => 10]); // usable 0: the mail is refused
        $res = $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $this->assertSame(302, $res->status, 'the add still succeeds');
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM coauthors WHERE user_id = ?", [$this->otherUserId])['c']);
        $rows = $this->db->all("SELECT actor_id, action, detail FROM activity_log WHERE action = 'coauthor.refused'");
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['actor_id']);
        $this->assertStringContainsString('The Rabbit Hole', $rows[0]['detail'], 'the story title is named');
        $this->assertStringNotContainsString('ow@e.test', $rows[0]['detail'], 'the address never rides the log');
        $this->assertSame('', (string) file_get_contents($this->mailLog));
    }
}
