<?php // tests/LayoutShellTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// C6: the responsive shell. The comp ships a compact header (brand, inline
// nav, a search form that appears from 1024px, a Menu control opening a
// :target sheet holding the whole nav) and NO footer on any frame. These
// pins hold the shell markup plus the two stylesheet contracts every
// not-yet-redesigned screen inherits: the comp breakpoints live in
// reader.css, and print.css stops styling the retired footer.
final class LayoutShellTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-shell-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function app(array $extra = []): App
    {
        return new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            ...$extra,
        ]);
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        $client = new TestClient($this->app($extra));
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_header_renders_brand_nav_search_link_and_menu_control(): void
    {
        $body = $this->client()->get('/')->body;
        $this->assertStringContainsString('<header class="site-head">', $body);
        $this->assertStringContainsString('<a class="brand" href="/">Kiption</a>', $body);
        $this->assertStringContainsString('href="/browse"', $body);
        $this->assertStringContainsString('href="/browse/recent"', $body);
        // The search affordance on small screens is the plain /search link.
        $this->assertStringContainsString('<a href="/search">Search</a>', $body);
        // The Menu control is a plain anchor (CSS-only :target pattern, no
        // aria-expanded to maintain without scripting).
        $this->assertStringContainsString('<a class="nav-menu-link" href="#menu">Menu</a>', $body);
        $this->assertStringNotContainsString('aria-expanded', $body);
        // The hidden-below-1024px inline search form.
        $this->assertStringContainsString('<form class="nav-search" role="search" action="/search" method="get">', $body);
        $this->assertStringContainsString('name="q"', $body);
        // A cookieless guest sees the login spelling, not the account link.
        $this->assertStringContainsString('href="/auth/login"', $body);
        $this->assertStringNotContainsString('href="/account"', $body);
    }

    public function test_no_footer_element_renders_anywhere(): void
    {
        $body = $this->client()->get('/')->body;
        $this->assertStringNotContainsString('<footer', $body, 'the comp ships no footer on any frame');
        $this->assertStringNotContainsString('site-foot', $body);
        $this->assertStringNotContainsString('theme-toggle', $body, 'theme switching lives in the reader Text sheet (C8)');
        $this->assertStringNotContainsString('href="/theme/', $body, 'the legacy theme routes lost their only links');
    }

    public function test_menu_sheet_is_a_dialog_with_the_full_nav(): void
    {
        $guest = $this->client()->get('/')->body;
        $this->assertStringContainsString('<div id="menu" class="sheet" role="dialog"', $guest);
        $this->assertStringContainsString('class="sheet-done" href="#"', $guest, 'Done closes the sheet by clearing :target');
        // Guest sheet: login link, no member links.
        $this->assertStringContainsString('href="/auth/login"', $guest, 'a guest sees the login link in the sheet');
        $this->assertStringNotContainsString('href="/notifications"', $guest);
        $this->assertStringNotContainsString('href="/messages"', $guest);
        $member = $this->client($this->memberId)->get('/')->body;
        $this->assertStringContainsString('href="/messages"', $member, 'pms ships on: messages rides the sheet');
        $this->assertStringContainsString('href="/notifications"', $member, 'a member sees notifications in the sheet');
        $this->assertStringContainsString('href="/account"', $member, 'a member sees the account link');
    }

    public function test_menu_sheet_carries_admin_links_and_custom_nav_links(): void
    {
        $demoId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        \App\Adminness::setRole($this->db, $demoId, 'admin');
        $navFile = tempnam(sys_get_temp_dir(), 'kiption-shell-nav-') . '.json';
        $this->db->query('INSERT INTO nav_links (label, url, position) VALUES (?,?,?)', ['Rules', '/page/rules', 1]);
        \App\NavLinks::rebuild($this->db, $navFile);
        $body = $this->client($demoId, ['nav_file' => $navFile])->get('/')->body;
        $this->assertStringContainsString('href="/admin"', $body, 'admin links ride the sheet for admins');
        $this->assertStringContainsString('href="/queue"', $body);
        $this->assertStringContainsString('href="/news/new"', $body, 'news ships on: the post link rides the sheet');
        $this->assertStringContainsString('href="/page/rules"', $body, 'custom NavLinks render in the sheet');
        @unlink($navFile);
        // A plain member gets none of the admin links.
        $this->assertStringNotContainsString('href="/admin"', $this->client($this->memberId)->get('/')->body);
    }

    public function test_stylesheet_link_is_unchanged(): void
    {
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/reader.css">', $this->client()->get('/')->body);
    }

    public function test_reader_css_carries_the_comp_breakpoints_and_safety_switches(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $this->assertStringContainsString('@media (max-width:767px)', $css, 'the mobile ceiling from the comp');
        $this->assertStringContainsString('@media (min-width:768px)', $css, 'the tablet floor');
        $this->assertStringContainsString('@media (min-width:1024px)', $css, 'the desktop floor');
        $this->assertStringContainsString('prefers-reduced-motion', $css, 'the motion kill switch');
        $this->assertStringContainsString(':focus-visible', $css, 'keyboard focus outlines');
        // The :target sheet reveal pattern (zero JavaScript).
        $this->assertStringContainsString('.sheet:target', $css);
        $this->assertStringContainsString('.sheet{display:none', str_replace(' ', '', $css),
            'sheets stay hidden until :target opens them');
    }

    public function test_print_css_no_longer_references_the_retired_footer(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/print.css');
        $this->assertStringNotContainsString('.site-foot', $css, 'the element no longer exists');
        $this->assertStringContainsString('.site-head', $css, 'the site nav still hides in print');
        $this->assertStringContainsString('.print-hint', $css, 'the print hint still hides in print');
    }

    /** C7: the story-page components exist as real rules, at all three
     *  breakpoints, so the M1/T2/D3 frames are styled by the sheet. */
    public function test_reader_css_carries_the_story_page_components(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        foreach (['.story-hero', '.cover-card', '.continue-cta', '.story-stats',
                  '.chapter-list', '.eyebrow', '.you-are-here'] as $selector) {
            $this->assertStringContainsString($selector, $css, "the {$selector} component is styled");
        }
        $this->assertStringContainsString('aspect-ratio:2/3', str_replace(' ', '', $css),
            'the cover card keeps the comp 2:3 ratio');
        $this->assertStringContainsString('.story-info>.meta-line{display:none', str_replace(' ', '', $css),
            'desktop swaps the meta line for the stats dl (one markup, two presentations)');
    }
}
