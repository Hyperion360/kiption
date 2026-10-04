<?php // tests/AppJsContractTest.php
namespace App\Tests;
use App\Features\Reader\Prefs;
use App\Theme;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// The enhancement layer's server contract, prefs module (reading-experience
// plan Task 2): the text-settings form carries data-js="settings-form" with
// data-js-module="prefs", the size range carries the data-js-pref-target
// variant, and every radio in the sheet - the five segmented groups plus the
// theme swatches - carries data-js-pref="{group}" for the instant-apply
// reader. Pure markers: the server learns nothing of scripting, so noscript
// bytes gain only the attributes themselves.
final class AppJsContractTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        \App\Lang::setCurrent('en'); // the pack layer is process-global; anchor it (the LangTest idiom)
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-appjs-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(): TestClient
    {
        return new TestClient(new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-appjs-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
    }

    /** The text-settings form tag: the element carrying the prefs marker. */
    private function formTag(string $body): string
    {
        $this->assertSame(1, preg_match('/<form[^>]*data-js="settings-form"[^>]*>/s', $body, $m),
            'the text-settings form renders with its settings-form marker');
        return $m[0];
    }

    public function test_text_settings_form_carries_the_prefs_module(): void
    {
        $tag = $this->formTag($this->client()->get('/story/read/the-rabbit-hole/1')->body);
        $this->assertStringContainsString('action="/reader/settings"', $tag,
            'the marker rides the settings form itself, not some other form');
        $this->assertStringContainsString('data-js-module="prefs"', $tag, 'the loader keys off this marker');
    }

    public function test_every_control_carries_its_pref_marker(): void
    {
        $body = $this->client()->get('/story/read/the-rabbit-hole/1')->body;
        $this->assertSame(1, preg_match('/<input[^>]*data-js-pref-target="size"[^>]*>/', $body, $range),
            'the size control carries the target variant');
        $this->assertStringContainsString('type="range"', $range[0], 'the target marker rides the range input');
        foreach ([
            'typeface' => count(Prefs::TYPEFACES),
            'spacing' => count(Prefs::SPACINGS),
            'paragraphs' => count(Prefs::PARAGRAPHS),
            'theme' => count(Theme::VALUES),
            'width' => count(Prefs::WIDTHS),
            'mode' => count(Prefs::MODES),
        ] as $group => $expected) {
            preg_match_all('/<input[^>]*data-js-pref="' . $group . '"[^>]*>/', $body, $m);
            $this->assertSame($expected, count($m[0]), "every {$group} radio carries the marker");
            $this->assertStringContainsString('type="radio"', $m[0][0] ?? '', "the {$group} marker sits on radios");
        }
    }

    public function test_the_markers_render_in_focus_mode_too(): void
    {
        $body = $this->client()->get('/story/read/the-rabbit-hole/1', ['focus' => '1'])->body;
        $this->assertStringContainsString('data-js-module="prefs"', $this->formTag($body),
            'focus mode wires the module the same way');
        $this->assertStringContainsString('data-js-pref-target="size"', $body);
    }

    public function test_the_prefs_module_file_exists_for_the_loader(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/public/assets/prefs.js');
        $loader = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("'prefs'", $loader, 'the loader knows the prefs module');
    }

    /* ---- position module (merged from wave/position's contract file) ---- */

    public function test_progress_bar_carries_the_position_module_and_server_span(): void
    {
        $tag = $this->progressTag($this->client()->get('/story/read/the-rabbit-hole/2')->body);
        $this->assertStringContainsString('data-js-module="position"', $tag, 'the loader keys off this marker');
        $this->assertStringContainsString('data-js="reader-progress"', $tag);
        $this->assertStringContainsString('data-p-start="17"', $tag,
            'the chapter span start, server-computed (seeded story: ch2 opens at 17%)');
        $this->assertStringContainsString('data-p-end="50"', $tag,
            'the chapter span end, server-computed');
        // the first chapter's band starts at zero
        $first = $this->progressTag($this->client()->get('/story/read/the-rabbit-hole/1')->body);
        $this->assertStringContainsString('data-p-start="0"', $first);
        $this->assertStringContainsString('data-p-end="17"', $first);
    }

    public function test_the_percent_readout_is_wired(): void
    {
        foreach (['/story/read/the-rabbit-hole/2', '/story/read/the-rabbit-hole/1'] as $url) {
            foreach ($this->pctTags($this->client()->get($url)->body) as $tag) {
                $this->assertStringContainsString('data-js="reader-pct"', $tag,
                    'the readout rides its wiring attribute');
            }
        }
    }

    public function test_focus_mode_carries_the_same_markers_on_the_breadcrumb(): void
    {
        $body = $this->client()->get('/story/read/the-rabbit-hole/2', ['focus' => '1'])->body;
        $this->assertStringContainsString('class="reader reader-focus"', $body);
        $tag = $this->progressTag($body);
        $this->assertStringContainsString('data-js-module="position"', $tag, 'focus mode wires the module the same way');
        $this->assertStringContainsString('data-js="reader-progress"', $tag);
        $this->assertStringContainsString('data-p-start="17"', $tag);
        $this->assertStringContainsString('data-p-end="50"', $tag);
        // the breadcrumb's readout is the page's one readout, wired the same
        $tags = $this->pctTags($body);
        $this->assertStringContainsString('data-js="reader-pct"', $tags[0]);
        $this->assertStringContainsString('Chapter 2 of 3 · 50%', $body,
            'the breadcrumb text keeps its shape for the module to edit');
    }

    public function test_the_position_module_writes_the_bar_and_never_a_cookie(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/position.js');
        $this->assertStringNotContainsString('document.cookie', $js,
            'reading position lives in the page, never in a cookie');
        $this->assertStringContainsString('aria-valuenow', $js,
            'the progressbar stays truthful to assistive tech');
        $this->assertStringContainsString('--p-end', $js, 'the module writes the fill custom property');
    }

    public function test_the_position_module_file_exists_for_the_loader(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/public/assets/position.js');
        $loader = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("'position'", $loader, 'the loader knows the position module');
    }

    /** The .reader-progress opening tag: the element carrying the module marker. */
    private function progressTag(string $body): string
    {
        $this->assertSame(1, preg_match('/<div class="reader-progress"[^>]*>/s', $body, $m),
            'the .reader-progress element renders');
        return $m[0];
    }

    /** Every .reader-pct opening tag: one readout per reader page. */
    private function pctTags(string $body): array
    {
        $this->assertSame(1, preg_match_all('/<span class="reader-pct"[^>]*>/', $body, $m),
            'exactly one percent readout renders');
        return $m[0];
    }

    /** Script-written preference cookies keep the Secure attribute the server
     *  adds on HTTPS (App\Cookie): the first control move must not drop it. */
    public function test_script_cookies_are_secure_on_https(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("location.protocol === 'https:' ? '; secure' : ''", $js);
        $this->assertSame(2, substr_count($js, '+ secure'), 'both the write and the delete carry it');
        // an empty value deletes (max-age=0), never a year-long empty cookie
        // that would still make every request miss the static cache
        $this->assertStringContainsString("name + '=; path=/; max-age=0; samesite=lax'", $js);
    }

    /** Arrow keys stay the browser's when Shift is held (text selection) and
     *  in pages mode (the horizontal deck turns pages with them); J and K
     *  always navigate chapters. */
    public function test_keys_leave_shifted_arrows_and_pages_mode_alone(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/keys.js');
        $this->assertStringContainsString("e.shiftKey || document.documentElement.getAttribute('data-mode') === 'pages'", $js);
        $this->assertStringContainsString("key === 'j' || (key === 'arrowright' && arrows)", $js);
        $this->assertStringContainsString("key === 'k' || (key === 'arrowleft' && arrows)", $js);
    }

    /** One owner for the progress fill: the position module marks its bar
     *  is-live and the CSS scroll-timeline animation stands down, so the fill
     *  is not interpolated twice (qa-full /review, Codex). */
    public function test_the_position_module_owns_the_fill_alone(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/position.js');
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $this->assertStringContainsString("bar.classList.add('is-live')", $js);
        $this->assertStringContainsString('.reader-progress.is-live::after { animation: none; }', $css);
    }

    /** The hint bar reveals only when keys.js has actually run: the CSS
     *  reveal carries :not([hidden]) (an author display rule outranks the
     *  UA's [hidden] rule, so a bare .js reveal would flash the bar on
     *  every load and stick forever if keys.js failed), and the keys
     *  module is what clears the attribute. */
    public function test_hint_keys_reveal_lets_the_hidden_attribute_win(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $this->assertStringContainsString('.js .hint-keys:not([hidden])', $css,
            'the reveal requires keys.js to have cleared the hidden attribute');
        $this->assertStringNotContainsString(".js .hint-keys {", $css,
            'no bare .js .hint-keys display rule may outrank [hidden]');
        $this->assertStringContainsString('@media (max-width:1023px) { .js .hint-keys:not([hidden]) { display: none; } }',
            $css, 'the tablet hide carries the same specificity or it loses the cascade');
    }

    /** An explicit behavior:'smooth' does not defer to the CSS
     *  scroll-behavior kill switch, so the keys module asks the media query
     *  itself (prefers-reduced-motion) before animating the T shortcut. */
    public function test_the_text_shortcut_honors_reduced_motion(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/keys.js');
        $this->assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $js);
        $this->assertStringContainsString("reduce ? 'auto' : 'smooth'", $js);
    }

    /** A prefetched fragment never counts a read: the infinite module parks
     *  the unit's beacon src on data-beacon-src and drops the img on
     *  insert, and activation re-creates it for the chapter being read. */
    public function test_fragments_park_the_read_beacon_until_activation(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/infinite.js');
        $this->assertStringContainsString('img[src^="/beacon/"]', $js);
        $this->assertStringContainsString('data-beacon-src', $js);
        $this->assertStringContainsString('read.appendChild(img)', $js);
    }

    /** The percent rewrite is shape-based: the module owns the trailing
     *  percent token and never matches translated wording, so a second
     *  language pack cannot freeze the focus readout (red-team finding:
     *  the en-only regex stopped matching the moment chapter_of_pct
     *  rendered differently). */
    public function test_the_percent_rewrite_never_hardcodes_wording(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/position.js');
        $this->assertStringNotContainsString('Chapter', $js, 'no en wording in the module: the rewrite is shape-based');
        $this->assertStringContainsString('/\\D\\d+%$/', $js, 'the trailing-percent rewrite anchors on shape');
    }

    /** Activation swaps the visible chapter label and the focus breadcrumb's
     *  chapter-of prefix: both are server-rendered into the unit-state
     *  (the module invents no label), and the percent node itself is
     *  rewritten in place because the position module holds its reference. */
    public function test_activation_swaps_the_chapter_label_from_the_unit_state(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/infinite.js');
        $this->assertStringContainsString("swap('.rt-chapter', slot('label'))", $js);
        $this->assertStringContainsString('data-pct-focus', $js);
        $this->assertStringContainsString('pctEl.textContent = pf', $js);
        $view = (string) file_get_contents(dirname(__DIR__) . '/app/Features/Story/views/_chapter.php');
        $this->assertStringContainsString('data-slot="label"', $view,
            'the unit-state carries the label the module swaps in');
        $this->assertStringContainsString('data-pct-focus=', $view,
            'the unit-state carries the focus breadcrumb text');
    }

    /** A stacked chapter never reloads the page, so the activation writes the
     *  server-built chapter name into a status region (WCAG 4.1.3): what a
     *  chapter load announces by re-reading, infinite scroll announces here. */
    public function test_activation_announces_the_chapter_in_a_status_region(): void
    {
        $body = $this->client()->get('/story/read/the-rabbit-hole/1')->body;
        $this->assertStringContainsString('role="status"', $body, 'the reader carries a status region');
        $fragment = $this->client()->get('/story/fragment/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('data-status="Chapter 2 of 3 · Through"', $fragment,
            'the unit-state carries the server-built announcement');
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/infinite.js');
        $this->assertStringContainsString("Kip.\$('[role=\"status\"]', shell)", $js);
        $this->assertStringContainsString('statusEl.textContent = say', $js);
    }
}
