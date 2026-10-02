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
        // The below-1024px search link sits between the nav and the Menu
        // control; the stylesheet swaps it for the input form from 1024px.
        $this->assertStringContainsString('<a class="nav-search-link" href="/search">Search</a>', $body);
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

    /** The site footer (owner decision 2026-10-02 reverses the comp's
     *  no-footer call for site pages): secondary destinations and the
     *  credit, identical for every visitor, hidden under the reader by CSS. */
    public function test_site_footer_carries_secondary_destinations(): void
    {
        $body = $this->client()->get('/')->body;
        $this->assertStringContainsString('<footer class="site-foot">', $body);
        preg_match('#<footer class="site-foot">.*?</footer>#s', $body, $m);
        $foot = $m[0] ?? '';
        foreach (['href="/news"', 'href="/top"', 'href="/lists"', 'href="/challenges"', 'href="/feed/subscribe"'] as $href) {
            $this->assertStringContainsString($href, $foot);
        }
        $this->assertStringNotContainsString('href="/browse"', $foot, 'header destinations are not repeated');
        $this->assertSame($foot, (preg_match('#<footer class="site-foot">.*?</footer>#s', $this->client($this->memberId)->get('/')->body, $mm) ? $mm[0] : ''),
            'one footer for guests and members: cached bytes stay stable');
        $css = str_replace(' ', '', (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css'));
        $this->assertStringContainsString('body:has(.reader).site-foot{display:none', $css, 'the reader keeps its own chrome');
        $this->assertStringNotContainsString('href="/theme/', $body, 'the legacy theme routes lost their only links');
    }

    /** The header theme quick toggle (plan Task 7): a real button between the
     *  search affordance and the Menu control, labeled from the shared
     *  nav.theme_toggle key. Scripting off it stays hidden (the Text sheet
     *  still switches themes); with scripting it flips light/dark on the
     *  spot, device-locally, its label naming the next state. */
    public function test_header_carries_the_theme_quick_toggle(): void
    {
        $body = $this->client()->get('/')->body;
        $this->assertStringContainsString('<button type="button" class="theme-toggle" data-js-module="toggle" aria-label="Theme" data-label-dark="Switch to the Night theme" data-label-light="Switch to the Paper theme">', $body,
            'the exact button shape the toggle module keys off, with the nav.theme_toggle label');
        // DOM order: the search affordance, then the toggle, then the Menu control.
        $this->assertMatchesRegularExpression('/class="nav-search-link"[^>]*>.*?class="theme-toggle".*?class="nav-menu-link"/s', $body,
            'the toggle sits between the search affordance and the Menu link');
        // No member state and no URL: the button is identical markup for a
        // guest and a member, so cached guest bytes stay stable.
        $this->assertStringContainsString('<button type="button" class="theme-toggle" data-js-module="toggle" aria-label="Theme" data-label-dark="Switch to the Night theme" data-label-light="Switch to the Paper theme">', $this->client($this->memberId)->get('/')->body);
    }

    /** The Menu sheet is grouped (You / Read / Operator), never lists a
     *  destination twice (operator nav links that repeat a built-in are
     *  skipped), and for members it carries Settings, a Log out form, and
     *  the is-member flag that keeps Menu visible on desktop. */
    public function test_menu_sheet_is_grouped_deduped_and_member_aware(): void
    {
        $guest = $this->client()->get('/')->body;
        preg_match('#<div id="menu".*?</div>#s', $guest, $m);
        $sheet = $m[0] ?? '';
        $this->assertSame(1, substr_count($sheet, 'href="/browse"'), 'each destination once');
        $this->assertSame(1, substr_count($sheet, 'href="/browse/recent"'));
        $this->assertStringContainsString('href="/auth/login"', $sheet);
        $this->assertStringNotContainsString('sheet-logout', $sheet);
        $this->assertStringContainsString('<a class="nav-menu-link" href="#menu">', $guest, 'guests: Menu stays a sub-desktop control');
        $member = $this->client($this->memberId)->get('/')->body;
        preg_match('#<div id="menu".*?</div>#s', $member, $m);
        $msheet = $m[0] ?? '';
        $this->assertStringContainsString('<a class="nav-menu-link is-member" href="#menu">', $member);
        $this->assertStringContainsString('href="/account/settings"', $msheet);
        $this->assertStringContainsString('<form method="post" action="/auth/logout" class="sheet-logout">', $msheet);
        $this->assertLessThan(strpos($msheet, 'href="/browse"'), strpos($msheet, 'href="/account"'), 'the member group leads');
    }

    public function test_theme_toggle_is_hidden_without_scripting_and_revealed_under_html_js(): void
    {
        $flat = str_replace(' ', '', (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css'));
        $this->assertStringContainsString('.theme-toggle{display:none', $flat,
            'the base rule hides the button while scripting is off');
        $this->assertStringContainsString('.js.theme-toggle{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px', $flat,
            'html.js reveals a 44px square tap target once the enhancement layer runs');
    }

    public function test_the_toggle_module_file_exists_for_the_loader(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/public/assets/toggle.js');
        $loader = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("'toggle'", $loader, 'the loader knows the toggle module');
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/toggle.js');
        $this->assertStringNotContainsString('document.cookie', $js,
            'the server theme cookie is HttpOnly and a legacy dark/light cookie reads unknown: the data-theme attribute is the only truth');
        $this->assertStringContainsString("getAttribute('data-theme')", $js, 'the current state is read from the root attribute');
        // a predictable two-way flip: Night from any light page, Paper from a
        // dark one (Auto resolves through the OS setting); the label is swapped
        // to name the next state
        $this->assertStringContainsString("isDark() ? 'paper' : 'night'", $js, 'the light/dark flip');
        $this->assertStringContainsString("prefers-color-scheme: dark", $js, 'Auto resolves through the OS setting');
        $this->assertStringContainsString("setAttribute('aria-label'", $js, 'the label names the next state');
        $this->assertStringContainsString('Kip.setTheme(next)', $js, 'application goes through the one core setter');
        $this->assertStringContainsString('setTheme: function', (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js'),
            'which lives in app.js, loaded on every page');
    }

    public function test_the_toggle_label_key_exists(): void
    {
        \App\Lang::setCurrent('en');
        $this->assertSame('Theme', \App\Lang::t('nav.theme_toggle'));
    }

    public function test_menu_sheet_is_a_dialog_with_the_full_nav(): void
    {
        $guest = $this->client()->get('/')->body;
        $this->assertStringContainsString('<div id="menu" class="sheet" role="dialog"', $guest);
        $this->assertStringContainsString('class="sheet-done" href="#sheet-close"', $guest, 'Done closes the sheet by retargeting :target at the fixed close anchor (never the document top)');
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

    public function test_print_css_hides_the_site_chrome(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/print.css');
        $this->assertStringContainsString('.site-foot', $css, 'the footer hides in print');
        $this->assertStringContainsString('.site-head', $css, 'the site nav still hides in print');
        $this->assertStringContainsString('.print-hint', $css, 'the print hint still hides in print');
    }

    /** C7: the story-page components exist as real rules, at all three
     *  breakpoints, so the M1/T2/D3 frames are styled by the sheet. */
    /** The Text slider advertises one default (19px) and the server and
     *  prefs.js drop data-size for it, so no breakpoint may rebind the
     *  default --read-size: choosing 19 rendered 21px on tablets and 20px on
     *  desktops while the readout said 19 (qa-full /review, Codex). */
    public function test_default_reading_size_is_the_same_at_every_width(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $this->assertSame(1, preg_match_all('/--read-size:\s*\d+px/', preg_replace('/html\[data-size="\d+"\][^}]*}/', '', $css)),
            'only :root sets the default size; data-size rules are the only overrides');
        $this->assertStringContainsString('--read-size:19px', $css);
    }

    /** On the desktop reader Contents and Text are always-visible panes, so
     *  targeting them must not raise the scrim: their Done links are hidden
     *  there, and a scrim with no dismissal would trap pointer users with
     *  scripting off. Focus mode keeps the overlay (it has Done links). */
    public function test_desktop_panes_never_raise_the_scrim(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $desktop = substr($css, (int) strpos($css, "@media (min-width:1024px) {\n  /* D1: the desktop reader"));
        $desktop = substr($desktop, 0, (int) strpos($desktop, "\n}\n"));
        $this->assertStringContainsString(
            'body:has(.reader:not(.reader-focus) :is(#contents, #text):target) .scrim { display: none; }', $desktop);
    }

    public function test_reader_css_carries_the_story_page_components(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        foreach (['.story-hero', '.cover-card', '.continue-cta', '.story-stats',
                  '.chapter-list', '.eyebrow', '.ch-here', '.action-row', '.story-warnings'] as $selector) {
            $this->assertStringContainsString($selector, $css, "the {$selector} component is styled");
        }
        $flat = str_replace(' ', '', $css);
        $this->assertStringContainsString('aspect-ratio:2/3', $flat,
            'the cover card keeps the comp 2:3 ratio');
        $this->assertStringContainsString('.story-info>.meta-line{display:none', $flat,
            'desktop swaps the meta line for the stats dl (one markup, two presentations)');
        $this->assertStringContainsString('.nav-search-link{display:none', $flat,
            'desktop swaps the search link for the input form');
        $this->assertStringContainsString('.story-cover{display:none', $flat,
            'M1 is content-first: the cover column hides below 768px');
    }

    /** C8: the reader-shell components exist as real rules: the control bar
     *  at all three placements, the scroll-driven progress enhancement with
     *  its static fallback, the pref overrides, and focus chrome hiding. */
    public function test_reader_css_carries_the_chapter_reader_components(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $flat = str_replace(' ', '', $css);
        foreach (['.reader-head', '.reader-progress', '.reader-bar', '.reader-dock',
                  '.chapter-end', '.ctab-radio', '.segmented', '.swatch'] as $selector) {
            $this->assertStringContainsString($selector, $css, "the {$selector} component is styled");
        }
        $this->assertStringContainsString('@supports(animation-timeline:scroll())', $flat,
            'the scroll-driven progress enhancement ships inside @supports');
        $this->assertStringContainsString('animation-timeline:scroll(root)', $flat);
        $this->assertStringContainsString('--p-end,0%)100%', $flat,
            'the static fallback paints the --p-end gradient without timeline support');
        $this->assertStringContainsString('html[data-mode="pages"]', $css, 'paged mode rides CSS columns');
        $this->assertStringContainsString('column-width:var(--read-width)', $flat, 'columns sized by the width token');
        $this->assertStringContainsString('html[data-width="wide"]', $css, 'the width pref override exists');
        $this->assertStringContainsString('html[data-typeface="sans"]', $css, 'the typeface pref override exists');
        $this->assertStringContainsString('body.focus.site-head{display:none', $flat,
            'focus mode hides the site header via body.focus');
    }

    public function test_layout_defers_the_enhancement_layer(): void
    {
        $body = $this->client()->get('/')->body;
        $this->assertStringContainsString('<script src="/assets/app.js" defer></script>', $body,
            'one deferred vanilla script tag');
        $this->assertSame(2, substr_count($body, '<script'),
            'exactly two script tags: the deferred layer and the data-only JSON-LD');
    }
}
