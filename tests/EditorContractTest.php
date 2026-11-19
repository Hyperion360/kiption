<?php // tests/EditorContractTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Slice 5: the chapter box carries the editor marker and the toolbar
 *  labels, the loader lists the module, and nothing changes with JS off. */
#[Medium]
final class EditorContractTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-editor-');
        $db = new \Kip\Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
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
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="content"[^>]*data-js-module="paste epigraph-boot"/', $html);
        $this->assertMatchesRegularExpression('/data-editor-labels="[^"]*Bold[^"]*"/', $html, 'the toolbar labels ride the box');
        foreach (['Italic', 'Add Link', 'Quote', 'Scene Break'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('required', $html, 'the plain form is unchanged');
        $this->assertStringContainsString('Ctrl or Cmd + /', $html, 'the shortcut list renders');
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/', $html, 'no inline script');
    }

    public function test_the_editor_header_theme_control_names_all_four_themes_and_cycles_them(): void
    {
        $html = $this->client()->get('/chapter/new/the-rabbit-hole')->body;
        $this->assertMatchesRegularExpression('/data-ed-theme-toggle[^>]*aria-label="Theme"/', $html);
        $this->assertMatchesRegularExpression('/"themeNames":\{"paper":"Paper","sepia":"Sepia","night":"Night","auto":"Auto"\}/', html_entity_decode($html), 'the labels carry all four names');
        $this->assertStringNotContainsString('ed.theme_dark', $html);
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/writer-comfort.js');
        $this->assertStringContainsString("['paper', 'sepia', 'night', 'auto']", $js, 'the toggle cycles four themes, never a two-state flip');
        $this->assertStringContainsString("/reader/settings", $js, 'the choice is posted like the rest of the site');
    }

    public function test_the_editor_keeps_the_site_menu_the_source_switch_and_all_four_formatting_hints(): void
    {
        $html = $this->client()->get('/chapter/new/the-rabbit-hole')->body;
        $this->assertMatchesRegularExpression('/<a role="menuitem" class="ed-menu-link" href="#menu"[^>]*>Menu<\/a>/', $html, 'More opens the site menu');
        $this->assertStringContainsString('id="menu"', $html, 'the site menu sheet rides the bare layout');
        $this->assertStringContainsString('action="/auth/logout"', $html, 'Log Out is reachable from the editor');
        $this->assertStringContainsString('class="theme-seg"', $html, 'the four-way theme control is on the page');
        foreach (['chapter.markdown_help', 'ed.editor_hint', 'chapter.source_shortcut', 'chapter.paste_hint'] as $key) {
            $this->assertStringContainsString(htmlspecialchars(\App\Lang::t($key), ENT_QUOTES), $html, $key);
        }
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/writer-comfort.js');
        $this->assertStringContainsString('ed-tool-source', $js, 'the header keeps the Source switch');
        $this->assertStringContainsString('writer-source', $js, 'the footer carries the Source switch');
    }

    public function test_shortcuts_are_spelled_per_platform_and_escape_leaves_focus_mode(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/writer-comfort.js');
        $this->assertStringContainsString("'#ed-keys kbd'", $js, 'the cheat sheet combos are rewritten for the platform');
        $this->assertStringContainsString('function comboKeys(shift)', $js, 'one helper spells Cmd or Ctrl, with Shift');
        $this->assertStringContainsString('panel: panelOrder.length > 0 && !(comfort && comfort.isFocus())', $js, 'hidden panels do not swallow Esc in focus mode');
    }

    public function test_epigraph_is_always_loaded_and_the_form_retains_native_save(): void
    {
        foreach ([1] as $_) {
            $html = $this->client()->get('/chapter/new/the-rabbit-hole')->body;
            [$form, $xpath] = $this->writerForm($html, 'chapter-editor-form');
            $box = $xpath->query('.//textarea[@name="content"]', $form)->item(0);
            $this->assertStringContainsString('epigraph-boot', $box->getAttribute('data-js-module'));
            $this->assertFalse($box->hasAttribute('hidden'));
            $this->assertTrue($box->hasAttribute('required'));
            $this->assertFalse($box->hasAttribute('disabled'));
            $this->assertSame('Enter chapter text before saving.', $form->getAttribute('data-content-required'));
            $labels = json_decode($box->getAttribute('data-writer-labels'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('Formatted', $labels['formatted']);
            // The header button, the popover's go button and Save as Draft are the only submits.
            $this->assertSame(1, $xpath->query('.//button[@data-ed-primary and @type="submit"]', $form)->length);
            $this->assertSame(1, $xpath->query('.//button[@data-ed-go and @type="submit"]', $form)->length);
            $this->assertSame(1, $xpath->query('.//button[@data-ed-draft and @type="submit" and @name="draft" and @value="1"]', $form)->length);
            $this->assertSame(3, $xpath->query('.//button[@type="submit" and not(@form)]', $form)->length);
        }
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("['epigraph-boot', ['paste', 'epigraph']]", $js);
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
            ['/chapter/new/the-rabbit-hole', 'chapter-editor-form', '/chapter/create/the-rabbit-hole', 'Publish Chapter'],
            ['/chapter/edit/the-rabbit-hole/1', 'chapter-editor-form', '/chapter/update/the-rabbit-hole/1', 'Update'],
            ['/story/new', 'chapter-editor-form', '/story/create', 'Publish Story'],
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
            $buttons = $xpath->query('.//button[@type="submit" and not(@form) and not(@name)]', $form);
            $this->assertSame($id === 'chapter-editor-form' ? 2 : 1, $buttons->length, $path);
            $this->assertSame($label, trim($buttons->item(0)->textContent));
            if ($id === 'story-editor-form') {
                $this->assertStringStartsWith('btn-primary', $buttons->item(0)->getAttribute('class'));
            }
        }
    }

    public function test_the_editor_page_keeps_every_named_field_and_drops_unowned_controls(): void
    {
        $html = $this->client()->get('/chapter/edit/the-rabbit-hole/1')->body;
        [$form, $xpath] = $this->writerForm($html, 'chapter-editor-form');
        foreach (['title', 'content', 'notes_before', 'notes_after', 'publish_at', 'access_password', 'base_revision', '_token'] as $name) {
            $this->assertGreaterThanOrEqual(1, $xpath->query('.//*[@name="' . $name . '"]', $form)->length, $name);
        }
        $this->assertSame(0, $xpath->query('.//*[@id="ed-date" or @id="ed-time"][@name]', $form)->length, 'helper fields are unnamed');
        $this->assertSame(1, $xpath->query('//form[@id="chapter-delete-form"]')->length);
        $this->assertSame(1, $xpath->query('.//*[contains(@class,"epigraph-mode") or @data-ed-write]', $form)->length >= 1 ? 1 : 0);
        $this->assertStringNotContainsString('Smart punctuation', $html);
        $this->assertStringNotContainsString('Restore sample chapter', $html);
        $this->assertStringNotContainsString('<style', $html, 'no inline styles');
    }

    public function test_the_editor_language_keys_exist(): void
    {
        $lang = require dirname(__DIR__, 1) . '/app/lang/en.php';
        foreach (['chapter.editor_bold', 'chapter.editor_italic', 'chapter.editor_link', 'chapter.editor_quote', 'chapter.editor_break', 'chapter.editor_hint'] as $key) {
            $this->assertArrayHasKey($key, $lang, $key);
        }
    }

    public function test_comfort_enhancement_has_labels_but_no_inert_script_free_buttons(): void
    {
        [$form, $xpath] = $this->writerForm($this->client()->get('/chapter/new/the-rabbit-hole')->body, 'chapter-editor-form');
        $this->assertStringContainsString('writer-comfort', $form->getAttribute('data-js-module'));
        $labels = json_decode($form->getAttribute('data-writer-labels'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Focus', $labels['focus']);
        $this->assertSame('Typewriter', $labels['typewriter']);
        $this->assertSame(0, $xpath->query('.//button[@aria-pressed and not(ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " js-only ")])]', $form)->length, 'every toggle is script-only');
        $this->assertSame(3, $xpath->query('.//button[@type="submit" and not(@form)]', $form)->length);
        $box = $xpath->query('.//textarea[@name="content"]', $form)->item(0);
        $this->assertTrue($box->hasAttribute('required'));
        $this->assertFalse($box->hasAttribute('hidden'));
        [$story] = $this->writerForm($this->client()->get('/story/new')->body, 'chapter-editor-form');
        $this->assertStringContainsString('writer-comfort', $story->getAttribute('data-js-module'));
        $this->assertSame('new-story', $story->getAttribute('data-ed-mode'));
        $this->assertStringContainsString("['writer-comfort']", file_get_contents(dirname(__DIR__) . '/public/assets/app.js'));
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
            $wrap = $kind === 'chapter' ? $state->item(0)->parentNode : $state->item(0);
            $this->assertSame('status', $wrap->getAttribute('role'));
            $this->assertSame('polite', $wrap->getAttribute('aria-live'));
            $this->assertSame('true', $wrap->getAttribute('aria-atomic'));
            if ($kind === 'chapter') {
                $this->assertNotSame('', trim($state->item(0)->textContent), 'the editor always names its state');
            } else {
                $this->assertSame('', $state->item(0)->textContent);
            }
            [$saved, $savedXpath] = $this->writerForm($this->client()->get($path, ['saved' => '1'])->body, $kind . '-editor-form');
            $this->assertSame('1', $saved->getAttribute('data-writer-saved'));
            $this->assertSame($kind === 'chapter' ? 'Saved' : 'All changes shown here are saved.', $savedXpath->query('.//*[@data-writer-state]', $saved)->item(0)->textContent);
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
            $this->assertSame($kind === 'chapter' ? 2 : 1, $xpath->query('.//button[@type="submit" and not(@name) and not(@form)]', $form)->length);
        }
    }

    public function test_recovery_contract_uses_saved_fields_stable_identity_and_no_secrets(): void
    {
        $db = new \Kip\Database('sqlite:' . $this->path);
        $db->query("UPDATE chapters SET content = ?, notes_before = ?, publish_at = ? WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole') AND position = 1", ['Archive <script> text', 'Before', '2026-12-01T12:30:00']);
        $client = $this->client();
        $saved = $client->get('/chapter/edit/the-rabbit-hole/1');
        [$form] = $this->writerForm($saved->body, 'chapter-editor-form');
        $this->assertMatchesRegularExpression('/^chapter:[1-9][0-9]*$/D', $form->getAttribute('data-writer-chapter'));
        $this->assertMatchesRegularExpression('/^[1-9][0-9]*$/D', $form->getAttribute('data-writer-owner'));
        $this->assertSame('/chapter/edit/the-rabbit-hole/1', $form->getAttribute('data-writer-check-url'));
        $this->assertSame('0', $form->getAttribute('data-writer-protected'));
        $server = json_decode($form->getAttribute('data-writer-server'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['title', 'content', 'notes_before', 'notes_after', 'publish_at'], array_keys($server));
        $this->assertSame('Archive <script> text', $server['content']);
        $this->assertSame('2026-12-01T12:30', $server['publish_at']);
        $this->assertSame(0, substr_count($saved->body, '<script> text'));
        $rejected = $client->postWithToken('/chapter/update/the-rabbit-hole/1', ['content' => 'Rejected draft', 'base_revision' => str_repeat('0', 64)]);
        $this->assertSame(409, $rejected->status);
        [$failed, $xpath] = $this->writerForm($rejected->body, 'chapter-editor-form');
        $this->assertSame($server, json_decode($failed->getAttribute('data-writer-server'), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($form->getAttribute('data-writer-base'), $failed->getAttribute('data-writer-base'));
        $this->assertSame(str_repeat('0', 64), $xpath->query('.//input[@name="base_revision"]', $failed)->item(0)->getAttribute('value'));
        $this->assertSame('Rejected draft', $xpath->query('.//textarea[@name="content"]', $failed)->item(0)->textContent);
        foreach (['stories', 'chapters'] as $table) {
            $db->query("UPDATE $table SET access_hash = ?", ['private-hash-canary']);
            [$protected] = $this->writerForm($client->get('/chapter/edit/the-rabbit-hole/1')->body, 'chapter-editor-form');
            $this->assertSame('1', $protected->getAttribute('data-writer-protected'));
            $this->assertStringNotContainsString('private-hash-canary', $protected->ownerDocument->saveHTML());
            $db->query("UPDATE $table SET access_hash = NULL");
        }
        $db->query("UPDATE chapters SET content = ? WHERE position = 1", ["Legacy \xff text"]);
        $legacy = $client->get('/chapter/edit/the-rabbit-hole/1');
        $this->assertSame(200, $legacy->status);
        [$legacyForm] = $this->writerForm($legacy->body, 'chapter-editor-form');
        $this->assertSame("Legacy \u{FFFD} text", json_decode($legacyForm->getAttribute('data-writer-server'), true, 512, JSON_THROW_ON_ERROR)['content']);
        [$new] = $this->writerForm($client->get('/chapter/new/the-rabbit-hole')->body, 'chapter-editor-form');
        $this->assertMatchesRegularExpression('/^new:[1-9][0-9]*$/D', $new->getAttribute('data-writer-chapter'));
        $this->assertSame('new', $new->getAttribute('data-writer-base'));
        $this->assertSame('/chapter/new/the-rabbit-hole', $new->getAttribute('data-writer-check-url'));
    }

    public function test_rejected_password_keeps_recovery_off_after_the_secret_is_discarded(): void
    {
        $response = $this->client()->postWithToken('/chapter/update/the-rabbit-hole/1', [
            'content' => '', 'notes_before' => 'Private draft note', 'access_password' => 'password-canary',
        ]);
        $this->assertSame(422, $response->status);
        [$form] = $this->writerForm($response->body, 'chapter-editor-form');
        $this->assertSame('1', $form->getAttribute('data-writer-protected'));
        $this->assertStringNotContainsString('password-canary', $response->body);
        $this->assertStringContainsString('Private draft note', $response->body);
    }

    public function test_login_loads_cleanup_and_loader_reuses_recovery_asset(): void
    {
        $this->assertStringContainsString('data-js-module="draft-cleanup"', $this->client()->get('/auth/login')->body);
        $loader = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("['draft-form', ['draft-store']]", $loader);
        $this->assertStringContainsString("load('draft-form')", $loader);
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

    /** The recovery offer reads as a titled banner (design parity): the detail strings exist, are sent to the script, and the result stays visible. */
    public function test_recovery_offer_has_a_title_detail_lines_and_a_visible_result(): void
    {
        $root = dirname(__DIR__, 1);
        $lang = (string) file_get_contents($root . '/app/lang/en.php');
        $view = (string) file_get_contents($root . '/app/src/ChapterEditorView.php');
        $js = (string) file_get_contents($root . '/public/assets/draft-form.js');
        $css = (string) file_get_contents($root . '/public/assets/reader.css');
        foreach (['recoveryOfferDetail', 'recoveryOfferDetailOne', 'recoveryOfferDetailSame'] as $key) {
            $this->assertStringContainsString("'writer.$key'", $lang, "$key has an English string");
            $this->assertStringContainsString("'$key'", $view, "$key reaches the script");
            $this->assertStringContainsString($key, $js, "$key is used by the offer");
        }
        $this->assertStringNotContainsString('—', substr($lang, (int) strpos($lang, "'writer.recoveryOfferDetail'"), 400), 'no em dashes in recovery copy');
        $this->assertStringContainsString('[data-notice="1"]', $css, 'the restore and discard result stays on screen');
        $this->assertMatchesRegularExpression('/\.ed-toast \{[^}]*inset-block-end: 60px;[^}]*padding: 12px 18px/', $css, 'the toast matches the design');
    }
}
