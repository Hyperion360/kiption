<?php // tests/WholeViewTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class WholeViewTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-whole-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function newApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-whole-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-whole-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    public function test_whole_view_renders_every_chapter_with_toc_anchors_and_print_styles(): void
    {
        $res = $this->client()->get('/story/whole/the-rabbit-hole');
        $this->assertSame(200, $res->status, $res->body);
        // EVERY seeded chapter title and its markdown-rendered prose
        foreach (['Down', 'Through', 'Up'] as $title) {
            $this->assertStringContainsString($title, $res->body, "chapter title {$title}");
        }
        $this->assertStringContainsString('<em>down</em>', $res->body, 'prose goes through Markdown::render');
        $this->assertStringContainsString('Through the little door', $res->body);
        $this->assertStringContainsString('Climbing back', $res->body);
        // TOC anchors: the contents list points at per-chapter section ids
        $this->assertStringContainsString('href="#ch-1"', $res->body);
        $this->assertStringContainsString('id="ch-1"', $res->body);
        $this->assertStringContainsString('href="#ch-3"', $res->body);
        $this->assertStringContainsString('id="ch-3"', $res->body);
        // print styles ride a media="print" stylesheet (no ?print= variant exists)
        $this->assertStringContainsString('media="print"', $res->body);
        $this->assertStringContainsString('/assets/print.css', $res->body);
        $this->assertStringNotContainsString('?print=', $res->body);
        // noindex always, canonical suppressed: chapters are the canonical
        // reading units, so the whole view never offers itself as one
        $this->assertStringContainsString('name="robots"', $res->body);
        $this->assertStringContainsString('noindex', $res->body);
        $this->assertStringNotContainsString('rel="canonical"', $res->body);
        $this->assertSame('noindex', $res->headers['X-Robots-Tag'] ?? '', 'the header belt to the meta suspenders');
        // per-chapter counting only: the read beacon does not embed here
        $this->assertStringNotContainsString('/beacon/read/', $res->body);
    }

    public function test_unvalidated_chapters_are_absent_from_the_whole_view(): void
    {
        $this->db()->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), 4, 'Hidden', 'Hidden draft prose.', 0, 2)");
        $res = $this->client()->get('/story/whole/the-rabbit-hole');
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('Hidden draft prose.', $res->body);
        $this->assertStringNotContainsString('href="#ch-4"', $res->body);
    }

    public function test_adult_whole_view_gated_without_the_cookie_and_reads_with_it(): void
    {
        $gate = $this->app->handle(new Request('GET', '/story/whole/after-hours', [], [], []));
        $this->assertSame(200, $gate->status);
        $this->assertStringContainsString('Contains explicit adult content.', $gate->body, 'the age-gate page renders');
        $this->assertStringNotContainsString('Body.', $gate->body, 'the prose stays behind the gate');
        $this->assertStringContainsString('return_to=/story/whole/after-hours', $gate->body, 'the gate returns to the whole view');
        $read = $this->app->handle(new Request('GET', '/story/whole/after-hours', [], [], ['age_ok' => '1']));
        $this->assertSame(200, $read->status);
        $this->assertStringContainsString('Body.', $read->body);
    }

    public function test_restricted_whole_view_404s_for_guests_and_renders_for_members(): void
    {
        $this->db()->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(404, $this->client()->get('/story/whole/the-rabbit-hole')->status);
        $this->assertSame(200, $this->client($this->memberId())->get('/story/whole/the-rabbit-hole')->status);
    }

    public function test_unknown_whole_slug_404s(): void
    {
        $this->assertSame(404, $this->client()->get('/story/whole/nope')->status);
    }

    public function test_story_view_links_the_whole_and_download_surfaces(): void
    {
        $res = $this->client()->get('/story/view/the-rabbit-hole');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="/story/whole/the-rabbit-hole"', $res->body);
        // the Task 6 route shape, rendered now with the honest href (404 until
        // the download action lands)
        $this->assertStringContainsString('href="/story/download/the-rabbit-hole/html"', $res->body);
    }

    public function test_whole_view_records_no_reading_progress(): void
    {
        // Note 15: there is no single position to record on a whole-work read,
        // so the view never touches reading_history (keeps the budget clean).
        $res = $this->client($this->memberId())->get('/story/whole/the-rabbit-hole');
        $this->assertSame(200, $res->status);
        $this->assertNull($this->db()->one('SELECT * FROM reading_history'));
    }

    public function test_print_css_hides_site_chrome_when_printing(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/print.css');
        $this->assertStringContainsString('@media print', $css);
        $this->assertStringContainsString('.site-head', $css, 'the site nav hides in print');
        $this->assertStringContainsString('.engagement-bar', $css, 'the engagement bar hides in print');
        $this->assertStringContainsString('display: none', $css);
    }
}
