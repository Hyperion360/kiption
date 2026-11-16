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
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-editor-upl'],
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

    public function test_editor_forms_keep_one_native_primary_save_action(): void
    {
        foreach ([
            ['/chapter/new/the-rabbit-hole', 'chapter-editor-form', '/chapter/create/the-rabbit-hole', 'Save Chapter'],
            ['/chapter/edit/the-rabbit-hole/1', 'chapter-editor-form', '/chapter/update/the-rabbit-hole/1', 'Save Chapter'],
            ['/story/new', 'story-editor-form', '/story/create', 'Create'],
            ['/story/edit/the-rabbit-hole', 'story-editor-form', '/story/update/the-rabbit-hole', 'Save'],
        ] as [$path, $id, $action, $label]) {
            $r = $this->client()->get($path);
            $this->assertSame(200, $r->status, $path);
            $doc = new \DOMDocument();
            @$doc->loadHTML($r->body);
            $xpath = new \DOMXPath($doc);
            $form = $xpath->query('//form[@id="' . $id . '"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $form, $path);
            $this->assertSame('post', $form->getAttribute('method'));
            $this->assertSame($action, $form->getAttribute('action'));
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
            $buttons = $xpath->query('.//button[@type="submit"]', $form);
            $this->assertSame(1, $buttons->length, $path);
            $this->assertSame($label, trim($buttons->item(0)->textContent));
            $this->assertSame('btn-primary', $buttons->item(0)->getAttribute('class'));
        }
    }

    public function test_the_editor_language_keys_exist(): void
    {
        $lang = require dirname(__DIR__, 1) . '/app/lang/en.php';
        foreach (['chapter.editor_bold', 'chapter.editor_italic', 'chapter.editor_link', 'chapter.editor_quote', 'chapter.editor_break', 'chapter.editor_hint'] as $key) {
            $this->assertArrayHasKey($key, $lang, $key);
        }
    }

    private function writerForm(string $html, string $id): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($doc);
        $form = $xpath->query('//form[@id="' . $id . '"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $form);
        return [$form, $xpath];
    }

    public function test_writer_status_has_explicit_server_state_and_accessible_nonlive_word_count(): void
    {
        foreach (['chapter' => '/chapter/edit/the-rabbit-hole/1', 'story' => '/story/edit/the-rabbit-hole'] as $kind => $path) {
            [$form, $xpath] = $this->writerForm($this->client()->get($path)->body, $kind . '-editor-form');
            $this->assertSame('0', $form->getAttribute('data-writer-saved'));
            $this->assertSame('0', $form->getAttribute('data-writer-rejected'));
            $labels = json_decode($form->getAttribute('data-writer-labels'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('You have unsaved changes.', $labels['unsaved']);
            $state = $xpath->query('.//*[@data-writer-state]', $form);
            $this->assertSame(1, $state->length);
            $this->assertSame('status', $state->item(0)->getAttribute('role'));
            $this->assertSame('polite', $state->item(0)->getAttribute('aria-live'));
            $this->assertSame('true', $state->item(0)->getAttribute('aria-atomic'));
            $this->assertSame('', $state->item(0)->textContent);
            [$saved, $savedXpath] = $this->writerForm($this->client()->get($path, ['saved' => '1'])->body, $kind . '-editor-form');
            $this->assertSame('1', $saved->getAttribute('data-writer-saved'));
            $this->assertSame('All changes shown here are saved.', $savedXpath->query('.//*[@data-writer-state]', $saved)->item(0)->textContent);
            if ($kind === 'chapter') {
                $box = $xpath->query('.//textarea[@name="content"]', $form)->item(0);
                $count = $xpath->query('.//*[@data-writer-count]', $form)->item(0);
                $this->assertStringContainsString($count->getAttribute('id'), $box->getAttribute('aria-describedby'));
                $this->assertSame(\App\Markdown::wordCount($box->textContent) . ' words', $count->textContent);
                $this->assertSame('off', $count->getAttribute('aria-live'));
                $button = $xpath->query('.//button[@data-writer-count-selection]', $form)->item(0);
                $this->assertSame('button', $button->getAttribute('type'));
                $this->assertTrue($button->hasAttribute('hidden'));
                $this->assertSame('Count Selection', $button->textContent);
            } else {
                $this->assertSame(0, $xpath->query('.//*[@data-writer-count]', $form)->length);
            }
        }
    }

    public function test_rejected_forms_are_immediately_unsaved_even_with_a_saved_query_parameter(): void
    {
        foreach ([
            ['/chapter/create/the-rabbit-hole', ['content' => ''], 422, 'chapter'],
            ['/chapter/update/the-rabbit-hole/1', ['content' => 'Retained text', 'base_revision' => str_repeat('0', 64)], 409, 'chapter'],
            ['/story/create', ['title' => ''], 422, 'story'],
        ] as [$path, $data, $status, $kind]) {
            $client = $this->client();
            $response = $client->request('POST', $path, ['saved' => '1'], $data + ['_token' => $client->csrfToken()]);
            $this->assertSame($status, $response->status);
            [$form, $xpath] = $this->writerForm($response->body, $kind . '-editor-form');
            $this->assertSame('1', $form->getAttribute('data-writer-rejected'));
            $this->assertSame('0', $form->getAttribute('data-writer-saved'));
            $this->assertSame('You have unsaved changes.', $xpath->query('.//*[@data-writer-state]', $form)->item(0)->textContent);
            $this->assertSame(1, $xpath->query('.//button[@type="submit" and not(@name)]', $form)->length);
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
