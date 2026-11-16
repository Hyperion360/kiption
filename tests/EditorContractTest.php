<?php // tests/EditorContractTest.php
namespace App\Tests;
use Kip\App;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Slice 5: the chapter box carries the editor marker and the toolbar
 *  labels, the loader lists the module, and nothing changes with JS off. */
final class EditorContractTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-editor-') . '.sqlite';
        $db = new \Kip\Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__, 1) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 1) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->path . '.mail', 'from' => 'noreply@localhost'],
            'uploads' => [sys_get_temp_dir() . '/kiption-editor-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        return (new TestClient($app))->actingAs(1);
    }

    public function test_chapter_box_carries_the_editor_marker_labels_and_still_works_without_scripting(): void
    {
        $html = $this->client()->get('/chapter/new/the-rabbit-hole')->body;
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="content"[^>]*data-js-module="paste editor"/', $html);
        $this->assertMatchesRegularExpression('/data-editor-labels="[^"]*Bold[^"]*"/', $html, 'the toolbar labels ride the box');
        foreach (['Italic', 'Add Link', 'Quote', 'Scene Break'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('required', $html, 'the plain form is unchanged');
        $this->assertStringContainsString('Ctrl or Cmd plus B', $html, 'the shortcut hint renders');
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/', $html, 'no inline script');
    }

    public function test_the_loader_lists_the_editor_module_and_the_files_exist(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 1) . '/public/assets/app.js');
        $this->assertStringContainsString("'editor'", $js);
        foreach (['subset.js', 'text-edit.js', 'editor.js'] as $f) {
            $this->assertFileExists(dirname(__DIR__, 1) . '/public/assets/' . $f);
        }
    }

    public function test_the_editor_language_keys_exist(): void
    {
        $lang = require dirname(__DIR__, 1) . '/app/lang/en.php';
        foreach (['chapter.editor_bold', 'chapter.editor_italic', 'chapter.editor_link', 'chapter.editor_quote', 'chapter.editor_break', 'chapter.editor_hint'] as $key) {
            $this->assertArrayHasKey($key, $lang, $key);
        }
    }

    /** The JS rule tables cannot drift from the PHP renderer: the scene-break
     *  set and the escape trio are pinned on both sides. */
    public function test_the_subset_tables_match_the_php_renderer(): void
    {
        $subset = (string) file_get_contents(dirname(__DIR__, 1) . '/public/assets/subset.js');
        $md = (string) file_get_contents(dirname(__DIR__, 1) . '/app/src/Markdown.php');
        foreach (['-{3,}', '\*{3,}', '_{3,}'] as $needle) {
            $this->assertStringContainsString($needle, $subset, 'the scene rule in subset.js');
            $this->assertStringContainsString($needle, $md, 'the scene rule in the php renderer');
        }
        foreach (['*_\\\\', 'word edge'] as $needle) {
            $this->assertStringContainsString($needle, $subset, 'subset.js carries the escape trio and the word-edge rule');
            $this->assertStringContainsString($needle, $md, 'the php renderer carries the same');
        }
    }
}
