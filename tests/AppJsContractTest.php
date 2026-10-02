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
    /** The .reader-progress opening tag: the element carrying the module marker. */
    private function progressTag(string $body): string
    {
        $this->assertSame(1, preg_match('/<div class="reader-progress"[^>]*>/s', $body, $m),
            'the .reader-progress element renders');
        return $m[0];
    }

    /** Every .reader-pct opening tag: one readout per reader page. */
    /** Every .reader-pct opening tag: one readout per reader page. */
    private function pctTags(string $body): array
    {
        $this->assertSame(1, preg_match_all('/<span class="reader-pct"[^>]*>/', $body, $m),
            'exactly one percent readout renders');
        return $m[0];
    }
}

