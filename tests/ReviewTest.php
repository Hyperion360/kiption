<?php // tests/ReviewTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ReviewTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $fanId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rev-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('rv@e.test', ?, 'reviewfan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->fanId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null, array $overrides = []): TestClient
    {
        $app = new App(array_merge([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-rev-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-rev-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides));
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function storyId(): int
    {
        return (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
    }

    public function test_member_review_with_rating_shows_on_story_page(): void
    {
        $res = $this->client($this->fanId)->postWithToken('/review/add/the-rabbit-hole',
            ['body' => 'A *wonderful* fall.', 'rating' => '9', 'guest_name' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $body = $this->client($this->fanId)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('reviewfan', $body);
        $this->assertStringContainsString('<em>wonderful</em>', $body); // markdown rendered
        $this->assertStringContainsString('9/10', $body);
        $guestBody = $this->client()->get('/story/view/the-rabbit-hole')->body; // anonymous sees reviews too
        $this->assertStringContainsString('reviewfan', $guestBody);
    }

    public function test_guest_review_with_name(): void
    {
        $res = $this->client()->post('/review/add/the-rabbit-hole',
            ['body' => 'Guest says hi.', 'rating' => '', 'guest_name' => 'Casual Reader']);
        $this->assertSame(302, $res->status, $res->body);
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Casual Reader', $body);
    }

    public function test_guest_review_throttled_per_story_per_day(): void
    {
        $client = $this->client();
        $client->post('/review/add/the-rabbit-hole', ['body' => 'First.', 'rating' => '', 'guest_name' => 'G1']);
        $res = $client->post('/review/add/the-rabbit-hole', ['body' => 'Second.', 'rating' => '', 'guest_name' => 'G1']);
        $this->assertSame(429, $res->status, $res->body);
    }

    public function test_rating_clamped_and_body_required(): void
    {
        $res = $this->client($this->fanId)->postWithToken('/review/add/the-rabbit-hole',
            ['body' => 'x', 'rating' => '99', 'guest_name' => '']);
        $this->assertSame(302, $res->status); // stored, rating clamped to 10
        $this->assertSame(10, (int) $this->db->one('SELECT rating FROM reviews WHERE story_id = ?', [$this->storyId()])['rating']);
        $res = $this->client($this->fanId)->postWithToken('/review/add/the-rabbit-hole',
            ['body' => '', 'rating' => '5', 'guest_name' => '']);
        $this->assertSame(422, $res->status);
    }

    public function test_unknown_story_404s(): void
    {
        $this->assertSame(404, $this->client($this->fanId)->postWithToken('/review/add/nope', ['body' => 'x', 'rating' => '', 'guest_name' => ''])->status);
    }

    public function test_negative_rating_clamps_to_zero(): void
    {
        $res = $this->client($this->fanId)->postWithToken('/review/add/the-rabbit-hole',
            ['body' => 'Rough.', 'rating' => '-7', 'guest_name' => '']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(0, (int) $this->db->one('SELECT rating FROM reviews WHERE story_id = ?', [$this->storyId()])['rating']);
    }

    public function test_guest_name_required_and_capped(): void
    {
        $res = $this->client()->post('/review/add/the-rabbit-hole', ['body' => 'Hi.', 'rating' => '', 'guest_name' => '   ']);
        $this->assertSame(422, $res->status);
        $res = $this->client()->post('/review/add/the-rabbit-hole', ['body' => 'Hi.', 'rating' => '', 'guest_name' => str_repeat('n', 41)]);
        $this->assertSame(422, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM reviews')['c'], 'guarded intakes must store nothing');
    }
}
