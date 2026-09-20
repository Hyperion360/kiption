<?php // tests/MicroformatsTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class MicroformatsTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-mf2-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function config(): array
    {
        return [
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-mf2-test.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-mf2-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ];
    }

    private function client(): TestClient
    {
        return new TestClient(new App($this->config()));
    }

    public function test_profile_carries_an_h_card(): void
    {
        // The seeded profile has neither avatar nor bio, so set both to
        // exercise every h-card property on a real render.
        $this->db()->query("UPDATE users SET avatar_path = '/uploads/a.png', bio = 'I write things.' WHERE profile_slug = 'demo-author'");
        $res = $this->client()->get('/user/view/demo-author');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<section class="profile-card h-card">', $res->body);
        $this->assertStringContainsString('<h1 class="p-name">', $res->body);
        $this->assertStringContainsString('class="avatar u-photo"', $res->body);
        $this->assertStringContainsString('class="prose p-note"', $res->body);
        // Finding 17: the Profile tab is promoted from a span to the page's
        // own canonical link carrying u-url (it IS the current page).
        $this->assertStringContainsString('<a href="/user/view/demo-author" class="u-url">Profile</a>', $res->body);
    }

    public function test_chapter_read_carries_an_h_entry(): void
    {
        $res = $this->client()->get('/story/read/the-rabbit-hole/1');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<article class="h-entry">', $res->body);
        $this->assertStringContainsString('<h1 class="p-name">', $res->body);
        $this->assertStringContainsString('class="prose e-content"', $res->body);
        // Finding 17: u-url lands on the existing header anchor to the story
        // view URL (the entry's stable identity) and the plain-text byline is
        // wrapped in a URL-less h-card (legal mf2).
        $this->assertStringContainsString('<a class="u-url" href="/story/view/the-rabbit-hole">', $res->body);
        $this->assertStringContainsString('<span class="p-author h-card">Demo Author</span>', $res->body);
        // The two time elements ride the story timestamps already selected
        // by findStoryWithChapter (no query change).
        $this->assertStringContainsString('<time class="dt-published" datetime="2026-08-01T09:00:00Z">', $res->body);
        $this->assertStringContainsString('<time class="dt-updated" datetime="2026-09-10T09:00:00Z">', $res->body);
    }
}
