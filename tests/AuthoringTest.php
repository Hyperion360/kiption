<?php // tests/AuthoringTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class AuthoringTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-auth2-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides);
    }

    private function clientAs(int $userId, array $overrides = []): TestClient
    {
        return (new TestClient(new App($this->config($overrides))))->actingAs($userId);
    }

    /** A second, plain member (goes through the queue). */
    private function memberId(): int
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('m2@e.test', ?, 'plainmember', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        return (int) $this->db->lastInsertId();
    }

    private function ratingId(): int
    {
        return (int) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
    }

    private function categoryId(): int
    {
        return (int) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id'];
    }

    public function test_story_form_renders_for_author(): void
    {
        $res = $this->clientAs(1)->get('/story/new');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<form', $res->body);
        $this->assertStringContainsString('name="title"', $res->body);
        $this->assertStringContainsString('name="rating_id"', $res->body); // ratings reach the form
        $this->assertStringContainsString('name="categories[]"', $res->body);
    }

    public function test_story_requires_login(): void
    {
        $res = (new TestClient(new App($this->config())))->get('/story/new');
        $this->assertSame(302, $res->status);
    }

    public function test_validated_author_bypasses_queue(): void
    {
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'Fresh Work', 'summary' => 'A new tale.', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringEndsWith('/story/edit/fresh-work', $res->headers['Location']);
        $row = $this->db->one("SELECT * FROM stories WHERE title = 'Fresh Work'");
        $this->assertSame(1, (int) $row['validated']);
        $this->assertSame('fresh-work', $row['slug']);
        $this->assertSame(1, (int) $this->db->one(
            'SELECT COUNT(*) c FROM story_categories WHERE story_id = ?', [$row['id']])['c']);
    }

    public function test_member_goes_through_queue(): void
    {
        $res = $this->clientAs($this->memberId())->postWithToken('/story/create',
            ['title' => 'Queued Work', 'summary' => 'S.', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT * FROM stories WHERE title = 'Queued Work'");
        $this->assertSame(0, (int) $row['validated']);
        $res = (new TestClient(new App($this->config())))->get('/story/view/queued-work'); // anonymous
        $this->assertSame(404, $res->status);
    }

    public function test_slug_deduplication(): void
    {
        $res = $this->clientAs(1)->postWithToken('/story/create',
            ['title' => 'The Rabbit Hole', 'summary' => 'x', 'rating_id' => (string) $this->ratingId(), 'categories' => [(string) $this->categoryId()]]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertStringEndsWith('/story/edit/the-rabbit-hole-2', $res->headers['Location']);
    }

    public function test_edit_requires_ownership(): void
    {
        $other = $this->memberId();
        $client = $this->clientAs($other);
        $this->assertSame(404, $client->get('/story/edit/the-rabbit-hole')->status);
        $this->assertSame(404, $client->postWithToken('/story/delete/the-rabbit-hole')->status);
        $this->assertNull($this->db->one("SELECT deleted_at FROM stories WHERE slug = 'the-rabbit-hole'")['deleted_at']);
    }

    public function test_update_swaps_categories_and_touches_updated_at(): void
    {
        $before = $this->db->one("SELECT updated_at FROM stories WHERE slug = 'the-rabbit-hole'")['updated_at'];
        sleep(1); // distinct seconds make the timestamp assertion legible
        $res = $this->clientAs(1)->postWithToken('/story/update/the-rabbit-hole',
            ['title' => 'The Rabbit Hole', 'summary' => 'A slow fall into a stranger world.',
             'rating_id' => (string) $this->ratingId(), 'categories' => [], 'completed' => '1']);
        $this->assertSame(302, $res->status, $res->body);
        $row = $this->db->one("SELECT completed, updated_at FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(1, (int) $row['completed']);
        $this->assertNotSame($before, $row['updated_at']);
        $this->assertSame(0, (int) $this->db->one(
            'SELECT COUNT(*) c FROM story_categories WHERE story_id = (SELECT id FROM stories WHERE slug = \'the-rabbit-hole\')')['c']);
        // restore the category for later tasks/tests
        $this->db->query('INSERT INTO story_categories (story_id, category_id)
                          SELECT id, ? FROM stories WHERE slug = \'the-rabbit-hole\'', [$this->categoryId()]);
    }

    public function test_delete_is_soft_and_purges(): void
    {
        $cacheDir = dirname(__DIR__) . '/public/cache';
        @mkdir($cacheDir . '/story/view/the-rabbit-hole', 0775, true);
        file_put_contents($cacheDir . '/story/view/the-rabbit-hole/index.html', 'stale');
        $res = $this->clientAs(1)->postWithToken('/story/delete/the-rabbit-hole');
        $this->assertSame(302, $res->status);
        $this->assertNotNull($this->db->one("SELECT deleted_at FROM stories WHERE slug = 'the-rabbit-hole'")['deleted_at']);
        $this->assertFileDoesNotExist($cacheDir . '/story/view/the-rabbit-hole/index.html');
        // restore for other tests
        $this->db->query("UPDATE stories SET deleted_at = NULL WHERE slug = 'the-rabbit-hole'");
    }
}
