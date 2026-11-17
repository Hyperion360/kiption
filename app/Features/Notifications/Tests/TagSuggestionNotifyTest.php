<?php // app/Features/Notifications/Tests/TagSuggestionNotifyTest.php
namespace App\Features\Notifications\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\Tests\Support\TaxonomyCase;

/** Slice 4 Task 4.7: the author hears what happened to their suggestion. */
#[Medium]
final class TagSuggestionNotifyTest extends TaxonomyCase
{
    private int $member2 = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('notify-m2@e.test', ?, 'notifytwo', ?, ?)",
            [\App\Tests\Support\Hashes::of('password123'), date('c'), date('c')]);
        $this->member2 = (int) $this->db->lastInsertId();
    }

    private function pendingRow(int $storyId, int $by, string $name): int
    {
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $this->row("SELECT id FROM tag_types WHERE name = 'genre'")['id'], $name, $by, $storyId, 'pending', date('c')]);
        return (int) $this->db->lastInsertId();
    }

    public function test_approving_notifies_each_distinct_suggester_once(): void
    {
        $a = $this->makeStory('notify-a');
        $b = $this->makeStory('notify-b');
        $c = $this->makeStory('notify-c');
        $this->pendingRow($a, $this->memberId, 'Notified Marker');
        $this->pendingRow($b, $this->memberId, 'Notified Marker'); // same suggester, second story
        $this->pendingRow($c, $this->member2, 'Notified Marker'); // a second suggester
        $this->admin()->postWithToken('/tagqueue/approve', ['type_id' => (string) $this->row("SELECT id FROM tag_types WHERE name = 'genre'")['id'], 'name' => 'Notified Marker']);
        $rows = $this->db->all("SELECT user_id, kind, story_id, story_title FROM notifications WHERE kind = 'tag_suggestion_approved' ORDER BY user_id, story_id");
        $this->assertCount(2, $rows, 'two distinct suggesters, one notification each');
        $this->assertSame($this->memberId, (int) $rows[0]['user_id']);
        $this->assertSame('Notified Marker', $rows[0]['story_title']);
        $this->assertSame($this->member2, (int) $rows[1]['user_id']);
    }

    public function test_rejecting_notifies_the_suggester(): void
    {
        $story = $this->makeStory('notify-reject');
        $this->pendingRow($story, $this->memberId, 'Rejected Notify');
        $this->admin()->postWithToken('/tagqueue/reject', ['type_id' => (string) $this->row("SELECT id FROM tag_types WHERE name = 'genre'")['id'], 'name' => 'Rejected Notify']);
        $row = $this->row("SELECT user_id, story_id FROM notifications WHERE kind = 'tag_suggestion_rejected'");
        $this->assertNotNull($row);
        $this->assertSame($this->memberId, (int) $row['user_id']);
    }

    public function test_an_admin_who_suggested_does_not_notify_themselves(): void
    {
        $story = $this->makeStory('notify-self');
        $this->pendingRow($story, $this->adminId, 'Self Marker');
        $this->admin()->postWithToken('/tagqueue/approve', ['type_id' => (string) $this->row("SELECT id FROM tag_types WHERE name = 'genre'")['id'], 'name' => 'Self Marker']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM notifications WHERE user_id = ?", [$this->adminId]), 'no self-notification');
    }

    public function test_the_inbox_renders_both_kinds_escaped(): void
    {
        $story = $this->makeStory('notify-render');
        $this->pendingRow($story, $this->memberId, 'Render <b>Marker</b>');
        $this->admin()->postWithToken('/tagqueue/reject', ['type_id' => (string) $this->row("SELECT id FROM tag_types WHERE name = 'genre'")['id'], 'name' => 'Render <b>Marker</b>']);
        $notified = $this->makeStory('notify-render-ok');
        $this->pendingRow($notified, $this->memberId, 'Render Good Marker');
        $this->admin()->postWithToken('/tagqueue/approve', ['type_id' => (string) $this->row("SELECT id FROM tag_types WHERE name = 'genre'")['id'], 'name' => 'Render Good Marker']);
        $body = $this->client($this->memberId)->get('/notifications')->body;
        $this->assertStringContainsString('Render Good Marker', $body, 'the tag name shows');
        $this->assertStringContainsString('Notify Render', $body, 'the story title shows');
        $this->assertStringContainsString('was added', $body);
        $this->assertStringContainsString('was not added', $body);
        $this->assertStringContainsString('&lt;b&gt;', $body, 'the hostile name is escaped');
        $this->assertStringNotContainsString('Render <b>Marker</b>', $body, 'no raw markup');
    }

    public function test_the_inbox_stays_one_query(): void
    {
        $story = $this->makeStory('notify-budget');
        $this->pendingRow($story, $this->memberId, 'Budget Notify');
        $this->admin()->postWithToken('/tagqueue/approve', ['type_id' => (string) $this->row("SELECT id FROM tag_types WHERE name = 'genre'")['id'], 'name' => 'Budget Notify']);
        $app = new \Kip\App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->path . '.mail', 'from' => 'noreply@localhost'],
            'uploads' => [sys_get_temp_dir() . '/kiption-tax-upl'],
            'static_cache' => ['dir' => $this->cacheDir],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $client = (new \Kip\Testing\TestClient($app))->actingAs($this->memberId);
        $queries = 0;
        \App\Tests\Support\QueryTap::on($app->container->make(\Kip\Database::class), function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?' || $sql === \App\SessionGuard::SQL) return;
            $queries++;
        });
        $res = $client->get('/notifications');
        \App\Tests\Support\QueryTap::off($app->container->make(\Kip\Database::class));
        $this->assertSame(200, $res->status);
        $this->assertLessThanOrEqual(1, $queries, "the inbox ran {$queries} queries, budget is 1");
    }

    private function makeStory(string $slug): int
    {
        $this->db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated) VALUES (?, ?, ?, 1, 1)",
            [ucwords(str_replace('-', ' ', $slug)), $slug, $this->authorId]);
        return (int) $this->db->lastInsertId();
    }
}
