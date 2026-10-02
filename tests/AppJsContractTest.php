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
}
