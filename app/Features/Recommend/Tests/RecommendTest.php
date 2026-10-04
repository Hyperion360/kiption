<?php // app/Features/Recommend/Tests/RecommendTest.php
namespace App\Features\Recommend\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Member recommendations (the eFiction parity surface): a validated member
 *  recommends a story with an optional one-line note; the row shows on their
 *  profile and bumps a public count on the story page, the author is
 *  notified once, and removal reverses all three. The page renders stay
 *  inside the one-query budget: the rec fold rides the EXISTING profile and
 *  story statements as correlated scalar subqueries, pinned by
 *  QueryBudgetTest's /user/view/* and /story/view/* rows. */
final class RecommendTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0; // betafriend, a validated member
    private int $authorId = 0; // Demo Author, owns the seeded stories

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rec-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->authorId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(int $as, array $extra = []): TestClient
    {
        return (new TestClient(new App($extra + [
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-rec-mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-rec-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->actingAs($as);
    }

    public function test_recommend_lands_on_profile_story_and_inbox(): void
    {
        $res = $this->client($this->memberId)->postWithToken('/recommend/add/the-rabbit-hole', ['note' => 'A perfect descent.']);
        $this->assertSame(302, $res->status);
        $row = $this->db->one('SELECT * FROM recommendations');
        $this->assertNotNull($row, 'the recommendation row landed');
        $this->assertSame($this->memberId, (int) $row['user_id']);
        $this->assertSame('A perfect descent.', (string) $row['note']);
        // The profile's new Recommended section lists the story with the note.
        $profile = $this->client($this->memberId)->get('/user/view/betafriend');
        $this->assertSame(200, $profile->status);
        $this->assertStringContainsString(\App\Lang::t('user.recommended_heading'), (string) $profile->body);
        $this->assertStringContainsString('The Rabbit Hole', (string) $profile->body);
        $this->assertStringContainsString('A perfect descent.', (string) $profile->body);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', (string) $profile->body);
        // The story page shows the public count; the member form carries the token.
        $story = $this->client($this->memberId)->get('/story/view/the-rabbit-hole');
        $this->assertSame(200, $story->status);
        $this->assertStringContainsString(\App\Lang::t('story.rec_count_one'), (string) $story->body);
        $this->assertStringContainsString('action="/recommend/add/the-rabbit-hole"', (string) $story->body);
        $this->assertStringContainsString('action="/recommend/remove/the-rabbit-hole"', (string) $story->body);
        // Guests see the count but never the member form.
        $guest = (new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app', 'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-rec-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ])))->get('/story/view/the-rabbit-hole');
        $this->assertStringContainsString(\App\Lang::t('story.rec_count_one'), (string) $guest->body);
        $this->assertStringNotContainsString('/recommend/add/', (string) $guest->body);
        // The author receives ONE inbox notification, the Notifications::create shape.
        $n = $this->db->one("SELECT * FROM notifications WHERE kind = 'recommendation'");
        $this->assertNotNull($n, 'the author was notified');
        $this->assertSame($this->authorId, (int) $n['user_id']);
        $this->assertSame($this->memberId, (int) $n['actor_id']);
        $this->assertSame('The Rabbit Hole', (string) $n['story_title']);
        $this->assertNotNull($n['story_id']);
    }

    public function test_duplicate_recommend_is_a_no_op_without_a_second_notification(): void
    {
        $client = $this->client($this->memberId);
        $client->postWithToken('/recommend/add/the-rabbit-hole', ['note' => 'first']);
        $this->assertSame(302, $client->postWithToken('/recommend/add/the-rabbit-hole', ['note' => 'again'])->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM recommendations')['c'], 'ON CONFLICT DO NOTHING: no second row');
        $this->assertSame('first', (string) $this->db->one('SELECT note FROM recommendations')['note'], 'the no-op rerun never rewrites the note');
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM notifications WHERE kind = 'recommendation'")['c'], 'no duplicate notification');
    }

    public function test_remove_drops_the_row_the_profile_section_and_the_count(): void
    {
        $client = $this->client($this->memberId);
        $client->postWithToken('/recommend/add/the-rabbit-hole', ['note' => 'gone soon']);
        $this->assertSame(302, $client->postWithToken('/recommend/remove/the-rabbit-hole')->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM recommendations')['c']);
        $profile = $this->client($this->memberId)->get('/user/view/betafriend');
        $this->assertStringNotContainsString(\App\Lang::t('user.recommended_heading'), (string) $profile->body, 'the section renders only when it has content');
        $this->assertStringNotContainsString(\App\Lang::t('story.rec_count_one'), (string) $this->client($this->memberId)->get('/story/view/the-rabbit-hole')->body);
        // Removing with nothing to remove is a harmless redirect, never an error.
        $this->assertSame(302, $client->postWithToken('/recommend/remove/the-rabbit-hole')->status);
    }

    public function test_guests_hit_the_auth_redirect(): void
    {
        $guest = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app', 'env' => getenv('DBG_ENV') ?: 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        $this->assertSame(302, $guest->postWithToken('/recommend/add/the-rabbit-hole')->status);
        $this->assertSame(302, $guest->postWithToken('/recommend/remove/the-rabbit-hole')->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM recommendations')['c'], 'no row from a guest');
    }

    public function test_self_recommendation_lands_but_never_notifies(): void
    {
        $this->client($this->authorId)->postWithToken('/recommend/add/the-rabbit-hole');
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM recommendations')['c'], 'the row lands');
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM notifications WHERE kind = 'recommendation'")['c'],
            'the actor exclusion: an author recommending their own work never notifies themselves');
    }

    public function test_the_note_is_one_line_and_capped(): void
    {
        $client = $this->client($this->memberId);
        $client->postWithToken('/recommend/add/the-rabbit-hole', ['note' => "two\n \tlines   squashed"]);
        $this->assertSame('two lines squashed', (string) $this->db->one('SELECT note FROM recommendations')['note']);
        $client->postWithToken('/recommend/remove/the-rabbit-hole');
        $client->postWithToken('/recommend/add/the-rabbit-hole', ['note' => str_repeat('x', 300)]);
        $this->assertSame(200, mb_strlen((string) $this->db->one('SELECT note FROM recommendations')['note']));
    }

    public function test_the_31st_recommend_in_the_window_hits_the_rate_limit(): void
    {
        // The real map entry (config.php: 'recommend' => 30/60, keyed by the
        // route's FIRST URL SEGMENT). The 31st POST in the window is 429; the
        // limiter enforces before routing, so no-op reruns count like any hit.
        $client = $this->client($this->memberId, ['rate_limit' => ['recommend' => ['max' => 30, 'window' => 60]]]);
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame(302, $client->postWithToken('/recommend/add/the-rabbit-hole')->status, "hit {$i} passes");
        }
        $over = $client->postWithToken('/recommend/add/the-rabbit-hole');
        $this->assertSame(429, $over->status, 'the 31st recommend in the window is over max=30');
        $this->assertSame(31, (int) $this->db->one("SELECT hits FROM rate_limits WHERE prefix = 'Recommend'")['hits'],
            'the blocked hit still counts, stored under the studly-canonical prefix');
    }

    public function test_removing_a_story_cascades_the_recommendations(): void
    {
        $this->client($this->memberId)->postWithToken('/recommend/add/the-rabbit-hole');
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM recommendations')['c']);
        $this->db->query("DELETE FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM recommendations')['c'], 'ON DELETE CASCADE dropped the rows');
    }

    public function test_unknown_story_answers_404(): void
    {
        $this->assertSame(404, $this->client($this->memberId)->postWithToken('/recommend/add/no-such-story')->status);
        $this->assertSame(404, $this->client($this->memberId)->postWithToken('/recommend/remove/no-such-story')->status);
    }
}
