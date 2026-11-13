<?php // app/Features/Report/Tests/ReportRemoveTest.php
namespace App\Features\Report\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Removing reported content: takes it down, mails the author, closes the report. */
final class ReportRemoveTest extends TestCase
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
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-report-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-report-' . uniqid('', true);
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

    private function story(): int
    {
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES (?, ?, ?, ?, 2, 1)', ['Owned Tale', 'owned-tale', 'S.', $this->targetId]);
        return (int) $this->db->lastInsertId();
    }

    private function report(?int $storyId, ?int $reviewId): int
    {
        $this->db->query('INSERT INTO reports (reporter_id, story_id, review_id, reason) VALUES (?, ?, ?, ?)', [$this->heirId, $storyId, $reviewId, 'Spam spam']);
        return (int) $this->db->lastInsertId();
    }

    private function mail(): string
    {
        return is_file($this->root . '/mail.log') ? (string) file_get_contents($this->root . '/mail.log') : '';
    }

    public function test_removing_a_reported_story_soft_deletes_mails_and_closes(): void
    {
        $sid = $this->story();
        $rid = $this->report($sid, null);
        $page = $this->client($this->moderatorId)->get('/report/remove/' . $rid);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('action="/report/takedown/' . $rid . '"', $page->body);
        $res = $this->client($this->moderatorId)->postWithToken('/report/takedown/' . $rid, ['reason' => 'Plagiarised text']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertNotNull($this->db->one('SELECT deleted_at FROM stories WHERE id = ?', [$sid])['deleted_at']);
        $this->assertNotNull($this->db->one('SELECT resolved_at FROM reports WHERE id = ?', [$rid])['resolved_at']);
        $this->assertSame(404, $this->client()->get('/story/view/owned-tale')->status);
        $mail = $this->mail();
        $this->assertStringContainsString('leaving-member@e.test', $mail);
        $this->assertStringContainsString('Plagiarised text', $mail);
        $this->assertStringContainsString('Your story was removed', $mail);
    }

    public function test_an_empty_reason_sends_a_polite_default(): void
    {
        $rid = $this->report($this->story(), null);
        $this->client($this->moderatorId)->postWithToken('/report/takedown/' . $rid, ['reason' => '  ']);
        $this->assertStringContainsString("content policy", $this->mail());
    }

    public function test_removing_a_reported_review_deletes_it_and_mails_its_author(): void
    {
        $sid = $this->story();
        $this->db->query('INSERT INTO reviews (story_id, user_id, body) VALUES (?, ?, ?)', [$sid, $this->heirId, 'Rude words']);
        $review = (int) $this->db->lastInsertId();
        $rid = $this->report(null, $review);
        $this->assertSame(302, $this->client($this->adminId)->postWithToken('/report/takedown/' . $rid, ['reason' => ''])->status);
        $this->assertNull($this->db->one('SELECT id FROM reviews WHERE id = ?', [$review]));
        $this->assertStringContainsString('heir-member@e.test', $this->mail());
        $this->assertStringContainsString('Your review was removed', $this->mail());
    }

    public function test_takedown_skips_placeholder_addresses(): void
    {
        $this->db->query("UPDATE users SET email = ? WHERE id = ?", ["imported-7@wxr-import.invalid", $this->targetId]);
        $rid = $this->report($this->story(), null);
        $this->assertSame(302, $this->client($this->moderatorId)->postWithToken("/report/takedown/" . $rid, ["reason" => "x"])->status);
        $this->assertSame("", $this->mail());
    }

    public function test_dismiss_still_works_and_sends_nothing(): void
    {
        $sid = $this->story();
        $rid = $this->report($sid, null);
        $this->assertSame(302, $this->client($this->moderatorId)->postWithToken('/report/resolve/' . $rid . '/dismiss')->status);
        $this->assertNotNull($this->db->one('SELECT resolved_at FROM reports WHERE id = ?', [$rid])['resolved_at']);
        $this->assertNull($this->db->one('SELECT deleted_at FROM stories WHERE id = ?', [$sid])['deleted_at']);
        $this->assertSame('', $this->mail());
    }

    public function test_queue_links_to_the_removal_page(): void
    {
        $rid = $this->report($this->story(), null);
        $this->assertStringContainsString('href="/report/remove/' . $rid . '"', $this->client($this->moderatorId)->get('/queue')->body);
    }

    public function test_members_guests_missing_tokens_and_closed_reports_are_refused(): void
    {
        $sid = $this->story();
        $rid = $this->report($sid, null);
        $this->assertSame(403, $this->client($this->heirId)->postWithToken('/report/takedown/' . $rid)->status);
        $this->assertSame(403, $this->client($this->heirId)->get('/report/remove/' . $rid)->status);
        $this->assertSame(403, $this->client($this->moderatorId)->post('/report/takedown/' . $rid)->status);
        $this->assertNull($this->db->one('SELECT deleted_at FROM stories WHERE id = ?', [$sid])['deleted_at']);
        $this->assertSame(404, $this->client($this->moderatorId)->postWithToken('/report/takedown/99999')->status);
        $this->client($this->moderatorId)->postWithToken('/report/resolve/' . $rid . '/dismiss');
        $this->assertSame(404, $this->client($this->moderatorId)->postWithToken('/report/takedown/' . $rid)->status, 'a closed report cannot be reused');
    }

    public function test_removing_a_guest_review_deletes_it_and_mails_nobody(): void
    {
        $sid = $this->story();
        $this->db->query("INSERT INTO reviews (story_id, user_id, guest_name, body) VALUES (?, NULL, 'Visitor', 'Rude words')", [$sid]);
        $review = (int) $this->db->lastInsertId();
        $rid = $this->report(null, $review);
        $this->assertSame(302, $this->client($this->moderatorId)->postWithToken('/report/takedown/' . $rid, ['reason' => 'Abuse'])->status);
        $this->assertNull($this->db->one('SELECT id FROM reviews WHERE id = ?', [$review]));
        $this->assertSame('', $this->mail());
    }

    public function test_every_open_report_on_a_removed_story_is_closed(): void
    {
        $sid = $this->story();
        $first = $this->report($sid, null);
        $this->db->query('INSERT INTO reports (reporter_id, story_id, reason) VALUES (?, ?, ?)', [$this->moderatorId, $sid, 'Also spam']);
        $second = (int) $this->db->lastInsertId();
        $this->client($this->moderatorId)->postWithToken('/report/takedown/' . $first, ['reason' => 'Plagiarised']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM reports WHERE story_id = ? AND resolved_at IS NULL', [$sid])['c']);
        $this->assertSame(404, $this->client($this->moderatorId)->postWithToken('/report/takedown/' . $second)->status, 'the sibling report cannot take it down twice');
    }

    public function test_a_reason_with_template_markers_is_sent_as_typed(): void
    {
        $rid = $this->report($this->story(), null);
        $this->client($this->moderatorId)->postWithToken('/report/takedown/' . $rid, ['reason' => 'Copied. Ref {title} and {what}']);
        $this->assertStringContainsString('Copied. Ref {title} and {what}', $this->mail());
    }

    public function test_the_confirm_page_shows_what_was_reported_and_refuses_closed_reports(): void
    {
        $sid = $this->story();
        $this->db->query('INSERT INTO reviews (story_id, user_id, body) VALUES (?, ?, ?)', [$sid, $this->heirId, 'Quoted review text']);
        $rid = $this->report(null, (int) $this->db->lastInsertId());
        $page = $this->client($this->moderatorId)->get('/report/remove/' . $rid);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Quoted review text', $page->body);
        $this->client($this->moderatorId)->postWithToken('/report/resolve/' . $rid . '/dismiss');
        $this->assertSame(404, $this->client($this->moderatorId)->get('/report/remove/' . $rid)->status);
        $this->assertSame(404, $this->client($this->moderatorId)->get('/report/remove/abc')->status);
    }
}
