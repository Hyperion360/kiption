<?php // tests/AppJsContractTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// The enhancement layer's server contract, keys module (reading-experience
// plan Task 4): the chapter reader carries data-js-module="keys" with every
// URL the shortcuts navigate to resolved server-side on .reader - the JS
// never invents one. The attributes render ALWAYS (focus mode or not), and
// the hint bar with its kbd caps is always-present markup that CSS reveals
// only under html.js, so noscript bytes stay exactly what they were.
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

    /** The .reader opening tag: the element carrying the keys module marker. */
    private function readerTag(string $body): string
    {
        $this->assertSame(1, preg_match('/<div class="reader[^"]*"[^>]*>/s', $body, $m),
            'the .reader element renders');
        return $m[0];
    }

    public function test_reader_carries_the_keys_module_with_resolved_urls(): void
    {
        $tag = $this->readerTag($this->client()->get('/story/read/the-rabbit-hole/2')->body);
        $this->assertStringContainsString('data-js-module="keys"', $tag, 'the loader keys off this marker');
        $this->assertStringContainsString('data-prev="/story/read/the-rabbit-hole/2/1"', $tag,
            'the previous chapter URL, resolved by the server');
        $this->assertStringContainsString('data-next="/story/read/the-rabbit-hole/2/3"', $tag,
            'the next chapter URL, resolved by the server');
        $this->assertStringContainsString('data-focus-url="/story/read/the-rabbit-hole/2?focus=1"', $tag);
        $this->assertStringContainsString('data-exit-focus="/story/read/the-rabbit-hole/2"', $tag,
            'the clean chapter URL exits focus');
        $this->assertStringContainsString('data-text-url="/story/read/the-rabbit-hole/2#text"', $tag,
            'the text sheet deep link, server-resolved');
    }

    public function test_prev_and_next_are_empty_at_the_story_ends(): void
    {
        $first = $this->readerTag($this->client()->get('/story/read/the-rabbit-hole/1')->body);
        $this->assertStringContainsString('data-prev=""', $first, 'chapter 1 has no previous');
        $this->assertStringContainsString('data-next="/story/read/the-rabbit-hole/1/2"', $first);
        $last = $this->readerTag($this->client()->get('/story/read/the-rabbit-hole/3')->body);
        $this->assertStringContainsString('data-prev="/story/read/the-rabbit-hole/3/2"', $last);
        $this->assertStringContainsString('data-next=""', $last, 'the last chapter has no next');
    }

    public function test_the_keys_attributes_render_in_focus_mode_too(): void
    {
        $tag = $this->readerTag($this->client()->get('/story/read/the-rabbit-hole/2', ['focus' => '1'])->body);
        $this->assertStringContainsString('class="reader reader-focus"', $tag);
        $this->assertStringContainsString('data-js-module="keys"', $tag, 'focus mode wires the module the same way');
        $this->assertStringContainsString('data-focus-url="/story/read/the-rabbit-hole/2?focus=1"', $tag);
        $this->assertStringContainsString('data-exit-focus="/story/read/the-rabbit-hole/2"', $tag,
            'Escape and F leave focus for the clean URL');
    }

    public function test_hint_bar_is_always_present_with_kbd_caps(): void
    {
        foreach ([
            $this->client()->get('/story/read/the-rabbit-hole/2')->body,
            $this->client()->get('/story/read/the-rabbit-hole/2', ['focus' => '1'])->body,
        ] as $body) {
            $this->assertStringContainsString('<div class="hint-keys" hidden>', $body,
                'always rendered, hidden until the enhancement layer runs');
            foreach (['<kbd>J</kbd>', '<kbd>K</kbd>', '<kbd>F</kbd>', '<kbd>T</kbd>', '<kbd>Esc</kbd>'] as $cap) {
                $this->assertStringContainsString($cap, $body, "the {$cap} cap renders");
            }
        }
    }

    public function test_reader_css_reveals_the_hint_bar_only_under_js(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $flat = str_replace(' ', '', $css);
        $this->assertStringContainsString('.js.hint-keys{', $flat,
            'the reveal rule lives under html.js, noscript never sees the caps');
        $this->assertStringContainsString('.hint-keys{display:none;', $flat,
            'the base rule keeps the bar hidden without scripting');
        $this->assertStringContainsString('z-index:25', $flat, 'the bar sits under the sheets');
    }

    public function test_the_keys_module_file_exists_for_the_loader(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/public/assets/keys.js');
        $loader = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("'keys'", $loader, 'the loader knows the keys module');
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
}
