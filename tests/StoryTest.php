<?php // tests/StoryTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class StoryTest extends TestCase
{
    private string $dsn = '';
    private App $app;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kiption-story-') . '.sqlite';
        $this->dsn = 'sqlite:' . $path;
        $db = new Database($this->dsn);
        (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
        $db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)', ['a@x.test', 'h', 'Demo Author']);
        $db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, 0, "", 1)', ['Teen']);
        $db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, 1, "Adult content ahead.", 2)', ['Explicit']);
        $db->query('INSERT INTO categories (name, slug) VALUES (?, ?)', ['General', 'general']);
        $db->query('INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, validated, completed, word_count) VALUES (?, ?, ?, ?, 1, 1, 1, 0, 300)',
            ['The Rabbit Hole', 'the-rabbit-hole', 'Falling, slowly.', 'Thanks for reading.']);
        $db->query('INSERT INTO chapters (story_id, position, title, notes_before, content, notes_after, validated, word_count) VALUES (1, 1, "Down", "A/N before.", "<p>Falling <em>down</em>.</p>", "", 1, 100)');
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, 2, "Through", "<p>Through the door.</p>", 1, 200)');
        $db->query('INSERT INTO story_categories (story_id, category_id) VALUES (1, 1)');
        $db->query("INSERT INTO series (title, slug, summary, owner_id, membership) VALUES ('Down the Rabbit Hole', 'down-the-rabbit-hole', 'The complete descent, chapter by chapter.', 1, 'open')");
        $db->query("INSERT INTO series_items (series_id, story_id, position, confirmed) VALUES (1, 1, 1, 1)");
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id, validated, completed, word_count) VALUES (?, ?, 1, 2, 1, 1, 100)',
            ['After Hours', 'after-hours']);
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (2, 1, "One", "<p>Body.</p>", 1, 100)');
        $this->app = new App([
            'env' => 'prod',
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => $this->dsn],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-story-upl'],
        ]);
    }

    protected function tearDown(): void
    {
        unset($this->app);
        @unlink(substr($this->dsn, 7));
        @unlink(substr($this->dsn, 7) . '-wal');
        @unlink(substr($this->dsn, 7) . '-shm');
    }

    public function test_story_landing_shows_metadata_and_toc(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('Demo Author', $res->body);
        $this->assertStringContainsString('/story/read/the-rabbit-hole/1', $res->body);
        $this->assertStringContainsString('Chapter 2', $res->body);
    }

    public function test_toc_renders_titles_with_delimiter_characters_intact(): void
    {
        // admin-CRUD-written chapter titles are free text; the TOC transport
        // must survive the characters a delimited blob would split on
        $db = new Database($this->dsn);
        $db->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, 3, ?, ?, 1, 444)',
            ['A|B~C', '<p>x</p>']);
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Chapter 3: A|B~C', $res->body);
        $this->assertStringContainsString('444 words', $res->body);
        $this->assertStringNotContainsString('Chapter 0', $res->body);
    }

    public function test_story_view_links_its_series(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/the-rabbit-hole', [], [], []));
        $this->assertStringContainsString('href="/series/view/down-the-rabbit-hole"', $res->body);
        $this->assertStringContainsString('Down the Rabbit Hole', $res->body);
    }

    public function test_unknown_story_404(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/nope', [], [], []));
        $this->assertSame(404, $res->status);
    }

    public function test_chapter_reads_with_nav(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/read/the-rabbit-hole/2', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Through the door.', $res->body);
        $this->assertStringContainsString('/story/read/the-rabbit-hole/1', $res->body); // prev
        $this->assertStringContainsString('Chapter 2 of 2', $res->body);
    }

    public function test_missing_chapter_404(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/read/the-rabbit-hole/9', [], [], []));
        $this->assertSame(404, $res->status);
    }

    public function test_adult_story_gated_without_cookie(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/read/after-hours/1', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Adult content ahead.', $res->body);
        $this->assertStringNotContainsString('Body.', $res->body);
    }

    public function test_adult_story_reads_with_consent_cookie(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/read/after-hours/1', [], [], ['age_ok' => '1']));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Body.', $res->body);
    }

    public function test_adult_landing_not_gated(): void
    {
        $res = $this->app->handle(new Request('GET', '/story/view/after-hours', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Explicit', $res->body);
    }
}
