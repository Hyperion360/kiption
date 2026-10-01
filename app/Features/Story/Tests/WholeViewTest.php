<?php // app/Features/Story/Tests/WholeViewTest.php
namespace App\Features\Story\Tests;
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
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
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
        $css = (string) file_get_contents(dirname(__DIR__, 4) . '/public/assets/print.css');
        $this->assertStringContainsString('@media print', $css);
        $this->assertStringContainsString('.site-head', $css, 'the site nav hides in print');
        $this->assertStringContainsString('.engagement-bar', $css, 'the engagement bar hides in print');
        $this->assertStringContainsString('display: none', $css);
        // Reader chrome the redesign introduced never reaches paper.
        foreach (['.reader-head', '.reader-bar', '.reader-dock', '.sheet:target'] as $selector) {
            $this->assertStringContainsString($selector, $css, "{$selector} hides in print");
        }
        $this->assertStringContainsString('.chapter-end-actions', $css,
            'the boundary kudos/review buttons hide in print; the caption itself stays');
    }

    /** C9 (frame M3): every chapter closes with the boundary separator (dot
     *  ornament, roman caption, kudos form, review link), and every chapter
     *  after the first opens with the comp's next-chapter kicker above the
     *  surviving h2.p-name. The last chapter's separator is the final block:
     *  no kicker can follow it. */
    public function test_chapter_boundaries_render_separator_actions_and_next_kicker(): void
    {
        $res = $this->client()->get('/story/whole/the-rabbit-hole');
        $this->assertSame(200, $res->status, $res->body);
        // one separator per chapter, each captioned in roman numerals
        $this->assertSame(3, substr_count($res->body, '<footer class="chapter-end"'));
        foreach (['I', 'II', 'III'] as $roman) {
            $this->assertStringContainsString('aria-label="End of chapter ' . $roman . '"', $res->body);
        }
        $this->assertSame(3, substr_count($res->body, '<span class="dots" aria-hidden="true"></span>'));
        // the engagement pair rides every boundary
        $this->assertSame(3, substr_count($res->body, 'action="/kudos/add/the-rabbit-hole"'));
        $this->assertSame(3, substr_count($res->body, 'href="/story/view/the-rabbit-hole#reviews"'));
        // the next-chapter kicker: never on chapter 1, one per later chapter,
        // and none after the final separator (no chapter 4 exists)
        $this->assertSame(2, substr_count($res->body, '<p class="ch-kicker">'));
        $this->assertStringContainsString('<p class="ch-kicker">Chapter II</p>', $res->body);
        $this->assertStringContainsString('<p class="ch-kicker">Chapter III</p>', $res->body);
        $this->assertStringNotContainsString('Chapter IV', $res->body);
        // the h2.p-name element and class survive the restyle, three times
        $this->assertSame(3, substr_count($res->body, '<h2 class="p-name">'));
        // order inside a boundary: section anchor, kicker, heading, separator
        $sec2 = strpos($res->body, 'id="ch-2"');
        $kicker2 = strpos($res->body, '<p class="ch-kicker">Chapter II</p>');
        $h2 = strpos($res->body, '<h2 class="p-name">Through</h2>');
        $end2 = strpos($res->body, 'aria-label="End of chapter II"');
        $this->assertGreaterThan($sec2, $kicker2, 'the kicker sits inside the section, above the h2');
        $this->assertGreaterThan($kicker2, $h2);
        $this->assertGreaterThan($h2, $end2, 'the separator follows the chapter it closes');
    }

    /** Members carry the CSRF token on the boundary kudos forms (view.php's
     *  conditional-token shape); the guest render mints no session and no
     *  token input. */
    public function test_boundary_kudos_forms_carry_the_token_for_members_only(): void
    {
        $guest = $this->client()->get('/story/whole/the-rabbit-hole')->body;
        $this->assertStringNotContainsString('name="_token"', $guest);
        $member = $this->client($this->memberId())->get('/story/whole/the-rabbit-hole')->body;
        $this->assertStringContainsString('action="/kudos/add/the-rabbit-hole"', $member);
        $this->assertStringContainsString('name="_token"', $member);
    }

    /** The M3 boundary styles live in reader.css: the kicker, the restyled
     *  per-chapter heading with its 40x1 rule, and section rhythm. The
     *  chapter-end separator styles are C8's, reused unchanged. */
    public function test_reader_css_styles_the_whole_work_boundaries(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 4) . '/public/assets/reader.css');
        foreach (['.whole-work .ch-kicker', '.whole-work .p-name'] as $selector) {
            $this->assertStringContainsString($selector, $css, "the {$selector} block renders");
        }
    }
}
