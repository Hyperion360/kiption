<?php // tests/SearchTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class SearchTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-search-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** Fresh handle on the shared temp file (the SeriesRepositoryTest shape
     *  via a method, so every call sees committed fixture state). */
    private function db(): Database
    {
        return new Database('sqlite:' . $this->path);
    }

    private function newApp(): App
    {
        return new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-search-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-search-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    /** Kept for Task 3's controller cases (the SeriesTest pattern: the factory
     *  keeps the App as $this->app for direct handle() drives). */
    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_fts_search_finds_title_summary_and_chapter_content(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $rows = $repo->search('rabbit', [], 20, 0, 0)['rows'];
        $this->assertSame('the-rabbit-hole', $rows[0]['slug'] ?? null, 'title hit');
        // chapter-only term (finding 8, probed: 'shelves' exists only in seeded chapter content)
        $rows = $repo->search('shelves', [], 20, 0, 0)['rows'];
        $this->assertContains('the-rabbit-hole', array_column($rows, 'slug'), 'chapter content hit surfaces the story');
    }

    public function test_search_gates_and_personalizes_restricted(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->db()->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $guest = $repo->search('rabbit', [], 20, 0, 0)['rows'];
        $this->assertSame([], $guest, 'restricted hidden from guests');
        $member = $repo->search('rabbit', [], 20, 0, 1)['rows'];
        $this->assertSame('the-rabbit-hole', $member[0]['slug'] ?? null, 'members see restricted');
        $this->db()->query("UPDATE stories SET is_restricted = 0, validated = 0 WHERE slug = 'the-rabbit-hole'");
        $this->assertSame([], $repo->search('rabbit', [], 20, 0, 1)['rows'], 'unvalidated never searched');
    }

    public function test_search_filters_category_rating_completed_language(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->assertSame([], $repo->search('rabbit', ['category' => 'nope'], 20, 0, 0)['rows']);
        $rows = $repo->search('rabbit', ['category' => 'general'], 20, 0, 0)['rows'];
        $this->assertSame('the-rabbit-hole', $rows[0]['slug'] ?? null);
        $teen = (int) $this->db()->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
        $this->assertSame('the-rabbit-hole', $repo->search('rabbit', ['rating_id' => $teen], 20, 0, 0)['rows'][0]['slug'] ?? null);
        $this->assertSame([], $repo->search('rabbit', ['rating_id' => $teen + 999], 20, 0, 0)['rows']);
        $this->assertSame('the-rabbit-hole', $repo->search('rabbit', ['completed' => false], 20, 0, 0)['rows'][0]['slug'] ?? null, 'WIP story');
        $this->assertSame([], $repo->search('rabbit', ['completed' => true], 20, 0, 0)['rows']);
        $this->db()->query("UPDATE stories SET language = 'pt-BR' WHERE slug = 'the-rabbit-hole'");
        $this->assertSame([], $repo->search('rabbit', ['language' => 'en'], 20, 0, 0)['rows']);
        $this->assertSame('the-rabbit-hole', $repo->search('rabbit', ['language' => 'pt-BR'], 20, 0, 0)['rows'][0]['slug'] ?? null);
    }

    public function test_search_junk_query_and_overlong_input(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->assertSame(['rows' => [], 'hasMore' => false, 'mode' => 'none'], $repo->search('   ', [], 20, 0, 0));
        $this->assertSame(['rows' => [], 'hasMore' => false, 'mode' => 'none'], $repo->search('"', [], 20, 0, 0), 'quote-only input sanitizes to nothing');
        $many = $repo->search('one two three four five six seven eight nine ten', [], 20, 0, 0);
        $this->assertContains($many['mode'], ['fts', 'like']);
    }

    public function test_like_fallback_path_searches_and_escapes(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $rows = $repo->searchLike('rabbit', [], 20, 0, 0)['rows'];
        $this->assertSame('the-rabbit-hole', $rows[0]['slug'] ?? null);
        // a literal % must not match everything
        $this->assertSame([], $repo->searchLike('%%%', [], 20, 0, 0)['rows']);
        $this->assertSame([], $repo->searchLike('rabbit', ['language' => 'zz'], 20, 0, 0)['rows']);
    }
}
