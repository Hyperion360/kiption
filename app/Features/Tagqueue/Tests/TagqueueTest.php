<?php // app/Features/Tagqueue/Tests/TagqueueTest.php
namespace App\Features\Tagqueue\Tests;
use App\Tests\Support\TaxonomyCase;

/** The admin queue of waiting tag suggestions at /tagqueue (Task 4.4). */
final class TagqueueTest extends TaxonomyCase
{
    private function typeId(string $name): int
    {
        return (int) $this->row('SELECT id FROM tag_types WHERE name = ?', [$name])['id'];
    }

    private function makeStory(string $slug): int
    {
        $this->db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated) VALUES (?, ?, ?, 1, 1)", [ucwords(str_replace('-', ' ', $slug)), $slug, $this->authorId]);
        return (int) $this->db->lastInsertId();
    }

    private function suggest(int $storyId, int $by, string $name): void
    {
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$this->typeId('genre'), $name, $by, $storyId, 'pending', date('c')]);
    }

    public function test_admin_queue_lists_a_name_once_with_its_story_count(): void
    {
        foreach (['queue-one', 'queue-two', 'queue-three'] as $slug) {
            $this->suggest($this->makeStory($slug), $this->memberId, 'Waiting Marker');
        }
        $this->suggest($this->makeStory('queue-four'), $this->memberId, 'Lone Marker');
        $body = $this->admin()->get('/tagqueue')->body;
        $this->assertSame(1, substr_count($body, '<h2 class="section-label">Waiting Marker'), 'the three stories fold into one queue row');
        $this->assertSame(1, substr_count($body, '<h2 class="section-label">Lone Marker'), 'each distinct name is its own row');
        $this->assertStringContainsString('Queue One', $body, 'the story titles ride the row');
        $this->assertStringContainsString('3', $body, 'the count shows');
        // no scripts on the admin page
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/', $body, 'no inline script');
    }

    public function test_queue_gates_and_noindex(): void
    {
        $res = $this->admin()->get('/tagqueue');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('noindex', $res->body, 'the meta robots rides the admin page');
        // the #[Auth] shape: a guest redirects to sign-in, a signed-in
        // non-admin is refused (the TagsController gate shape)
        $this->assertSame(302, $this->client()->get('/tagqueue')->status);
        $this->assertSame(403, $this->client($this->memberId)->get('/tagqueue')->status, 'members are denied');
    }

    public function test_queue_empty_state_and_nav_link(): void
    {
        $body = $this->admin()->get('/tagqueue')->body;
        $this->assertStringContainsString('No suggestions are waiting.', $body);
        // the sidebar nav lists the screen (group stories, no feature flag)
        $nav = $this->admin()->get('/manage')->body;
        $this->assertStringContainsString('/tagqueue', $nav);
    }

    public function test_queue_is_one_query(): void
    {
        $this->suggest($this->makeStory('queue-budget'), $this->memberId, 'Budget Marker');
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
        $client = (new \Kip\Testing\TestClient($app))->actingAs($this->adminId);
        $queries = 0;
        \App\Tests\Support\QueryTap::on($app->container->make(\Kip\Database::class), function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?' || $sql === 'SELECT password_hash, is_locked, role FROM users WHERE id = ?') return; // session validation, excluded by rule
            $queries++;
        });
        $res = $client->get('/tagqueue');
        \App\Tests\Support\QueryTap::off($app->container->make(\Kip\Database::class));
        $this->assertSame(200, $res->status);
        $this->assertLessThanOrEqual(1, $queries, "the queue ran {$queries} queries, budget is 1");
    }
}
